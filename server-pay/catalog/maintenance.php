<?php
/**
 * Ежедневное обслуживание каталога: копия базы и уборка фото.
 *
 * Фото, на которые больше не ссылается ни один букет (кроме удалённых) и ни
 * один раздел, — заменили, убрали из карточки, букет удалён, — переезжают в
 * корзину images/catalog/_deleted/. Но не сразу: сначала фото становится
 * кандидатом, и уходит оно, только когда ссылок на него нет уже сутки и на
 * сайте выложен каталог той же версии, что в базе: пока выкладка не дошла,
 * старая страница на сайте ещё показывает фото, и задержка сборки не должна
 * оставить на ней пустую картинку. Версия, а не время правки, потому что фото,
 * на которое снова сослались и снова убрали между двумя уборками, хранит
 * старое время «без ссылок», и по времени сошла бы за выложенное сборка, что
 * его ещё показывает. Цена — фото ждёт лишнюю ночь, если вечером были правки,
 * а выкладка до ночи не дошла. Дошла ли выкладка, решает вызывающий (см.
 * catalog_deploy_covers). Корзина стирает фото через 90 дней. Файлы, хозяина
 * которых база не знает (uid букета ни в базе, ни в журнале, раздела из
 * имени нет), не трогаются никогда.
 *
 * Корзина — не последнее слово: на фото в корзине могут ссылаться снова
 * (загрузили, прошли две уборки, и только потом сохранили карточку; или
 * восстановление не успело вернуть файл). Такое фото уборка возвращает на
 * место и никогда не стирает из корзины.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/photo-files.php';

/** Сколько фото должно пробыть без ссылок, прежде чем уйти в корзину. */
const CATALOG_PHOTO_GRACE = 86400;
const CATALOG_TRASH_DAYS = 90;

/**
 * Предохранитель: за один раз в корзину уходит не больше большего из
 * CATALOG_PHOTO_MOVE_FLOOR фото и CATALOG_PHOTO_MOVE_SHARE от всех фото с
 * известным хозяином. Больше — значит, дело не в забытых снимках, а в
 * ссылках, которые перестали сходиться с файлами (например, путь записан без
 * начального «/»): тогда «без ссылок» выглядят все фото сразу.
 */
const CATALOG_PHOTO_MOVE_FLOOR = 50;
const CATALOG_PHOTO_MOVE_SHARE = 0.1;

