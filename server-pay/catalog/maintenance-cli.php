<?php
/**
 * Ежедневное обслуживание каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/maintenance-cli.php
 *
 * Делает копию базы и убирает фото, на которые больше никто не ссылается.
 * Фото уходит в корзину, только когда ссылок на него нет уже сутки и сайт,
 * выложенный на сервере, собран без этих ссылок. Что выложено, берётся из
 * pion-deploy/state.json (current.catalogVersion и current.catalogChangedAt;
 * папка выкладки — PION_DEPLOY_HOME или <корень сайта>/../../pion-deploy,
 * как в админке). Состояния нет или оно нечитаемо — фото не двигаются совсем,
 * а копия базы и стирание старого из корзины идут как обычно.
 *
 * Итог каждого запуска, удачного и нет, пишется в папку каталога:
 *   maintenance.json — {"ok": bool, "at": "<время конца запуска>", "message": "…"},
 *                      целиком и подменой файла (его читает сторож выкладки:
 *                      о неудаче он сообщает один раз, по времени "at");
 *   maintenance.log  — строка на запуск: "<время> ok" или "<время> ошибка: <причина>".
 * Причина — одна короткая строка по-русски: сторож показывает первую строку.
 * Код выхода 0 — получилось, 1 — нет (или итог не удалось записать).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/maintenance.php';

/** Текст в одну строку не длиннее $max знаков (обрезанный кончается «…»), как его показывает сторож. */
function maintenance_one_line(string $text, int $max = 300): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', mb_scrub($text)));
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

/**
 * Причина сбоя для итога. Свои исключения (RuntimeException) говорят по-русски
 * и сами называют, что не вышло; чужие (PDO, SQLite и прочее) — по-английски и
 * без контекста, им ставится впереди, на каком шаге это случилось.
 */
function maintenance_reason(Throwable $e, string $step): string
{
    $own = $e instanceof RuntimeException && !($e instanceof PDOException);
    return maintenance_one_line($own ? $e->getMessage() : $step . ': ' . $e->getMessage());
}

/**
 * Пишет итог: maintenance.json — во временный файл и подменой, чтобы сторож
 * не прочёл половину; maintenance.log — строкой в конец. Время ставится
 * здесь, в конце запуска. Возвращает текст ошибки или null.
 */
function maintenance_record(string $home, bool $ok, string $message): ?string
{
    $at = catalog_iso(new DateTimeImmutable());
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

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — обслуживать нечего.', PHP_EOL;
    exit(0);
}
// pay/catalog → pay → корень сайта
$webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
// Папка выкладки — та же, что у админки (admin/lib/app.php).
$deployHome = rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/');
$now = new DateTimeImmutable();
$ok = true;
$message = '';
$step = 'Копия базы';
try {
    $backup = catalog_backup($file, catalog_home() . '/backups', $now);
    echo 'Копия: ', $backup, PHP_EOL;

    $step = 'Открытие базы';
    $state = json_decode((string)@file_get_contents($deployHome . '/state.json'), true);
    $current = is_array($state) && is_array($state['current'] ?? null) ? $state['current'] : null;
    $db = catalog_db_open($file);
    $published = catalog_deploy_covers($current, (string)(catalog_meta($db)['version'] ?? ''));

    $step = 'Уборка фото';
    $r = catalog_photos_sweep($db, $webroot, catalog_home() . '/photo-candidates.json', $now, $published);
    $message = sprintf(
        'Фото: ждут уборки %d, убрано в корзину %d, стёрто из корзины %d, возвращено из корзины %d.',
        $r['candidates'], $r['moved'], $r['purged'], $r['returned'],
    );
    if (!is_string($current['catalogVersion'] ?? null) || $current['catalogVersion'] === '') {
        $message .= ' Что выложено на сайте, неизвестно — фото в корзину не уходят.';
    }
    echo $message, PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Обслуживание не удалось: ' . $e->getMessage() . PHP_EOL);
    $ok = false;
    $message = maintenance_reason($e, $step);
}

$problem = maintenance_record(catalog_home(), $ok, maintenance_one_line($message));
if ($problem !== null) {
    fwrite(STDERR, $problem . PHP_EOL);
}
exit($ok && $problem === null ? 0 : 1);
