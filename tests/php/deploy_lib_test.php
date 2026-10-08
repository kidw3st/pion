<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';
// Для сторожа каталога: временная база и catalog_touch.
require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/export.php';

// --- Защищённые пути -------------------------------------------------------
foreach ([
    'pay/config.php' => true,
    'pay' => true,
    'blog/index.php' => true,
    'blog/wp-content/uploads/a.jpg' => true,
    'blog/wp-content/themes/pion/style.css' => false,
    'images/catalog/bukety/a.webp' => true,
    'images/showcase/b.webp' => true,
    'api/showcase.json' => true,
    '.well-known/acme-challenge/x' => true,
    '.well-known/agent-skills/index.json' => false,
    '.well-known/agent-skills/pion-catalog/SKILL.md' => false,
    'index.html' => false,
    'images/site/logo.webp' => false,
    'api/catalog/bukety.json' => false,
    'payment/index.html' => false,
] as $path => $want) {
    t_equal(deploy_is_protected($path), $want, "защищён ли $path");
}

// --- Ветки из ответа git-сервера ------------------------------------------
// Формат pkt-line: 4 hex-цифры длины строки (вместе с ними), затем строка.
$pkt = static fn(string $line): string => sprintf('%04x', strlen($line) + 4) . $line;
$body = $pkt("# service=git-upload-pack\n") . '0000'
    . $pkt(str_repeat('a', 40) . " HEAD\0multi_ack symref=HEAD:refs/heads/master\n")
    . $pkt(str_repeat('a', 40) . " refs/heads/master\n")
    . $pkt(str_repeat('b', 40) . " refs/heads/server-build\n")
    . $pkt(str_repeat('c', 40) . " refs/heads/feature/x\n")
    . $pkt(str_repeat('d', 40) . " refs/tags/v1.0.0\n")
    . '0000';
t_equal(deploy_parse_refs($body), [
    'master' => str_repeat('a', 40),
    'server-build' => str_repeat('b', 40),
    'feature/x' => str_repeat('c', 40),
], 'ветки из ответа info/refs');
t_equal(deploy_parse_refs('<html>rate limit</html>'), [], 'не тот ответ — веток нет');

// --- Список файлов распакованной сборки -----------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['b/x.html' => '1', 'a.txt' => '2', '.htaccess' => '3', 'b/c/d.css' => '4']);
mkdir($dir . '/empty');
t_equal(
    deploy_list_files($dir),
    ['.htaccess', 'a.txt', 'b/c/d.css', 'b/x.html'],
    'только файлы, через «/», по алфавиту',
);

// --- Что удалить после выкладки -------------------------------------------
t_equal(
    deploy_stale_files(
        ['index.html', 'old/index.html', 'images/catalog/a.webp', 'pay/init.php', 'blog/wp-content/themes/pion/old.php'],
        ['index.html', 'new/index.html'],
    ),
    ['old/index.html', 'blog/wp-content/themes/pion/old.php'],
    'удаляется только своё и пропавшее',
);
t_equal(deploy_stale_files(['a', 'b'], ['a', 'b']), [], 'одинаковые сборки — удалять нечего');

// --- Состав архива ----------------------------------------------------------
t_equal(
    deploy_forbidden_files(['index.html', 'images/catalog/x.webp', 'pay/init.php']),
    ['images/catalog/x.webp', 'pay/init.php'],
    'чужие пути в архиве сайта',
);
t_equal(deploy_missing_required(['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt']), [], 'всё обязательное на месте');
t_equal(deploy_missing_required(['index.html', '.htaccess']), ['404.html', 'sitemap.xml', 'robots.txt'], 'чего не хватает');

// --- Сверка архивов с build-info.json -------------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['site.tgz' => 'site-bytes', 'pay.tgz' => 'pay-bytes']);
$info = [
    'site' => ['sha256' => hash('sha256', 'site-bytes')],
    'pay' => ['sha256' => hash('sha256', 'pay-bytes')],
];
$paths = ['site' => "$dir/site.tgz", 'pay' => "$dir/pay.tgz"];
t_equal(deploy_check_archives($info, $paths), [], 'sha256 сошлись');
$info['pay']['sha256'] = str_repeat('0', 64);
t_equal(deploy_check_archives($info, $paths), ['pay: sha256 не совпадает'], 'подменённый архив');
unset($info['site']);
t_equal(
    deploy_check_archives($info, $paths),
    ['в build-info.json нет sha256 для site', 'pay: sha256 не совпадает'],
    'нет записи в build-info.json',
);

