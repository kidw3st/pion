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

// Сторож каталога: 90 минут — не раньше выкладки текущей сборки. Выложили новый код
// выгрузки: версия базы другая без единой правки, а последняя правка была давно.
$deployedAt = static fn(?int $at): array => ['current' => ['deployedAt' => $at] + $base['current']] + $base;
$oldEdit = ['version' => 'v2', 'changedAt' => $now - 30 * 86400] + $fresh;
t_equal(deploy_pending_alerts($deployedAt($now - 600), $now, $oldEdit), [], 'выкладка 10 минут назад, правка месяц назад — ещё рано, а не тревога сразу');
t_equal(deploy_pending_alerts($deployedAt($now - 5399), $now, $oldEdit), [], 'выкладка 89 минут 59 секунд назад — ещё рано');
t_equal(deploy_pending_alerts($deployedAt($now - 5400), $now, $oldEdit), ['catalog'], 'выкладка 90 минут назад, а версии всё разные — пишем');
t_equal(deploy_pending_alerts($deployedAt($now - 86400), $now, ['version' => 'v2', 'changedAt' => $now - 600] + $fresh), [], 'правка позже выкладки — отсчёт от правки');
t_equal(deploy_pending_alerts($deployedAt($now - 86400), $now, ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh), ['catalog'], 'правка позже выкладки и старше 90 минут — пишем');
t_equal(deploy_pending_alerts($deployedAt($now - 600), $now, ['version' => 'v1'] + $oldEdit), [], 'выложенная версия равна версии базы — молчим, как бы давно ни была правка');
$noDeployTime = $base;
unset($noDeployTime['current']['deployedAt']);
t_equal(deploy_pending_alerts($noDeployTime, $now, $oldEdit), ['catalog'], 'времени выкладки в состоянии нет — отсчёт только от правки');
foreach (['вчера', (string)($now - 600), $now - 600.5, true, [$now - 600]] as $junk) {
    $state = $deployedAt(null);
    $state['current']['deployedAt'] = $junk;
    t_equal(deploy_pending_alerts($state, $now, $oldEdit), ['catalog'], 'время выкладки не целым числом (' . json_encode($junk) . ') — неизвестно, отсчёт от правки');
}

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
        'версия — та, что отдаёт выгрузка, время правки — из meta, в секундах',
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

t_case('сторож каталога: сменился код выгрузки', function (): void {
    // Сборка считает версию новым кодом, а meta.version осталась от последней правки и
    // посчитана старым: содержимое то же. Сторож сверяет с выложенным версию выгрузки.
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    catalog_touch($db, t_now('-30 days'));
    $live = catalog_current_version($db);
    $db->exec("UPDATE meta SET value = 'старый-код-выгрузки' WHERE key = 'version'");
    $db = null;
    $error = 'прежнее значение';
    $watch = deploy_catalog_watch($home, "$home/catalog.sqlite", $error);
    t_equal([$watch['version'], $watch['changedAt'], $error], [$live, t_now('-30 days')->getTimestamp(), null], 'версия — по выгрузке, а не из meta; время правки — из meta');
    $deployed = static fn(string $version, int $at): array => deploy_state_after_success(deploy_empty_state(),
        ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => '', 'catalogVersion' => $version], $at);
    $now = t_now()->getTimestamp();
    t_equal(deploy_pending_alerts($deployed($live, $now - 86400), $now, $watch), ['backup'], 'выложена версия выгрузки — о каталоге молчим, хоть meta другая и правка давняя');
    // Сборка с новым кодом выложена, а каталог в ней посчитан ещё старым: ждём следующую.
    t_equal(deploy_pending_alerts($deployed('старый-код-выгрузки', $now - 600), $now, $watch), ['backup'], 'код выложен 10 минут назад — ещё рано');
    t_equal(deploy_pending_alerts($deployed('старый-код-выгрузки', $now - 5400), $now, $watch), ['catalog', 'backup'], 'через 90 минут после выкладки версии всё разные — пишем');

    // Выгрузка не считается (испорченный JSON в строке) — версия последнего сохранения, причина — в $dbError.
    $db = new PDO('sqlite:' . "$home/catalog.sqlite");
    $db->exec("UPDATE sections SET covers = 'не json' WHERE slug = 'bukety'");
    $db = null;
    $watch = deploy_catalog_watch($home, "$home/catalog.sqlite", $error);
    t_equal($watch['version'], 'старый-код-выгрузки', 'выгрузка не считается — версия из meta: сторож о каталоге не замолкает');
    t_equal($watch['changedAt'], t_now('-30 days')->getTimestamp(), 'время правки на месте');
    t_true(is_string($error) && str_starts_with($error, 'выгрузка каталога не считается: '), "причина — в \$dbError ($error)");
    t_equal(deploy_pending_alerts($deployed($live, $now - 86400), $now, $watch), ['catalog', 'backup'], 'выложенное не равно последнему сохранению — пишем');
});
// deploy-lib.php подключается и из scripts/check-server-build.php, которому каталог не нужен.
// Копия во временной папке: путь без кириллицы надёжно запускается отдельным процессом на Windows.
$alone = t_tmpdir();
copy(dirname(__DIR__, 2) . '/server-pay/deploy-lib.php', "$alone/deploy-lib.php");
file_put_contents("$alone/probe.php", "<?php\nrequire __DIR__ . '/deploy-lib.php';\necho function_exists('catalog_home') ? 'загружен' : 'нет';\n");
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$alone/probe.php") . ' 2>&1', $out, $code);
t_equal([$code, implode("\n", $out)], [0, 'нет'], 'deploy-lib.php сама каталог не подключает: он нужен только сторожу');