/** Пути фото из JSON-списка в базе; мусор вместо путей отбрасывается. */
function catalog_photo_paths(mixed $json): array
{
    $list = is_string($json) ? json_decode($json, true) : null;
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

function catalog_photos_referenced(PDO $db): array
{
    $paths = [];
    foreach ($db->query("SELECT images FROM products WHERE status <> 'deleted'") as $row) {
        foreach (catalog_photo_paths($row['images']) as $path) {
            $paths[$path] = true;
        }
    }
    foreach ($db->query('SELECT tile_image, covers FROM sections') as $row) {
        if ($row['tile_image'] !== '') {
            $paths[$row['tile_image']] = true;
        }
        foreach (catalog_photo_paths($row['covers']) as $path) {
            $paths[$path] = true;
        }
    }
    return $paths;
}

/**
 * Знает ли база хозяина файла: раздел — для фото из _sections, букет по uid из
 * имени — для остальных. Букет известен, если он есть в products или его uid
 * есть в журнале: черновик, удалённый совсем, строку в products теряет, но
 * журнал о нём помнит — иначе его фото остались бы в папке навсегда. Фото
 * каталога до переноса в базу (uid в журнале не появлялся) по-прежнему чужие.
 *
 * Имя разбирается целиком и только в алфавите адресов сайта (a-z, 0-9, «-»):
 * всё, что на него не похоже, — не наше, и уборка этого файла не касается.
 * uid — последняя группа из 12 цифр: так «roza-111111111111-<uid>.webp»
 * принадлежит букету <uid>, а не тому, чья цифровая группа стоит в названии.
 */
function catalog_photo_owner_known(PDO $db, string $dir, string $name): bool
{
    if ($dir === '_sections') {
        if (!preg_match('/^([a-z0-9-]+)-(?:tile|cover)-[0-9a-f]{8}\.webp\z/', $name, $m)) {
            return false;
        }
        $q = $db->prepare('SELECT 1 FROM sections WHERE slug = ?');
        $q->execute([$m[1]]);
        return $q->fetchColumn() !== false;
    }
    if (!preg_match('/^[a-z0-9-]*-(\d{12})(?:-\d+|-[0-9a-f]{8})?\.webp\z/', $name, $m)) {
        return false;
    }
    $q = $db->prepare('SELECT 1 FROM products WHERE uid = ?');
    $q->execute([$m[1]]);
    if ($q->fetchColumn() !== false) {
        return true;
    }
    $q = $db->prepare("SELECT 1 FROM audit WHERE object_type = 'product' AND object_id = ? LIMIT 1");
    $q->execute([$m[1]]);
    return $q->fetchColumn() !== false;
}

/**
 * Выложен ли на сайте весь каталог из базы. state.json пишет deploy.php:
 * current.catalogVersion — версия каталога, по которому собран выложенный
 * сайт. Выложено только тогда, когда она та же, что в базе: тогда на сайте уже
 * нет ни одной ссылки, которой нет в базе.
 *
 * По времени правки (current.catalogChangedAt) не судим. Фото, на которое
 * снова сослались и снова убрали между двумя уборками, сохраняет старое время
 * «без ссылок», и сборка из середины (ссылка уже есть, второй правки ещё нет)
 * считалась бы выложенной без фото, хотя показывает его.
 *
 * Нет состояния, версия в нём не строка или пуста (выкладка ещё не знает
 * каталог), версии нет в базе — не выложено: лучше подержать фото лишнюю ночь,
 * чем показать на сайте пустую картинку.
 *
 * @param array|null $current state.json → current; null — состояния нет
 * @return Closure(string): bool принимает версию каталога в базе (catalog_meta, ключ version)
 */
function catalog_deploy_covers(?array $current): Closure
{
    $version = is_array($current) ? ($current['catalogVersion'] ?? null) : null;
    return static fn (string $dbVersion): bool => is_string($version) && $version !== '' && $version === $dbVersion;
}

/**
 * Одна уборка. Порядок такой: сначала вернуть из корзины то, на что снова
 * ссылаются; потом найти фото без ссылок и, если их к переносу не слишком
 * много, унести те, что пробыли без ссылок сутки, если на сайте выложен тот же
 * каталог, что в базе; потом стереть из корзины старое — кроме того, на что
 * ссылаются.
 *
 * Ссылки на фото и версия каталога в базе читаются в одной транзакции чтения —
 * из одного снимка базы: версия описывает ровно те ссылки, что прочитаны, и
 * правка, сохранённая посреди чтения, не может попасть в одно без другого.
 * $published($dbVersion) спрашивается один раз за уборку: выложен ли на сайте
 * каталог именно этой версии. Без «да» ни одно фото не уходит, как бы давно
 * оно ни лежало, но остаётся кандидатом со своим прежним временем и уйдёт в
 * одну из следующих уборок, когда выкладка дойдёт (возвращение из корзины и
 * стирание старого от выкладки не зависят). null — выкладку не учитывать,
 * ждать только сутки. Предохранитель считает только то, что действительно
 * уходит.
 *
 * Предохранитель сработал — RuntimeException: в корзину не уходит ничего и
 * из корзины ничего не стирается (при сбившихся ссылках защите «на это
 * ссылаются» верить нельзя). Список кандидатов при этом сохраняется как есть:
 * время, с которого фото без ссылок, не должно теряться, иначе после
 * исправления все фото снова ждали бы сутки, а выбывшие из списка вернулись
 * бы в него со старым временем.
 *
 * @param (Closure(string): bool)|null $published версия каталога в базе → выложен ли он на сайте
 * @return array{candidates: int, moved: int, purged: int, returned: int}
 */
function catalog_photos_sweep(PDO $db, string $webroot, string $candidatesFile, DateTimeImmutable $now, ?Closure $published = null): array
{
    $time = $now->getTimestamp();
    $db->beginTransaction();
    try {
        $referenced = catalog_photos_referenced($db);
        $dbVersion = (string)(catalog_meta($db)['version'] ?? '');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    $deployed = $published === null || $published($dbVersion);

    $returned = 0;
    foreach (array_keys($referenced) as $rel) {
        if (!is_file($webroot . $rel) && catalog_photo_untrash($webroot, (string)$rel)) {
            $returned++;
        }
    }

    $previous = json_decode((string)@file_get_contents($candidatesFile), true);
    $previous = is_array($previous) ? $previous : [];
    $candidates = [];
    $due = [];
    $known = 0;
    foreach (glob($webroot . '/images/catalog/*', GLOB_ONLYDIR) ?: [] as $dirPath) {
        $dir = basename($dirPath);
        // Папка-ссылка вела бы за пределы images/catalog: под своим именем из неё ничего не убираем.
        if ($dir === '_deleted' || is_link($dirPath)) {
            continue;
        }
        foreach (glob($dirPath . '/*.webp') ?: [] as $file) {
            $name = basename($file);
            $rel = '/images/catalog/' . $dir . '/' . $name;
            if (isset($referenced[$rel])) {
                $known++;
                continue;
            }
            // Что в корзину не унести (путь не по образцу), не кандидат: оно не должно ни копиться, ни тревожить предохранитель.
            if (catalog_photo_trash_path($rel) === null || !catalog_photo_owner_known($db, $dir, $name)) {
                continue;
            }
            $known++;
            $since = is_int($previous[$rel] ?? null) ? $previous[$rel] : $time;
            $candidates[$rel] = $since;
            // Кандидат, которому не хватает только выкладки, остаётся в списке со своим $since.
            if ($time - $since >= CATALOG_PHOTO_GRACE && $deployed) {
                $due[] = $rel;
            }
        }
    }

    $limit = max(CATALOG_PHOTO_MOVE_FLOOR, $known * CATALOG_PHOTO_MOVE_SHARE);
    if (count($due) > $limit) {
        catalog_photo_candidates_save($candidatesFile, $candidates);
        throw new RuntimeException('Уборка фото остановлена: к переносу в корзину ' . count($due)
            . ' фото — слишком много, нужна проверка разработчика.');
    }

    $moved = 0;
    foreach ($due as $rel) {
        if (catalog_photo_trash($webroot, $rel, $now)) {
            $moved++;
            unset($candidates[$rel]);
        }
    }

    $trash = $webroot . CATALOG_TRASH_DIR;
    $old = [];
    if (is_dir($trash)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getMTime() >= $time - CATALOG_TRASH_DAYS * 86400) {
                continue;
            }
            // Путь в корзине → путь на сайте; пока на фото ссылаются, оно не стирается, как бы давно ни лежало.
            $original = '/images/catalog/' . substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $trash)) + 1);
            if (!isset($referenced[$original])) {
                $old[] = $f->getPathname();
            }
        }
    }
    $purged = 0;
    foreach ($old as $path) {
        if (unlink($path)) {
            $purged++;
        }
    }

    catalog_photo_candidates_save($candidatesFile, $candidates);
    return ['candidates' => count($candidates), 'moved' => $moved, 'purged' => $purged, 'returned' => $returned];
}