// --- Сторож каталога -------------------------------------------------------
$base = deploy_state_after_success(deploy_empty_state(),
    ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => str_repeat('0', 64), 'catalogVersion' => 'v1'], 1000);
$now = 1_000_000;
$fresh = ['version' => 'v1', 'changedAt' => $now - 60, 'backupAt' => $now - 3600, 'maintenance' => ['ok' => true, 'at' => $now - 3600, 'message' => '']];
t_equal(deploy_pending_alerts($base, $now, $fresh), [], 'всё выложено, копия свежая, обслуживание в порядке — молчим');
t_equal(deploy_pending_alerts($base, $now, ['version' => 'v2', 'changedAt' => $now - 60] + $fresh), [], 'правка 1 минуту назад — ещё рано');
t_equal(deploy_pending_alerts($base, $now, ['version' => 'v2', 'changedAt' => $now - 5399] + $fresh), [], 'правка не выложена 89 минут 59 секунд — ещё рано');
t_equal(deploy_pending_alerts($base, $now, ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh), ['catalog'], 'правка не выложена 90 минут — пишем');
t_equal(deploy_pending_alerts($base, $now, ['backupAt' => $now - 2 * 86400] + $fresh), [], 'копии ровно двое суток — ещё не «больше»');
t_equal(deploy_pending_alerts($base, $now, ['backupAt' => $now - 2 * 86400 - 1] + $fresh), ['backup'], 'копии нет больше двух суток');
t_equal(deploy_pending_alerts($base, $now, ['backupAt' => null] + $fresh), ['backup'], 'копий нет совсем');
t_equal(deploy_pending_alerts($base, $now, ['maintenance' => ['ok' => false, 'at' => $now, 'message' => 'диск полон']] + $fresh), ['maintenance'], 'обслуживание упало');
t_equal(deploy_pending_alerts($base, $now, ['maintenance' => null] + $fresh), [], 'итога обслуживания ещё нет — не сбой');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'catalog', $now - 60), $now, ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh), [], 'не чаще раза в 3 часа');
t_equal(deploy_pending_alerts($base, $now, null), [], 'базы нет — о каталоге молчим');
t_equal(deploy_pending_alerts($base, $now), [], 'без третьего параметра — как раньше');

// Сторож каталога: что считается «правкой, которой нет на сайте».
$late = ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh;
$unknownDeployed = deploy_state_after_success(deploy_empty_state(), ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => str_repeat('0', 64)], 1000);
t_equal(deploy_pending_alerts($unknownDeployed, $now, $late), [], 'выложенная сборка без версии каталога — сравнивать не с чем (в админке это «Сайт собирается из прежнего каталога»)');
t_equal(deploy_pending_alerts(deploy_empty_state(), $now, $late), [], 'ещё ничего не выкладывалось — о каталоге молчим');
t_equal(deploy_pending_alerts($base, $now, ['version' => '', 'changedAt' => 0] + $fresh), [], 'в базе ещё не было правок (версии нет) — выкладывать нечего');

// Сторож каталога: сбой выкладки.
$ripeFailure = deploy_state_after_failure($base, 'fatal', 'нет robots.txt', str_repeat('f', 40), $now - 60);
t_equal(deploy_pending_alerts($ripeFailure, $now, $late), ['fatal'], 'выкладка стоит — пишем о ней одной, а не ещё и о каталоге');
$youngFailure = deploy_state_after_failure($base, 'transient', 'таймаут', null, $now - 60);
t_equal(deploy_pending_alerts($youngFailure, $now, $late), [], 'сеть сбоит меньше часа — о каталоге пока молчим');
$stale = ['backupAt' => null, 'maintenance' => ['ok' => false, 'at' => $now, 'message' => 'диск полон']] + $late;
t_equal(deploy_pending_alerts($ripeFailure, $now, $stale), ['fatal', 'backup', 'maintenance'], 'копии и обслуживание от сбоя выкладки не зависят');
t_equal(deploy_pending_alerts($youngFailure, $now, $stale), ['backup', 'maintenance'], 'сбой выкладки ещё молчит — копии и обслуживание всё равно пишут');