// --- Отметка о сообщении — только когда оно ушло ----------------------------
(static function (): void {
    $now = 1_800_000_000;
    // Копии нет совсем — сообщение о копии пора отправить.
    $watch = ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null];
    $lines = [];
    $log = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    t_true(in_array('backup', deploy_pending_alerts(deploy_empty_state(), $now, $watch), true), 'исходно: о копии пора писать');

    $failed = deploy_send_alerts(deploy_empty_state(), $now, $watch, static fn(string $text): string => 'Telegram ответил 502', $log);
    t_equal($failed['alerts']['backup'] ?? null, null, 'Telegram не принял — не отмечено');
    t_true(in_array('сообщение о сбое (backup): Telegram ответил 502', $lines, true), 'в журнале — что ответил Telegram');
    t_true(in_array('backup', deploy_pending_alerts($failed, $now + 900, $watch), true), 'через 15 минут сообщение снова пора отправить');

    $sent = deploy_send_alerts(deploy_empty_state(), $now, $watch, static fn(string $text): string => DEPLOY_TELEGRAM_SENT, $log);
    t_equal($sent['alerts']['backup'] ?? null, $now, 'отправлено — отмечено');
    t_true(!in_array('backup', deploy_pending_alerts($sent, $now + 900, $watch), true), 'и через 15 минут не повторяется');

    $off = deploy_send_alerts(deploy_empty_state(), $now, $watch, static fn(string $text): string => DEPLOY_TELEGRAM_OFF, $log);
    t_equal($off['alerts']['backup'] ?? null, $now, 'чат не настроен — отмечено: повтор ничего не даст, а журнал засорился бы');

    $texts = [];
    deploy_send_alerts(deploy_empty_state(), $now, $watch, static function (string $text) use (&$texts): string {
        $texts[] = $text;
        return DEPLOY_TELEGRAM_SENT;
    }, $log);
    t_true(in_array(deploy_alert_text('backup', deploy_empty_state(), $watch), $texts, true), 'отправляется текст deploy_alert_text');
})();