/**
 * Список кандидатов пишется во временный файл и получает своё имя только
 * целым: запуск, прерванный на полуслове, не оставит обрезанный JSON — иначе
 * все кандидаты стали бы «новыми» и уборка отложилась бы ещё на сутки.
 */
function catalog_photo_candidates_save(string $file, array $candidates): void
{
    $json = json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Не собрать список фото на уборку: ' . json_last_error_msg());
    }
    $part = $file . '.part';
    if (@file_put_contents($part, $json) !== strlen($json) || !@rename($part, $file)) {
        if (is_file($part)) {
            unlink($part);
        }
        throw new RuntimeException("Не записать список фото на уборку: $file");
    }
}

// --- Итог запуска для сторожа выкладки -------------------------------------

/** Текст в одну строку не длиннее $max знаков (обрезанный кончается «…»), как его показывает сторож. */
function maintenance_one_line(string $text, int $max = 300): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', mb_scrub($text)));
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

/**
 * Причина сбоя для итога — одна строка по-русски. Сообщение, где уже есть
 * кириллица, наше, и говорит само, что не вышло («Уборка фото остановлена: …»).
 * Остальное — чужие по-английски (PDO, SQLite, итераторы папок и прочее, даже
 * если класс исключения — RuntimeException): им ставится впереди, на каком
 * шаге это случилось.
 */
function maintenance_reason(Throwable $e, string $step): string
{
    $text = $e->getMessage();
    return maintenance_one_line(preg_match('/\p{Cyrillic}/u', mb_scrub($text)) === 1 ? $text : $step . ': ' . $text);
}

/**
 * Пишет итог запуска: maintenance.json — во временный файл и подменой, чтобы
 * сторож не прочёл половину; maintenance.log — строкой в конец. Время ставится
 * здесь: итог пишется в конце запуска, и «at» — время конца, по нему сторож
 * решает, что сбой новый. Сообщение приводится к одной строке до 300 знаков.
 * Возвращает текст ошибки записи или null.
 */
function maintenance_record(string $home, bool $ok, string $message): ?string
{
    $at = catalog_iso(new DateTimeImmutable());
    $message = maintenance_one_line($message);
    $errors = [];
    $json = json_encode(
        ['ok' => $ok, 'at' => $at, 'message' => $message],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    $json = is_string($json) ? $json . PHP_EOL : '';
    $file = $home . '/maintenance.json';
    $part = $file . '.part';
    if ($json === '' || @file_put_contents($part, $json) !== strlen($json) || !@rename($part, $file)) {
        if (is_file($part)) {
            @unlink($part);
        }
        $errors[] = "не записать $file";
    }
    $line = $at . ' ' . ($ok ? 'ok' : 'ошибка: ' . $message) . PHP_EOL;
    if (@file_put_contents($home . '/maintenance.log', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        $errors[] = "не дописать $home/maintenance.log";
    }
    return $errors === [] ? null : 'Итог обслуживания не записан: ' . implode('; ', $errors);
}