// Сторож каталога: несколько видов сразу, у каждого свой срок.
t_equal(deploy_pending_alerts($base, $now, $stale), ['catalog', 'backup', 'maintenance'], 'все три — по порядку');
$backupSaid = deploy_mark_alerted($base, 'backup', $now - 600);
t_equal(deploy_pending_alerts($backupSaid, $now, $stale), ['catalog', 'maintenance'], 'о копиях писали недавно, об остальном — нет');
$noBackups = ['backupAt' => null] + $fresh;
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'backup', $now - DEPLOY_ALERT_COOLDOWN), $now, $noBackups), [], 'о копиях через 3 часа не напоминаем: срок сутки');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'backup', $now - DEPLOY_BACKUP_ALERT_COOLDOWN + 1), $now, $noBackups), [], 'о копиях без секунды сутки — рано');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'backup', $now - DEPLOY_BACKUP_ALERT_COOLDOWN), $now, $noBackups), ['backup'], 'о копиях через сутки напоминаем');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'catalog', $now - DEPLOY_ALERT_COOLDOWN + 1), $now, $late), [], 'об отставании каталога без секунды 3 часа — рано');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'catalog', $now - DEPLOY_ALERT_COOLDOWN), $now, $late), ['catalog'], 'об отставании каталога через 3 часа напоминаем');
$lagging = deploy_note_master(deploy_note_head($base, str_repeat('1', 40), 1000), str_repeat('b', 40), 1000);
t_equal(deploy_pending_alerts($lagging, $now, $late), ['lag', 'catalog'], 'отставание коммитов и каталога — отдельные сообщения');

// Сторож каталога: обслуживание — одно сообщение на каждый неудавшийся запуск.
$failedAt = static fn(int $at): array => ['maintenance' => ['ok' => false, 'at' => $at, 'message' => 'диск полон']] + $fresh;
t_equal(deploy_pending_alerts($base, $now, $failedAt($now - 600)), ['maintenance'], 'обслуживание: сообщения ещё не было — пишем');
$reported = deploy_mark_alerted($base, 'maintenance', $now - 300);
t_equal(deploy_pending_alerts($reported, $now, $failedAt($now - 600)), [], 'обслуживание: о том же запуске уже писали — молчим, хотя срока в 3 часа ещё нет');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'maintenance', $now - 7 * 3600), $now, $failedAt($now - 8 * 3600)), [], 'обслуживание: о том же запуске молчим и через много часов');
t_equal(deploy_pending_alerts($reported, $now, $failedAt($now - 300)), [], 'обслуживание: запуск и сообщение в одну секунду — это тот же запуск');
t_equal(deploy_pending_alerts($reported, $now, $failedAt($now - 299)), ['maintenance'], 'обслуживание: запуск позже сообщения — новый сбой, пишем сразу');
t_equal(deploy_pending_alerts($reported, $now, $failedAt(0)), [], 'обслуживание: время запуска неизвестно, писали недавно — срок 3 часа');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'maintenance', $now - DEPLOY_ALERT_COOLDOWN), $now, $failedAt(0)), ['maintenance'], 'обслуживание: время запуска неизвестно, прошло 3 часа — напоминаем');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'maintenance', $now - 1), $now, $failedAt($now + 5000)), [], 'обслуживание: время запуска в будущем (часы разошлись), писали недавно — срок 3 часа');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'maintenance', $now - DEPLOY_ALERT_COOLDOWN), $now, $failedAt($now + 5000)), ['maintenance'], 'обслуживание: время запуска в будущем, прошло 3 часа — напоминаем');