// --- Отметка о сообщении: граница, смешанный исход и повтор обслуживания -----
(static function (): void {
    $now = 1_800_000_000;
    $log = static function (string $line): void {
    };

    // Граница: выкладка в эту самую секунду (так её пишет deploy.php) отсчёт 90 минут сдвигает.
    // Если бы «сейчас» считалось будущим (< вместо <=), отсчёт пошёл бы от правки суточной давности.
    $state = ['current' => ['catalogVersion' => 'v1', 'deployedAt' => $now]] + deploy_empty_state();
    $fresh = ['version' => 'v2', 'changedAt' => $now - 86400, 'backupAt' => $now - 60, 'maintenance' => null];
    t_true(!in_array('catalog', deploy_pending_alerts($state, $now, $fresh), true),
        'deployedAt равно «сейчас»: берётся, отсчёт от выкладки — о каталоге не пишем');
    t_true(in_array('catalog', deploy_pending_alerts($state, $now + 5400, $fresh), true),
        'а через 90 минут после выкладки — пишем');

    // Два рода сразу: копии нет и обслуживание не удалось. Первое сообщение не ушло, второе ушло.
    $watch = ['version' => '', 'changedAt' => 0, 'backupAt' => null,
        'maintenance' => ['ok' => false, 'at' => $now - 60, 'message' => 'x']];
    $empty = deploy_empty_state();
    t_equal(deploy_pending_alerts($empty, $now, $watch), ['backup', 'maintenance'], 'исходно пора писать о двух родах');
    $backupText = deploy_alert_text('backup', $empty, $watch);
    $lines = [];
    $mixed = deploy_send_alerts($empty, $now, $watch, static fn(string $text): string => $text === $backupText ? 'Telegram ответил 502' : DEPLOY_TELEGRAM_SENT,
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    t_equal($mixed['alerts']['backup'] ?? null, null, 'смешанный исход: не принятое Telegram не отмечено');
    t_equal($mixed['alerts']['maintenance'] ?? null, $now, 'смешанный исход: принятое отмечено');
    t_equal($lines, ['сообщение о сбое (backup): Telegram ответил 502', 'сообщение о сбое (maintenance): ' . DEPLOY_TELEGRAM_SENT],
        'в журнале обе строки');
    t_equal(deploy_pending_alerts($mixed, $now + 900, $watch), ['backup'], 'через 15 минут пора повторить только не ушедшее');

    // Повтор обслуживания: сообщение не ушло — следующий запуск пробует снова, ушло — больше не пишет.
    $maint = ['version' => '', 'changedAt' => 0, 'backupAt' => $now - 60,
        'maintenance' => ['ok' => false, 'at' => $now - 60, 'message' => 'x']];
    $failed = deploy_send_alerts($empty, $now, $maint, static fn(string $text): string => 'Telegram ответил 502', $log);
    t_equal($failed['alerts']['maintenance'] ?? null, null, 'обслуживание: Telegram не принял — не отмечено');
    t_equal(deploy_pending_alerts($failed, $now + 900, $maint), ['maintenance'], 'обслуживание: через 15 минут всё ещё пора писать');
    $sent = deploy_send_alerts($failed, $now + 900, $maint, static fn(string $text): string => DEPLOY_TELEGRAM_SENT, $log);
    t_equal($sent['alerts']['maintenance'] ?? null, $now + 900, 'обслуживание: повтор принят — отмечено');
    t_equal(deploy_pending_alerts($sent, $now + 1800, $maint), [], 'обслуживание: после отправки не пора');
})();

// --- Время выкладки из будущего не откладывает сообщение о каталоге ----------
(static function (): void {
    $now = 1_800_000_000;
    $state = ['current' => ['catalogVersion' => 'v1', 'deployedAt' => $now + 86400]] + deploy_empty_state();
    $watch = ['version' => 'v2', 'changedAt' => $now - 5400, 'backupAt' => $now - 60, 'maintenance' => null];
    t_true(in_array('catalog', deploy_pending_alerts($state, $now, $watch), true),
        'deployedAt позже «сейчас» не берётся: правка не выложена 90 минут — пишем');
})();

// --- Сообщение, которое Telegram не принимает, пробуют не больше DEPLOY_ALERT_MAX_TRIES раз -
(static function (): void {
    $now = 1_800_000_000;
    t_equal(DEPLOY_ALERT_MAX_TRIES, 4, 'всего четыре попытки — примерно час через 15 минут');
    t_equal(deploy_empty_state()['alertTries'], [], 'в пустом состоянии счёта попыток нет');

    // Копии нет совсем: backup пора слать, срок между сообщениями — сутки.
    $watch = ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null];
    $lines = [];
    $log = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    $fail = static fn(string $text): string => 'Telegram ответил 502';
    $giveUp = 'сообщение о сбое (backup): не ушло с 4 попыток — следующая попытка после обычного перерыва';

    // Три неудачи подряд: сообщение всё ещё пора слать, счёт растёт.
    $state = deploy_empty_state();
    foreach ([0, 900, 1800] as $i => $shift) {
        t_equal(deploy_pending_alerts($state, $now + $shift, $watch), ['backup'], 'попытка ' . ($i + 1) . ': сообщение пора слать');
        $state = deploy_send_alerts($state, $now + $shift, $watch, $fail, $log);
        t_equal($state['alertTries']['backup'] ?? null, $i + 1, 'после неудачи ' . ($i + 1) . ' счёт равен ' . ($i + 1));
        t_equal($state['alerts']['backup'] ?? null, null, 'и отметки о сообщении нет');
    }
    t_true(!in_array($giveUp, $lines, true), 'пока попытки есть, строки «не ушло» в журнале нет');

    // Четвёртая неудача: сообщение считается отправленным и ждёт обычного перерыва (для backup — сутки).
    t_equal(deploy_pending_alerts($state, $now + 2700, $watch), ['backup'], 'четвёртая попытка: сообщение ещё пора слать');
    $state = deploy_send_alerts($state, $now + 2700, $watch, $fail, $log);
    t_equal($state['alerts']['backup'] ?? null, $now + 2700, 'четвёртая неудача: сообщение отмечено');
    t_true(!isset($state['alertTries']['backup']), 'и счёт снят');
    t_equal(deploy_pending_alerts($state, $now + 3600, $watch), [], 'через час после начала сообщение больше не повторяется');
    t_equal(deploy_pending_alerts($state, $now + 2700 + 86400 - 1, $watch), [], 'и ждёт всех суток');
    t_equal(count(array_keys($lines, 'сообщение о сбое (backup): Telegram ответил 502', true)), 4, 'ответ Telegram в журнале — по разу за каждую попытку');
    t_equal(count(array_keys($lines, $giveUp, true)), 1, 'строка «не ушло с 4 попыток» — одна');
    t_equal(end($lines), $giveUp, 'и последняя');
    // Перерыв прошёл — сообщение пора слать снова, и попытки отсчитываются заново.
    $later = $now + 2700 + 86400;
    t_equal(deploy_pending_alerts($state, $later, $watch), ['backup'], 'через сутки сообщение снова пора слать');
    $state = deploy_send_alerts($state, $later, $watch, $fail, $log);
    t_equal($state['alertTries']['backup'] ?? null, 1, 'и счёт начат заново');

    // Успех после двух неудач: отмечено, счёт снят.
    $state = deploy_empty_state();
    $state = deploy_send_alerts($state, $now, $watch, $fail, $log);
    $state = deploy_send_alerts($state, $now + 900, $watch, $fail, $log);
    t_equal($state['alertTries']['backup'] ?? null, 2, 'две неудачи — счёт 2');
    $state = deploy_send_alerts($state, $now + 1800, $watch, static fn(string $text): string => DEPLOY_TELEGRAM_SENT, $log);
    t_equal($state['alerts']['backup'] ?? null, $now + 1800, 'успех после двух неудач: отмечено');
    t_true(!isset($state['alertTries']['backup']), 'и счёт снят');
    t_equal($state['alertTries'], [], 'счёт пуст');

    // Чат не настроен: отмечено сразу, счёта нет.
    $off = deploy_send_alerts(['alertTries' => ['backup' => 2]] + deploy_empty_state(), $now, $watch, static fn(string $text): string => DEPLOY_TELEGRAM_OFF, $log);
    t_equal($off['alerts']['backup'] ?? null, $now, 'чат не настроен: отмечено');
    t_equal($off['alertTries'], [], 'и счёт снят');

    // Счёт одного рода не трогает другой: копия и обслуживание.
    $both = ['version' => '', 'changedAt' => 0, 'backupAt' => null,
        'maintenance' => ['ok' => false, 'at' => $now - 60, 'message' => 'x']];
    $empty = deploy_empty_state();
    $backupText = deploy_alert_text('backup', $empty, $both);
    $onlyBackupFails = static fn(string $text): string => $text === $backupText ? 'Telegram ответил 502' : DEPLOY_TELEGRAM_SENT;
    $state = deploy_send_alerts($empty, $now, $both, $fail, $log);
    t_equal($state['alertTries'], ['backup' => 1, 'maintenance' => 1], 'обоим по одной неудаче');
    $state = deploy_send_alerts($state, $now + 900, $both, $fail, $log);
    t_equal($state['alertTries'], ['backup' => 2, 'maintenance' => 2], 'и по второй');
    $state = deploy_send_alerts($state, $now + 1800, $both, $onlyBackupFails, $log);
    t_equal($state['alertTries'], ['backup' => 3], 'обслуживание ушло — его счёт снят, счёт копии цел и растёт');
    t_equal($state['alerts']['maintenance'] ?? null, $now + 1800, 'обслуживание отмечено');
    t_equal($state['alerts']['backup'] ?? null, null, 'копия — нет');
    $state = deploy_send_alerts($state, $now + 2700, $both, $onlyBackupFails, $log);
    t_equal($state['alerts']['backup'] ?? null, $now + 2700, 'у копии четвёртая неудача: отмечена');
    t_equal($state['alerts']['maintenance'] ?? null, $now + 1800, 'отметка обслуживания не сдвинулась');
    t_equal($state['alertTries'], [], 'счётов нет');

    // Счёт роду, о котором писать больше не нужно (сбой кончился), не переносится на следующий сбой.
    $stale = ['alertTries' => ['lag' => 3, 'backup' => 2]] + deploy_empty_state();
    $state = deploy_send_alerts($stale, $now, $watch, $fail, $log);
    t_equal($state['alertTries'], ['backup' => 3], 'счёт роду, которого нет среди тех, о ком пора писать, снят');
    $quiet = ['version' => '', 'changedAt' => 0, 'backupAt' => $now - 60, 'maintenance' => null];
    $state = deploy_send_alerts(['alertTries' => ['backup' => 3]] + deploy_empty_state(), $now, $quiet, $fail, $log);
    t_equal($state['alertTries'], [], 'писать не о чем — счёта нет');
    t_equal($state['alerts'], [], 'и отметок нет');
})();

