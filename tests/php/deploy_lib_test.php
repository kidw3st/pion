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
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'backup', $now - DEPLOY_ALERT_COOLDOWN), $now, ['backupAt' => null] + $fresh), ['backup'], 'через 3 часа напоминаем');
$lagging = deploy_note_master(deploy_note_head($base, str_repeat('1', 40), 1000), str_repeat('b', 40), 1000);
t_equal(deploy_pending_alerts($lagging, $now, $late), ['lag', 'catalog'], 'отставание коммитов и каталога — отдельные сообщения');

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
    $home = t_tmpdir();
    file_put_contents("$home/catalog.sqlite", 'это не база SQLite, а просто текст, достаточно длинный, чтобы SQLite не принял его за пустой файл');
    t_throws(static fn() => deploy_catalog_watch($home, "$home/catalog.sqlite"), PDOException::class, 'испорченный файл — исключение: вызывающий пишет его в журнал');

    $newer = t_tmpdir();
    $db = t_catalog_db("$newer/catalog.sqlite");
    $db->exec('PRAGMA user_version = ' . (CATALOG_SCHEMA_VERSION + 1));
    $db = null;
    t_throws(static fn() => deploy_catalog_watch($newer, "$newer/catalog.sqlite"), RuntimeException::class, 'база новее кода — исключение');
    $db = new PDO('sqlite:' . "$newer/catalog.sqlite");
    t_equal((int)$db->query('PRAGMA user_version')->fetchColumn(), CATALOG_SCHEMA_VERSION + 1, 'версия схемы чужой базы не изменилась');
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