// Сторож каталога: проверки раз в 15 минут, как cron; сколько сообщений за сутки.
$simulate = static function (callable $watchAt, int $hours) use ($base): array {
    $from = 2_000_000;
    $state = $base;
    $sent = [];
    for ($t = $from; $t < $from + $hours * 3600; $t += 900) {
        foreach (deploy_pending_alerts($state, $t, $watchAt($t, $from)) as $kind) {
            $sent[] = [$t - $from, $kind];
            $state = deploy_mark_alerted($state, $kind, $t);
        }
    }
    return $sent;
};
$watchOf = static fn(int $t, ?array $maintenance, ?int $backupAt, string $version = 'v1'): array
    => ['version' => $version, 'changedAt' => $t - 60 - ($version === 'v1' ? 0 : 5400), 'backupAt' => $backupAt, 'maintenance' => $maintenance];
$failed = static fn(int $at): array => ['ok' => false, 'at' => $at, 'message' => 'диск полон'];
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, $failed($from - 600), $t - 3600), 24),
    [[0, 'maintenance']],
    'один неудавшийся запуск за сутки проверок раз в 15 минут — одно сообщение',
);
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, $failed($t < $from + 86400 ? $from - 600 : $from + 86400 - 600), $t - 3600), 48),
    [[0, 'maintenance'], [86400, 'maintenance']],
    'следующей ночью новый неудавшийся запуск — одно новое сообщение, сразу после него',
);
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, ['ok' => true, 'at' => $t - 600, 'message' => ''], $t - 3600), 24),
    [],
    'обслуживание в порядке — сообщений нет',
);
$every3h = array_map(static fn(int $i): array => [$i * 10800, 'maintenance'], range(0, 7));
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, $failed(0), $t - 3600), 24),
    $every3h,
    'время запуска неизвестно — по сроку в 3 часа, 8 сообщений в сутки',
);
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, $failed($t + 1000), $t - 3600), 24),
    $every3h,
    'время запуска в будущем — тоже по сроку в 3 часа',
);
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, null, null), 24),
    [[0, 'backup']],
    'копий нет сутки — одно сообщение',
);
t_equal(
    $simulate(static fn(int $t, int $from) => $watchOf($t, null, null), 48),
    [[0, 'backup'], [86400, 'backup']],
    'копий нет двое суток — по одному сообщению в сутки',
);
$everythingBad = $simulate(static fn(int $t, int $from) => $watchOf($t, $failed($from - 600), null, 'v2'), 24);
t_equal(
    array_count_values(array_column($everythingBad, 1)),
    ['catalog' => 8, 'backup' => 1, 'maintenance' => 1],
    'сутки, когда плохо всё: отставание каталога каждые 3 часа, копии и обслуживание по разу',
);