// --- Испорченный alertTries в state.json не роняет отправку; старый state.json без него грузится -
(static function (): void {
    $now = 1_800_000_000;
    $watch = ['version' => '', 'changedAt' => 0, 'backupAt' => null, 'maintenance' => null];
    $log = static function (string $line): void {
    };
    $fail = static fn(string $text): string => 'Telegram ответил 502';
    $junk = [
        'строка' => 'мусор',
        'число' => 7,
        'null' => null,
        'значение — строка' => ['backup' => 'много'],
        'значение — массив' => ['backup' => ['вложено' => 1]],
        'значение — дробное' => ['backup' => 2.5],
        'значение — булево' => ['backup' => true],
        'значение — null' => ['backup' => null],
        'значение — отрицательное' => ['backup' => -5],
        'вложенный массив' => [['backup' => 1]],
    ];
    foreach ($junk as $what => $value) {
        $state = ['alertTries' => $value] + deploy_empty_state();
        try {
            $after = deploy_send_alerts($state, $now, $watch, $fail, $log);
            t_equal($after['alertTries'], ['backup' => 1], "alertTries — $what: не бросает, считается как 0");
            $after = deploy_send_alerts($state, $now, $watch, static fn(string $text): string => DEPLOY_TELEGRAM_SENT, $log);
            t_equal($after['alerts']['backup'] ?? null, $now, "alertTries — $what: отправлено — отмечено");
            t_equal($after['alertTries'], [], "alertTries — $what: и счёт чист");
        } catch (Throwable $e) {
            t_true(false, "alertTries — $what: бросило " . $e::class . ': ' . $e->getMessage());
        }
    }
    // Большое целое — попытки уже исчерпаны: одна неудача, и сообщение отмечено.
    $after = deploy_send_alerts(['alertTries' => ['backup' => 99]] + deploy_empty_state(), $now, $watch, $fail, $log);
    t_equal($after['alerts']['backup'] ?? null, $now, 'счёт больше предела: первая же неудача отмечает сообщение');

    // Старый state.json (без alertTries) и испорченный — через deploy_state_load.
    $home = t_tmpdir();
    file_put_contents("$home/state.json", json_encode(['current' => null, 'history' => [], 'bad' => [], 'badPairs' => [], 'failure' => null, 'alerts' => [], 'master' => null, 'head' => null]));
    $loaded = deploy_state_load($home);
    t_equal($loaded['alertTries'], [], 'старый state.json: счёт пуст');
    $after = deploy_send_alerts($loaded, $now, $watch, $fail, $log);
    t_equal($after['alertTries'], ['backup' => 1], 'старый state.json: первая неудача — счёт 1');
    file_put_contents("$home/state.json", json_encode(['alertTries' => 'мусор']));
    $loaded = deploy_state_load($home);
    try {
        $after = deploy_send_alerts($loaded, $now, $watch, $fail, $log);
        t_equal($after['alertTries'], ['backup' => 1], 'испорченный state.json (alertTries строкой) через deploy_state_load: не бросает');
        deploy_state_save($home, $after);
        t_equal(deploy_state_load($home)['alertTries'], ['backup' => 1], 'счёт сохраняется и читается обратно');
    } catch (Throwable $e) {
        t_true(false, 'испорченный state.json: бросило ' . $e::class . ': ' . $e->getMessage());
    }
})();
