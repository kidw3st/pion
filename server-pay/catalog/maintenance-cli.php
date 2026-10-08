<?php
/**
 * Ежедневное обслуживание каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/maintenance-cli.php
 *
 * Делает копию базы и убирает фото, на которые больше никто не ссылается.
 * Фото уходит в корзину, только когда ссылок на него нет уже сутки и на сайте
 * выложен каталог той же версии, что в базе. Что выложено, берётся из
 * pion-deploy/state.json (current.catalogVersion; папка выкладки —
 * PION_DEPLOY_HOME или <корень сайта>/../../pion-deploy, как в админке).
 * Версия, а не время правки: фото, на которое снова сослались и снова убрали
 * между двумя уборками, хранит старое время «без ссылок», и по времени сошла бы
 * за выложенное сборка, что его ещё показывает. Цена — фото ждёт лишнюю ночь,
 * если вечером были правки, а выкладка до ночи не дошла. Состояния нет или оно
 * нечитаемо — фото не двигаются совсем, а копия базы, возврат из корзины того,
 * на что снова сослались, и стирание старого из корзины идут как обычно.
 *
 * Итог каждого запуска, удачного и нет, пишется в папку каталога:
 *   maintenance.json — {"ok": bool, "at": "<время конца запуска>", "message": "…"},
 *                      целиком и подменой файла (его читает сторож выкладки:
 *                      о неудаче он сообщает один раз, по времени "at");
 *   maintenance.log  — строка на запуск: "<время> ok" или "<время> ошибка: <причина>".
 * Причина — одна короткая строка по-русски: сторож показывает первую строку.
 * Запуск, оборванный фатальной ошибкой (например, не хватило памяти), успевает
 * записать «Обслуживание оборвалось» — иначе сторож остался бы с вчерашним «ok».
 * Код выхода 0 — получилось, 1 — нет (или итог не удалось записать).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/maintenance.php';

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — обслуживать нечего.', PHP_EOL;
    exit(0);
}

// Итог ещё не записан, а запуск уже кончается — значит, он оборван фатальной ошибкой, мимо catch.
$recorded = false;
register_shutdown_function(static function () use (&$recorded): void {
    if ($recorded) {
        return;
    }
    $recorded = true;
    // Не любая «последняя ошибка» годится: предупреждение, подавленное «@», тоже остаётся в error_get_last().
    $error = error_get_last();
    $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
    $problem = maintenance_record(catalog_home(), false, 'Обслуживание оборвалось' . ($fatal ? ': ' . $error['message'] : ''));
    if ($problem !== null) {
        fwrite(STDERR, $problem . PHP_EOL);
    }
});

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

    $step = 'Уборка фото';
    $r = catalog_photos_sweep($db, $webroot, catalog_home() . '/photo-candidates.json', $now, catalog_deploy_covers($current));
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

$recorded = true;
$problem = maintenance_record(catalog_home(), $ok, $message);
if ($problem !== null) {
    fwrite(STDERR, $problem . PHP_EOL);
}
exit($ok && $problem === null ? 0 : 1);