// Сторож каталога: тексты сообщений.
$said = ['maintenance' => ['ok' => false, 'at' => $now, 'message' => 'диск полон']] + $fresh;
t_equal(
    deploy_alert_text('catalog', $base, $late),
    'Каталог pionperm.ru: изменения из админки больше 90 минут не на сайте. Проверьте GitHub Actions («Сборка и выкладка»).',
    'текст про отставание каталога',
);
t_equal(
    deploy_alert_text('backup', $base, $stale),
    'Каталог pionperm.ru: свежей копии базы нет больше двух суток — проверьте задание обслуживания.',
    'текст про копии базы',
);
t_equal(
    deploy_alert_text('maintenance', $base, $said),
    'Каталог pionperm.ru: ночное обслуживание не удалось — диск полон.',
    'текст про обслуживание',
);
t_equal(
    deploy_alert_text('maintenance', $base, ['maintenance' => ['ok' => false, 'at' => 0, 'message' => " Уборка фото остановлена. \n"]] + $fresh),
    'Каталог pionperm.ru: ночное обслуживание не удалось — Уборка фото остановлена.',
    'текст про обслуживание: точка в конце сообщения не удваивается',
);
t_equal(
    deploy_alert_text('maintenance', $base, ['maintenance' => ['ok' => false, 'at' => 0, 'message' => '']] + $fresh),
    'Каталог pionperm.ru: ночное обслуживание не удалось — причина не записана.',
    'текст про обслуживание: сообщения нет',
);
$reasonText = static fn(string $message): string => deploy_alert_text('maintenance', $base, ['maintenance' => ['ok' => false, 'at' => 0, 'message' => $message]] + $fresh);
$prefix = 'Каталог pionperm.ru: ночное обслуживание не удалось — ';
t_equal($reasonText("Уборка фото остановлена\nпервая строка трассировки\nвторая"), $prefix . 'Уборка фото остановлена.', 'причина: только первая строка');
t_equal($reasonText("Уборка фото остановлена.\r\nтрассировка"), $prefix . 'Уборка фото остановлена.', 'причина: первая строка с переводом строки Windows, точка не удваивается');
t_equal($reasonText("\n\n  Нет места на диске  \n"), $prefix . 'Нет места на диске.', 'причина: пустые строки и пробелы по краям не в счёт');
// «х» (U+0445) в UTF-8 — байты D1 85, а 0x85 без флага /u разбор строк принял бы за перевод строки.
t_equal($reasonText('Не хватает места на диске'), $prefix . 'Не хватает места на диске.', 'причина с буквой «х» доходит целиком');
t_equal($reasonText("Не хватает места на диске\r\nхвост трассировки"), $prefix . 'Не хватает места на диске.', 'причина с «х» и переводом строки Windows: только первая строка');
t_equal($reasonText("Нет места, хватит ли хоть где-то\nвторая хх"), $prefix . 'Нет места, хватит ли хоть где-то.', 'причина с несколькими «х» и переводом строки Unix: только первая строка');
t_equal($reasonText("первая хх\rвторая"), $prefix . 'первая хх.', 'причина: одиночный возврат каретки тоже конец строки');
$cutAtX = $reasonText(str_repeat('я', 297) . str_repeat('х', 10));
t_equal($cutAtX, $prefix . str_repeat('я', 297) . 'хх…', 'причина: «х» на границе 300 знаков — сокращена целыми буквами, с «…»');
t_true(mb_check_encoding($cutAtX, 'UTF-8'), 'причина: после сокращения у «х» нет битых байтов');
t_true(mb_check_encoding($reasonText(str_repeat('х', 5000)), 'UTF-8'), 'причина из одних «х» остаётся корректным UTF-8');
t_true(str_contains($reasonText("Диск \xff полон"), 'Диск '), 'причина с битым байтом UTF-8 не пропадает');
t_equal($reasonText(str_repeat('я', 300)), $prefix . str_repeat('я', 300) . '.', 'причина: ровно 300 знаков — без сокращения');
t_equal($reasonText(str_repeat('я', 301)), $prefix . str_repeat('я', 299) . '…', 'причина: 301 знак — сокращена до 300 с «…», точки после «…» нет');
// Обслуживание само режет причину до 300 знаков с «…» на конце: для сторожа это не «длиннее 300», и точки после «…» всё равно нет.
t_equal($reasonText(str_repeat('я', 299) . '…'), $prefix . str_repeat('я', 299) . '…', 'причина: уже обрезана обслуживанием (ровно 300 знаков, «…» на конце) — «….» не выходит');
t_equal($reasonText('Нужна проверка…'), $prefix . 'Нужна проверка…', 'причина: короткая, но кончается на «…» — без точки');
t_equal($reasonText(str_repeat('ж', 5000) . "\nвторая строка"), $prefix . str_repeat('ж', 299) . '…', 'причина: длинная первая строка и вторая — одна сокращённая строка');
t_equal(mb_strlen(deploy_alert_text('maintenance', $base, ['maintenance' => ['ok' => false, 'at' => 0, 'message' => str_repeat('я', 9000)]] + $fresh)), mb_strlen($prefix) + 300, 'причина: сообщение целиком не длиннее префикса и 300 знаков');
t_true(str_contains(deploy_alert_text('maintenance', $base), 'ночное обслуживание не удалось'), 'текст про обслуживание без сторожа не падает');
t_true(str_contains(deploy_alert_text('lag', deploy_note_master($base, 'abcdef1234', 0)), 'abcdef1'), 'прежние тексты на месте');

// --- Сторож каталога: что читается из папки каталога -------------------------
t_case('сторож каталога: чтение папки', function (): void {
    $home = t_tmpdir();
    t_equal(deploy_catalog_watch("$home/pion-catalog", "$home/pion-catalog/catalog.sqlite"), null, 'базы нет — null');
    t_true(!file_exists("$home/pion-catalog"), 'сторож базу и папку каталога не создаёт');

    $db = t_catalog_db("$home/catalog.sqlite");
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite"),
        ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null],
        'база без правок, без копий и без итога обслуживания',
    );

    t_catalog_with_sections($db);
    catalog_touch($db, t_now());
    $version = catalog_meta($db)['version'] ?? '';
    t_true($version !== '', 'проверка: у базы появилась версия');
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite"),
        ['version' => $version, 'changedAt' => t_now()->getTimestamp(), 'backupAt' => null, 'maintenance' => null],
        'версия и время правки — из meta, в секундах',
    );

    mkdir("$home/backups");
    foreach (['catalog-2026-10-03.sqlite' => 1_700_000_000, 'catalog-2026-10-04.sqlite' => 1_700_086_400] as $name => $at) {
        file_put_contents("$home/backups/$name", 'x');
        touch("$home/backups/$name", $at);
    }
    foreach (['catalog-2026-10-05.sqlite.part', 'notes.txt', 'other-2026-10-05.sqlite'] as $name) {
        file_put_contents("$home/backups/$name", 'x');
        touch("$home/backups/$name", 1_800_000_000);
    }
    t_equal(deploy_catalog_watch($home, "$home/catalog.sqlite")['backupAt'], 1_700_086_400, 'копия — самая свежая catalog-*.sqlite; недописанные и чужие файлы не в счёт');

    file_put_contents("$home/maintenance.json", json_encode(['ok' => false, 'at' => catalog_iso(t_now()), 'message' => 'диск полон'], JSON_UNESCAPED_UNICODE));
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite")['maintenance'],
        ['ok' => false, 'at' => t_now()->getTimestamp(), 'message' => 'диск полон'],
        'итог обслуживания: сбой',
    );
    file_put_contents("$home/maintenance.json", json_encode(['ok' => true, 'at' => catalog_iso(t_now())]));
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite")['maintenance'],
        ['ok' => true, 'at' => t_now()->getTimestamp(), 'message' => ''],
        'итог обслуживания: порядок, сообщения нет',
    );
    foreach (['не json', '[]', '{"ok": "нет"}', '{"message": "без ok"}', '"ok"'] as $garbage) {
        file_put_contents("$home/maintenance.json", $garbage);
        t_equal(deploy_catalog_watch($home, "$home/catalog.sqlite")['maintenance'], null, "итог обслуживания не по контракту ($garbage) — как будто его нет");
    }
    file_put_contents("$home/maintenance.json", '{"ok": false, "at": "когда-то", "message": 5}');
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite")['maintenance'],
        ['ok' => false, 'at' => 0, 'message' => '5'],
        'итог обслуживания: время не разобрать — 0, сообщение — строкой',
    );
    $db = null;
});

t_case('сторож каталога: база не читается', function (): void {
    $garbage = 'это не база SQLite, а просто текст, достаточно длинный, чтобы SQLite не принял его за пустой файл';
    $home = t_tmpdir();
    file_put_contents("$home/catalog.sqlite", $garbage);
    $error = 'прежнее значение';
    t_equal(
        deploy_catalog_watch($home, "$home/catalog.sqlite", $error),
        ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null],
        'испорченный файл: сторож жив, о каталоге ему нечего сказать',
    );
    t_true(is_string($error) && $error !== '' && $error !== 'прежнее значение', 'испорченный файл: причина — в $dbError');

    // Копии и итог обслуживания лежат рядом с испорченной базой — сторож их видит.
    mkdir("$home/backups");
    file_put_contents("$home/backups/catalog-2026-10-04.sqlite", 'x');
    touch("$home/backups/catalog-2026-10-04.sqlite", 1_700_086_400);
    file_put_contents("$home/maintenance.json", json_encode(['ok' => false, 'at' => catalog_iso(t_now()), 'message' => 'диск полон'], JSON_UNESCAPED_UNICODE));
    $watch = deploy_catalog_watch($home, "$home/catalog.sqlite", $error);
    t_equal(
        $watch,
        [
            'version' => '', 'changedAt' => 0, 'backupAt' => 1_700_086_400,
            'maintenance' => ['ok' => false, 'at' => t_now()->getTimestamp(), 'message' => 'диск полон'],
        ],
        'испорченный файл: копии и итог обслуживания прочитаны независимо от базы',
    );
    $state = deploy_state_after_success(deploy_empty_state(), ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => '', 'catalogVersion' => 'v1'], 1000);
    t_equal(
        deploy_pending_alerts($state, t_now()->getTimestamp() + 600, $watch),
        ['backup', 'maintenance'],
        'испорченная база: о каталоге молчим, о копиях и обслуживании пишем',
    );

    // База новее кода (например, после отката /pay/): то же, и база не тронута.
    $newer = t_tmpdir();
    $db = t_catalog_db("$newer/catalog.sqlite");
    $db->exec('PRAGMA user_version = ' . (CATALOG_SCHEMA_VERSION + 1));
    $db = null;
    t_equal(
        deploy_catalog_watch($newer, "$newer/catalog.sqlite", $error),
        ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null],
        'база новее кода: версии нет, остальное читается',
    );
    t_true(is_string($error) && str_contains($error, 'новее кода'), 'база новее кода: причина — в $dbError');
    $db = new PDO('sqlite:' . "$newer/catalog.sqlite");
    t_equal((int)$db->query('PRAGMA user_version')->fetchColumn(), CATALOG_SCHEMA_VERSION + 1, 'версия схемы чужой базы не изменилась');
    $db = null;

    // Здоровая база и отсутствие базы сбрасывают прежнюю ошибку.
    $ok = t_tmpdir();
    $db = t_catalog_db("$ok/catalog.sqlite");
    $db = null;
    $error = 'прежнее значение';
    deploy_catalog_watch($ok, "$ok/catalog.sqlite", $error);
    t_equal($error, null, 'здоровая база: $dbError пуст');
    $error = 'прежнее значение';
    t_equal(deploy_catalog_watch("$ok/нет", "$ok/нет/catalog.sqlite", $error), null, 'файла базы нет — null, как раньше: каталог ещё не переносили, не пишем ни о чём');
    t_equal($error, null, 'файла базы нет: $dbError пуст');

    // Пустой файл — не «нет базы»: общее открытие создаёт в нём таблицы (так и сказано в описании функции).
    $empty = t_tmpdir();
    file_put_contents("$empty/catalog.sqlite", '');
    t_equal(
        deploy_catalog_watch($empty, "$empty/catalog.sqlite", $error),
        ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null],
        'пустой файл базы: как база без правок',
    );
    t_equal($error, null, 'пустой файл базы: ошибки нет');
    $db = new PDO('sqlite:' . "$empty/catalog.sqlite");
    t_equal((string)$db->query("SELECT name FROM sqlite_master WHERE name = 'meta'")->fetchColumn(), 'meta', 'пустой файл базы: таблицы созданы');
    $db = null;
});
// deploy-lib.php подключается и из scripts/check-server-build.php, которому каталог не нужен.
// Копия во временной папке: путь без кириллицы надёжно запускается отдельным процессом на Windows.
$alone = t_tmpdir();
copy(dirname(__DIR__, 2) . '/server-pay/deploy-lib.php', "$alone/deploy-lib.php");
file_put_contents("$alone/probe.php", "<?php\nrequire __DIR__ . '/deploy-lib.php';\necho function_exists('catalog_home') ? 'загружен' : 'нет';\n");
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$alone/probe.php") . ' 2>&1', $out, $code);
t_equal([$code, implode("\n", $out)], [0, 'нет'], 'deploy-lib.php сама каталог не подключает: он нужен только сторожу');
