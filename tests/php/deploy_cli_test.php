<?php
/**
 * server-pay/deploy.php как программа: что печатает и с каким кодом выходит.
 *
 * Остальные проверки вызывают функции deploy-lib.php, а сам deploy.php нигде
 * не запускался: ошибка при загрузке файла (повторное объявление функции или
 * константы) прошла бы и php -l, и эти проверки, и CI, а упала бы на сервере
 * под расписанием. Здесь он запускается отдельным процессом, как это делает cron.
 *
 * Запускается копия во временной папке: рядом с ней нет config.php, поэтому
 * до Telegram дело не доходит. Режимы, которые ходят в сеть (без аргумента,
 * --dry-run, --find-chat, --test-alert), отсюда запускать нельзя: t_cli_run
 * откажется. Исключение — без аргумента и --dry-run в песочнице, где GitHub
 * подменён папкой с файлами (t_cli_fake_github): сети там нет.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';
require_once __DIR__ . '/catalog_fixture.php';

/**
 * Копия deploy.php с библиотекой и кодом базы каталога во временной папке;
 * рядом пустые home (служебная папка pion-deploy), www (веб-корень) и
 * catalogHome (папка каталога pion-catalog: без неё сторож каталога в копии
 * пошёл бы искать базу по путям вокруг временной папки).
 *
 * @return array{script:string,home:string,www:string,catalogHome:string}
 */
function t_cli_sandbox(): array
{
    $root = t_tmpdir();
    mkdir("$root/catalog");
    foreach (['deploy.php', 'deploy-lib.php', 'catalog/db.php'] as $name) {
        if (!copy(dirname(__DIR__, 2) . '/server-pay/' . $name, "$root/$name")) {
            throw new RuntimeException("не скопировать $name");
        }
    }
    mkdir("$root/home");
    mkdir("$root/www");
    mkdir("$root/pion-catalog");
    return ['script' => "$root/deploy.php", 'home' => "$root/home", 'www' => "$root/www", 'catalogHome' => "$root/pion-catalog"];
}

/**
 * Запускает копию deploy.php в режиме $mode. Пути приходят через
 * PION_DEPLOY_HOME, PION_DEPLOY_WEBROOT и PION_CATALOG_HOME: дочерний процесс
 * наследует их, а после запуска они снимаются. Папка каталога подменяется
 * всегда: настоящую deploy.php читать не должен.
 *
 * @param bool $mergeStderr false — stderr выбрасывается, остаётся один stdout
 * @return array{0:int,1:string} код выхода и вывод
 */
function t_cli_run(array $box, string $mode, bool $mergeStderr = true): array
{
    $offline = ['--status', '--rollback', '--no-such-mode'];
    // Выкладка (без аргумента и --dry-run) допустима только там, где GitHub подменён.
    $faked = isset($box['fake']) && in_array($mode, ['', '--dry-run'], true);
    if (!$faked && !in_array($mode, $offline, true)) {
        throw new InvalidArgumentException("режим $mode ходит в сеть, из проверок его запускать нельзя");
    }
    $stderr = $mergeStderr ? '2>&1' : '2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    putenv('PION_DEPLOY_HOME=' . $box['home']);
    putenv('PION_DEPLOY_WEBROOT=' . $box['www']);
    putenv('PION_CATALOG_HOME=' . $box['catalogHome']);
    try {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($box['script']) . ' ' . $mode . ' ' . $stderr, $out, $code);
    } finally {
        putenv('PION_DEPLOY_HOME');
        putenv('PION_DEPLOY_WEBROOT');
        putenv('PION_CATALOG_HOME');
    }
    return [$code, implode("\n", $out)];
}

/**
 * Скачанная сборка, как её оставляет выкладка: releases/<sha>/ с двумя
 * архивами и списком файлов сайта.
 *
 * @param array<string,string> $site путь => содержимое
 * @param array<string,string> $pay
 * @return array{sha:string,commit:string,paySha256:string,deployedAt:int} запись для state.json
 */
function t_cli_release(string $home, string $sha, string $commit, array $site, array $pay, int $at): array
{
    $dir = deploy_release_dir($home, $sha);
    mkdir($dir, 0777, true);
    foreach (['site' => $site, 'pay' => $pay] as $kind => $files) {
        $src = t_tmpdir();
        t_put_files($src, $files);
        $out = [];
        exec(deploy_tar() . ' -czf ' . escapeshellarg("$dir/pion-$kind.tar.gz") . ' -C ' . escapeshellarg($src) . ' . 2>&1', $out, $code);
        if ($code !== 0) {
            throw new RuntimeException('tar: ' . implode(' ', $out));
        }
    }
    $list = array_keys($site);
    sort($list, SORT_STRING);
    deploy_save_release_files($home, $sha, $list);
    return ['sha' => $sha, 'commit' => $commit, 'paySha256' => (string)hash_file('sha256', "$dir/pion-pay.tar.gz"), 'deployedAt' => $at];
}

/** Минуты на часах сервера: deploy.php работает по времени Екатеринбурга. */
function t_cli_minute(): int
{
    return (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Yekaterinburg')))->format('i');
}

/**
 * Песочница для отката: выложена сборка B (sha из «b»), прошлая — A (из «a»).
 * В веб-корне то, что положила B, плюс чужое: config.php, фото каталога и
 * файл, которого нет ни в одной сборке.
 *
 * @param string|null $catalogB версия каталога сборки B в state.json; null — сборка без каталога
 * @return array{script:string,home:string,www:string,catalogHome:string}
 */
function t_cli_rollback_box(?string $catalogB = null): array
{
    $box = t_cli_sandbox();
    $required = ['404.html' => '404', '.htaccess' => 'rules', 'sitemap.xml' => '<urlset/>', 'robots.txt' => 'User-agent: *'];
    $siteA = ['index.html' => 'A home', 'only-a/index.html' => 'A only'] + $required;
    $siteB = ['index.html' => 'B home', 'only-b/index.html' => 'B only'] + $required;
    $payA = ['init.php' => '<?php echo "A";'];
    $payB = ['init.php' => '<?php echo "B";', 'new-in-b.php' => '<?php echo 1;'];

    $a = t_cli_release($box['home'], str_repeat('a', 40), str_repeat('1', 40), $siteA, $payA, 1000);
    $b = t_cli_release($box['home'], str_repeat('b', 40), str_repeat('2', 40), $siteB, $payB, 2000);
    if ($catalogB !== null) {
        $b['catalogVersion'] = $catalogB;
    }
    $state = deploy_empty_state();
    $state['current'] = $b;
    $state['history'] = [$a];
    deploy_state_save($box['home'], $state);

    t_put_files($box['www'], $siteB + ['images/catalog/x.webp' => 'photo', 'manual.txt' => 'not from any build']);
    t_put_files($box['www'] . '/pay', $payB + ['config.php' => '<?php // secrets']);
    return $box;
}

/**
 * Подменяет GitHub папкой с файлами, чтобы deploy.php (deploy_run) можно было
 * запустить как программу без сети.
 *
 * В копии deploy.php функция deploy_http заменяется на чтение из папки fake:
 * ответ на опрос веток — файл refs, скачивание сборки — releases/<sha>/<файл>.
 * Всё остальное — настоящий код выкладки. Любой другой адрес — исключение, так
 * что наружу копия не ходит. Если функция в deploy.php изменится так, что
 * подмена не найдёт её, проверка упадёт сразу, а не пойдёт в сеть.
 *
 * @param array{script:string,home:string,www:string,catalogHome:string} $box
 * @return array{script:string,home:string,www:string,catalogHome:string,fake:string}
 */
function t_cli_fake_github(array $box): array
{
    $fake = t_tmpdir();
    $stub = <<<'PHP'
function deploy_http(string $url, ?string $saveTo = null, int $timeout = 60): string
{
    $fake = __FAKE__;
    $raw = 'https://raw.githubusercontent.com/' . DEPLOY_REPO . '/';
    if ($url === 'https://github.com/' . DEPLOY_REPO . '.git/info/refs?service=git-upload-pack') {
        $file = $fake . '/refs';
    } elseif (str_starts_with($url, $raw)) {
        $file = $fake . '/releases/' . substr($url, strlen($raw));
    } else {
        throw new RuntimeException('проверка: адрес ' . $url . ' не подменён');
    }
    if (!is_file($file)) {
        throw new DeployNotFound('проверка: нет файла ' . $file);
    }
    if ($saveTo === null) {
        return (string)file_get_contents($file);
    }
    if (!copy($file, $saveTo)) {
        throw new RuntimeException('проверка: не скопировать ' . $file);
    }
    return '';
}
PHP;
    $stub = str_replace('__FAKE__', var_export($fake, true), $stub);
    $patched = preg_replace_callback('~function deploy_http\(.*?\n\}\r?\n~s', static fn(): string => $stub . "\n", (string)file_get_contents($box['script']), 1, $count);
    if ($count !== 1 || !is_string($patched) || str_contains($patched, 'CURLOPT_FOLLOWLOCATION')) {
        throw new RuntimeException('в deploy.php не нашлась функция deploy_http — подмена GitHub в проверках не сработала');
    }
    file_put_contents($box['script'], $patched);
    return $box + ['fake' => $fake];
}

/**
 * Выкладывает в «GitHub» сборку: архивы и build-info.json, как их кладёт в
 * ветку server-build deploy.yml. Каталог есть в build-info, если задана его версия.
 *
 * @param array{fake:string} $box
 */
function t_cli_publish(array $box, string $sha, string $commit, ?string $catalogVersion, string $label): void
{
    $required = ['404.html' => '404', '.htaccess' => 'rules', 'sitemap.xml' => '<urlset/>', 'robots.txt' => 'User-agent: *'];
    t_cli_release($box['fake'], $sha, $commit, ['index.html' => "$label home"] + $required, ['init.php' => "<?php echo '$label';"], 0);
    $dir = deploy_release_dir($box['fake'], $sha);
    $info = [
        'commit' => $commit,
        'builtAt' => '2026-10-08T07:00:00.000Z',
        'site' => ['sha256' => hash_file('sha256', "$dir/pion-site.tar.gz"), 'bytes' => filesize("$dir/pion-site.tar.gz")],
        'pay' => ['sha256' => hash_file('sha256', "$dir/pion-pay.tar.gz"), 'bytes' => filesize("$dir/pion-pay.tar.gz")],
    ];
    if ($catalogVersion !== null) {
        $info['catalog'] = ['version' => $catalogVersion, 'changedAt' => '2026-10-08T12:00:00+05:00', 'products' => 7];
    }
    file_put_contents("$dir/build-info.json", json_encode($info));
}

/** Как будто сообщение $kind ушло в момент $at: правит state.json. @param array{home:string} $box */
function t_cli_alerted_at(array $box, string $kind, int $at): void
{
    $state = deploy_state_load($box['home']);
    $state['alerts'][$kind] = $at;
    deploy_state_save($box['home'], $state);
}

/** Сборка $sha — голова ветки server-build в «GitHub». @param array{fake:string} $box */
function t_cli_set_head(array $box, string $sha): void
{
    file_put_contents($box['fake'] . '/refs', "$sha refs/heads/server-build\n");
}

/**
 * База каталога для сторожа в папке каталога песочницы: версия и время правки
 * в meta, свежая копия (если нужна) и итог обслуживания (если задан).
 *
 * @param array{catalogHome:string} $box
 * @param array{ok:bool,at:string,message:string}|null $maintenance
 */
function t_cli_catalog(array $box, string $version, int $changedAt, bool $backup = true, ?array $maintenance = null): void
{
    $db = catalog_db_open($box['catalogHome'] . '/catalog.sqlite');
    $set = $db->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
    $set->execute(['version', $version]);
    $set->execute(['changed_at', catalog_iso(new DateTimeImmutable('@' . $changedAt))]);
    $db = null;
    if ($backup) {
        t_put_files($box['catalogHome'] . '/backups', ['catalog-2026-10-08.sqlite' => 'copy']);
    }
    if ($maintenance !== null) {
        file_put_contents($box['catalogHome'] . '/maintenance.json', json_encode($maintenance, JSON_UNESCAPED_UNICODE));
    }
}

// --- Статус, неизвестный ключ, откат без истории: ничего не выложено ---------
$box = t_cli_sandbox();

[$code, $out] = t_cli_run($box, '--status');
t_equal($code, 0, '--status на пустой папке: код выхода');
t_equal($out, 'Ещё ничего не выкладывалось.', '--status на пустой папке: ответ');

[$code, $out] = t_cli_run($box, '--no-such-mode');
t_equal($code, 2, 'неизвестный ключ: код выхода');
t_true(str_contains($out, 'Режимы'), "неизвестный ключ: подсказка о режимах ($out)");

[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 1, 'откат без истории: код выхода');
t_true(str_contains($out, 'Откатываться не на что'), "откат без истории: ответ ($out)");

// --- Откат: сайт возвращается на прошлую сборку ---------------------------
$box = t_cli_rollback_box();
$home = $box['home'];
$www = $box['www'];

[$code, $out] = t_cli_run($box, '--status');
t_equal($code, 0, '--status с историей: код выхода');
t_true(str_contains($out, 'Выложена сборка bbbbbbb (коммит 2222222)'), "--status: текущая сборка ($out)");
t_true(str_contains($out, 'Для отката хранятся: aaaaaaa'), "--status: что хранится для отката ($out)");

[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 0, 'откат: код выхода');
t_true(
    str_contains($out, 'откат: сборка bbbbbbb помечена плохой, выложена aaaaaaa (коммит 1111111), удалено файлов 1'),
    "откат: что сделано ($out)",
);
t_equal(file_get_contents("$www/index.html"), 'A home', 'откат: главная прошлой сборки');
t_equal(file_get_contents("$www/only-a/index.html"), 'A only', 'откат: страница прошлой сборки вернулась');
t_true(!file_exists("$www/only-b/index.html"), 'откат: страница откаченной сборки удалена');
t_true(!is_dir("$www/only-b"), 'откат: опустевшая папка убрана');
t_equal(file_get_contents("$www/pay/init.php"), '<?php echo "A";', 'откат: /pay/ прошлой сборки');
t_equal(
    file_get_contents("$www/pay/new-in-b.php"),
    '<?php echo 1;',
    'откат: файл, который откаченная сборка добавила в /pay/, остаётся (/pay/ накладывается, не чистится)',
);
t_equal(file_get_contents("$www/pay/config.php"), '<?php // secrets', 'откат: config.php не тронут');
t_equal(file_get_contents("$www/images/catalog/x.webp"), 'photo', 'откат: фото каталога не тронуто');
t_equal(file_get_contents("$www/manual.txt"), 'not from any build', 'откат: файл не из сборки остался');

$state = deploy_state_load($home);
t_equal($state['current']['sha'] ?? null, str_repeat('a', 40), 'откат: текущая сборка в state.json — прошлая');
t_equal(array_keys($state['bad']), [str_repeat('b', 40)], 'откат: откаченная сборка помечена плохой');
t_equal($state['history'], [], 'откат: больше откатываться не на что');
t_true(
    str_contains((string)file_get_contents("$home/deploy.log"), 'откат: сборка bbbbbbb помечена плохой'),
    'откат записан в deploy.log',
);

[$code, $out] = t_cli_run($box, '--status');
t_true(str_contains($out, 'Выложена сборка aaaaaaa'), "--status после отката: текущая сборка ($out)");
t_true(str_contains($out, 'Не выкладывать bbbbbbb: откат вручную'), "--status после отката: плохая сборка ($out)");
t_true(!str_contains($out, 'Для отката хранятся'), "--status после отката: откатываться больше не на что ($out)");

[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 1, 'второй откат подряд: код выхода');
t_true(str_contains($out, 'Откатываться не на что'), "второй откат подряд: ответ ($out)");

// --- Откат не удался: сообщение не обещает, что сайт не менялся -------------
// У прошлой сборки нет архива сайта: распаковка падает до копирования, но
// сообщение должно быть верным и для отказа посреди копирования.
$box = t_cli_rollback_box();
unlink(deploy_release_dir($box['home'], str_repeat('a', 40)) . '/pion-site.tar.gz');
$siteBefore = t_snapshot($box['www']);
$stateBefore = md5_file($box['home'] . '/state.json');
[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 1, 'откат не удался: код выхода');
t_true(str_contains($out, 'Откат не удался: не распаковался pion-site.tar.gz'), "откат не удался: причина ($out)");
t_true(
    str_contains($out, '. Если копирование уже началось, сайт мог измениться частично — запустите откат ещё раз.'),
    "откат не удался: что делать ($out)",
);
t_true(
    str_contains((string)file_get_contents($box['home'] . '/deploy.log'), 'Откат не удался: не распаковался pion-site.tar.gz'),
    'откат не удался: запись в deploy.log',
);
t_equal(t_snapshot($box['www']), $siteBefore, 'откат не удался до копирования: сайт не тронут');
t_equal(md5_file($box['home'] . '/state.json'), $stateBefore, 'откат не удался: state.json прежний');

// --- После отката та же пара «код + каталог» не возвращается -----------------
// Откатили сборку B (код из «2», каталог $v1). Кнопка «Run workflow» собирает тот
// же коммит с тем же каталогом заново: в server-build новая sha, а пара прежняя.
// Выкладка должна её не принять; с другим каталогом (салон поправил данные) —
// принять. Тут deploy.php запускается целиком, GitHub подменён папкой.
$v1 = str_repeat('c', 64);
$v2 = str_repeat('d', 64);
$codeB = str_repeat('2', 40);
$box = t_cli_fake_github(t_cli_rollback_box($v1));
[$code] = t_cli_run($box, '--rollback');
t_equal($code, 0, 'пара: откат выполнен');
t_equal(array_keys(deploy_state_load($box['home'])['badPairs']), ["$codeB|$v1"], 'пара: откат пометил «код + каталог» откатанной сборки');

$same = str_repeat('3', 40);
t_cli_publish($box, $same, $codeB, $v1, 'same');
t_cli_set_head($box, $same);
$refusal = 'сборка 3333333 не выложена: повторяет откатанную сборку (код 2222222, каталог ccccccc)';

// Пробный прогон отвечает так же, но ничего не записывает.
$stateBefore = md5_file($box['home'] . '/state.json');
[$code, $out] = t_cli_run($box, '--dry-run');
t_equal($code, 0, 'пара: пробный прогон — код выхода');
t_true(str_contains($out, $refusal), "пара: пробный прогон отказывает ($out)");
t_equal(md5_file($box['home'] . '/state.json'), $stateBefore, 'пара: пробный прогон не меняет state.json');

$wwwBefore = t_snapshot($box['www']);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара: пересборка откатанного — код выхода');
t_true(str_contains($out, $refusal), "пара: пересборка откатанного не выложена, в журнале «повторяет откатанную» ($out)");
t_true(!str_contains($out, 'выложена сборка'), "пара: пересборка откатанного не выложена ($out)");
t_equal(t_snapshot($box['www']), $wwwBefore, 'пара: сайт не тронут');
$state = deploy_state_load($box['home']);
t_equal($state['current']['sha'] ?? null, str_repeat('a', 40), 'пара: на сайте по-прежнему прошлая сборка');
t_equal($state['history'], [], 'пара: история не пополнилась');
t_equal(
    $state['bad'][$same] ?? null,
    'повторяет откатанную сборку (код 2222222, каталог ccccccc)',
    'пара: отказанная sha помечена плохой с причиной',
);
t_equal($state['failure'], null, 'пара: отказ — не сбой, служебный чат молчит');
t_equal(array_keys($state['badPairs']), ["$codeB|$v1"], 'пара: плохая пара на месте');

// Помеченную sha каждые 15 минут заново не разбирают.
$logBefore = substr_count((string)file_get_contents($box['home'] . '/deploy.log'), 'повторяет откатанную');
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара: повторный запуск — код выхода');
t_equal($out, '', 'пара: повторный запуск ничего не делает');
t_equal(
    substr_count((string)file_get_contents($box['home'] . '/deploy.log'), 'повторяет откатанную'),
    $logBefore,
    'пара: повторный запуск не пишет отказ в журнал снова',
);

// Тот же код с другим каталогом выкладывается.
$fresh = str_repeat('4', 40);
t_cli_publish($box, $fresh, $codeB, $v2, 'fresh');
t_cli_set_head($box, $fresh);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара: тот же код с другим каталогом — код выхода');
t_true(str_contains($out, 'выложена сборка 4444444 (коммит 2222222)'), "пара: другой каталог выкладывается ($out)");
t_true(!str_contains($out, 'повторяет откатанную'), "пара: другой каталог не принят за откатанный ($out)");
t_equal(file_get_contents($box['www'] . '/index.html'), 'fresh home', 'пара: на сайте сборка с новым каталогом');
$state = deploy_state_load($box['home']);
t_equal($state['current']['sha'] ?? null, $fresh, 'пара: текущая сборка — с новым каталогом');
t_equal($state['current']['catalogVersion'] ?? null, $v2, 'пара: версия нового каталога в state.json');
t_equal(array_keys($state['badPairs']), ["$codeB|$v1"], 'пара: успешная выкладка плохие пары не снимает');

// Откатанная пара остаётся плохой и после других выкладок.
$again = str_repeat('5', 40);
t_cli_publish($box, $again, $codeB, $v1, 'again');
t_cli_set_head($box, $again);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара: откатанная пара позже — код выхода');
t_true(str_contains($out, 'сборка 5555555 не выложена: повторяет откатанную сборку'), "пара: откатанная пара не вернулась и после другой выкладки ($out)");
t_equal(deploy_state_load($box['home'])['current']['sha'] ?? null, $fresh, 'пара: текущая сборка прежняя');

// Сборка без каталога: пара — «код|».
$box = t_cli_fake_github(t_cli_rollback_box());
[$code] = t_cli_run($box, '--rollback');
t_equal($code, 0, 'пара без каталога: откат выполнен');
t_equal(array_keys(deploy_state_load($box['home'])['badPairs']), ["$codeB|"], 'пара без каталога: помечен «код|»');
$rebuilt = str_repeat('6', 40);
t_cli_publish($box, $rebuilt, $codeB, null, 'rebuilt');
t_cli_set_head($box, $rebuilt);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара без каталога: пересборка — код выхода');
t_true(
    str_contains($out, 'сборка 6666666 не выложена: повторяет откатанную сборку (код 2222222, каталог —)'),
    "пара без каталога: пересборка откатанного не выложена ($out)",
);
t_equal(file_get_contents($box['www'] . '/index.html'), 'A home', 'пара без каталога: сайт остался на прошлой сборке');
$newCode = str_repeat('7', 40);
$next = str_repeat('8', 40);
t_cli_publish($box, $next, $newCode, null, 'next');
t_cli_set_head($box, $next);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'пара без каталога: новый код — код выхода');
t_true(str_contains($out, 'выложена сборка 8888888 (коммит 7777777)'), "пара без каталога: новый код выкладывается ($out)");
t_equal(file_get_contents($box['www'] . '/index.html'), 'next home', 'пара без каталога: на сайте новый код');

// Подмена GitHub не выпускает в сеть: чужие режимы по-прежнему закрыты.
t_throws(static fn() => t_cli_run($box, '--find-chat'), InvalidArgumentException::class, 'подмена GitHub: --find-chat всё равно закрыт');
t_throws(static fn() => t_cli_run(t_cli_sandbox(), ''), InvalidArgumentException::class, 'без подмены GitHub выкладку запустить нельзя');

// --- Сторож каталога: deploy.php читает базу и пишет в служебный чат ------------
// Песочница смотрит в свою папку каталога (PION_CATALOG_HOME), настоящую не
// читает. Служебный чат в песочнице не настроен: о том, что сообщение ушло бы,
// говорит строка в журнале.
$say = static fn(string $kind): string => "сообщение о сбое ($kind): служебный чат не настроен";
$bBuild = str_repeat('b', 40);

// Базы нет — сторож молчит, выкладка работает, папку каталога не трогает.
$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: базы каталога нет — молчим');
t_equal(t_snapshot($box['catalogHome']), [], 'сторож: в папку каталога ничего не пишет');

// Правка сохранена 2 часа назад, а на сайте прежний каталог; копия свежая.
t_cli_catalog($box, $v2, time() - 7200);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'сторож: правка не выложена — код выхода');
t_true(str_contains($out, $say('catalog')), "сторож: правка не выложена 2 часа — сообщение ($out)");
t_true(!str_contains($out, $say('backup')) && !str_contains($out, $say('maintenance')), "сторож: копия свежая, итога обслуживания нет — о них молчим ($out)");
t_equal(array_keys(deploy_state_load($box['home'])['alerts']), ['catalog'], 'сторож: в state.json записано, о чём написали');
// Сразу следующий запуск, потом через 15 минут (сообщение «ушло» 15 минут назад)
// и через 3 часа: о каталоге напоминаем раз в 3 часа.
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: сразу следующий запуск то же сообщение не повторяет');
t_cli_alerted_at($box, 'catalog', time() - 900);
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: через 15 минут то же сообщение не повторяется');
t_cli_alerted_at($box, 'catalog', time() - DEPLOY_ALERT_COOLDOWN - 60);
[$code, $out] = t_cli_run($box, '');
t_true(str_contains($out, $say('catalog')), "сторож: через 3 часа о каталоге напоминаем ($out)");

// Всё выложено — тишина, даже если правка свежая.
$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
t_cli_catalog($box, $v1, time() - 7200);
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: версия в базе выложена — молчим');
t_equal(deploy_state_load($box['home'])['alerts'], [], 'сторож: в state.json ничего не записано');

// База не читается: выкладка идёт, в журнале строка. Молчит только о каталоге:
// о копиях и обслуживании сторож пишет, как обычно (иначе испорченная база
// заглушила бы и тревогу о том, что копии не делаются).
// Итог обслуживания: неудачный запуск закончился $ago секунд назад.
$maintenanceFailed = fn(int $ago = 0): array => ['ok' => false, 'at' => catalog_iso(new DateTimeImmutable('@' . (time() - $ago))), 'message' => 'диск полон'];
$brokenDb = static function (array $box): void {
    file_put_contents($box['catalogHome'] . '/catalog.sqlite', 'это не база SQLite, а просто текст, достаточно длинный, чтобы SQLite не принял его за пустой файл');
};
$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
$brokenDb($box);
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'сторож: база не читается — выкладка не падает');
t_true(str_contains($out, 'не прочитать базу каталога для сторожа: '), "сторож: причина в журнале ($out)");
t_true(str_contains($out, $say('backup')), "сторож: база не читается, копий нет — о копиях пишем ($out)");
t_true(!str_contains($out, $say('catalog')) && !str_contains($out, $say('maintenance')) && !str_contains($out, 'упал'), "сторож: о каталоге молчим, выкладка не упала ($out)");
t_true(str_contains((string)file_get_contents($box['home'] . '/deploy.log'), 'не прочитать базу каталога для сторожа: '), 'сторож: строка записана и в deploy.log');
t_equal(deploy_state_load($box['home'])['current']['sha'] ?? null, $bBuild, 'сторож: state.json записан как обычно');
t_equal(array_keys(deploy_state_load($box['home'])['alerts']), ['backup'], 'сторож: в state.json — только о копиях');

$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
$brokenDb($box);
t_put_files($box['catalogHome'] . '/backups', ['catalog-2026-10-08.sqlite' => 'copy']);
file_put_contents($box['catalogHome'] . '/maintenance.json', json_encode($maintenanceFailed(), JSON_UNESCAPED_UNICODE));
[$code, $out] = t_cli_run($box, '');
t_equal($code, 0, 'сторож: база не читается, обслуживание упало — код выхода');
t_true(str_contains($out, 'не прочитать базу каталога для сторожа: '), "сторож: и тут причина в журнале ($out)");
t_true(str_contains($out, $say('maintenance')), "сторож: база не читается — о сбое обслуживания пишем ($out)");
t_true(!str_contains($out, $say('backup')) && !str_contains($out, $say('catalog')), "сторож: копия свежая — о копиях и каталоге молчим ($out)");
t_equal(array_keys(deploy_state_load($box['home'])['alerts']), ['maintenance'], 'сторож: в state.json — только об обслуживании');

// Обслуживание: одно сообщение на каждый неудавшийся запуск (а не по сроку).
$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
t_cli_catalog($box, $v1, time() - 60, true, $maintenanceFailed());
[$code, $out] = t_cli_run($box, '');
t_true(str_contains($out, $say('maintenance')), "сторож: неудавшийся запуск — сообщение ($out)");
// Прошло несколько часов: запуск кончился 6 часов назад, сообщение ушло 5 часов назад.
file_put_contents($box['catalogHome'] . '/maintenance.json', json_encode($maintenanceFailed(6 * 3600), JSON_UNESCAPED_UNICODE));
t_cli_alerted_at($box, 'maintenance', time() - 5 * 3600);
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: о том же запуске через 5 часов (срок в 3 часа прошёл) не повторяет');
file_put_contents($box['catalogHome'] . '/maintenance.json', json_encode($maintenanceFailed(), JSON_UNESCAPED_UNICODE));
[$code, $out] = t_cli_run($box, '');
t_true(str_contains($out, $say('maintenance')), "сторож: новый неудавшийся запуск — новое сообщение сразу ($out)");

// Копии нет: напоминаем раз в сутки, а не раз в 3 часа.
$box = t_cli_fake_github(t_cli_rollback_box($v1));
t_cli_set_head($box, $bBuild);
t_cli_catalog($box, $v1, time() - 60, false);
[$code, $out] = t_cli_run($box, '');
t_true(str_contains($out, $say('backup')), "сторож: копий нет — сообщение ($out)");
t_cli_alerted_at($box, 'backup', time() - 4 * 3600);
[$code, $out] = t_cli_run($box, '');
t_equal([$code, $out], [0, ''], 'сторож: о копиях через 4 часа не напоминаем');
t_cli_alerted_at($box, 'backup', time() - 86400 - 60);
[$code, $out] = t_cli_run($box, '');
t_true(str_contains($out, $say('backup')), "сторож: о копиях через сутки напоминаем ($out)");
// Каждый путь deploy_run отдаёт сторожу данные: забытая передача молча отключила
// бы сообщения на этом пути (по умолчанию watch пуст).
$paths = [
    // Ветки server-build в «GitHub» нет вовсе — опрос не удаётся.
    'GitHub не отвечает' => [1, static fn(array $box) => null],
    'новой сборки нет' => [0, static function (array $box) use ($bBuild): void {
        t_cli_set_head($box, $bBuild);
    }],
    'сборка повторяет откатанную' => [0, static function (array $box) use ($v1, $codeB): void {
        t_cli_publish($box, str_repeat('3', 40), $codeB, $v1, 'same');
        t_cli_set_head($box, str_repeat('3', 40));
        $state = deploy_state_load($box['home']);
        $state['badPairs'][deploy_pair_key(['commit' => $codeB, 'catalogVersion' => $v1])] = 'откат вручную';
        deploy_state_save($box['home'], $state);
    }],
    'сборка испорчена' => [1, static function (array $box): void {
        t_cli_set_head($box, str_repeat('4', 40));
    }],
    'сборка скачалась не целиком' => [1, static function (array $box) use ($v1, $codeB): void {
        t_cli_publish($box, str_repeat('5', 40), $codeB, $v1, 'torn');
        file_put_contents(deploy_release_dir($box['fake'], str_repeat('5', 40)) . '/pion-site.tar.gz', 'обрыв', FILE_APPEND);
        t_cli_set_head($box, str_repeat('5', 40));
    }],
    'сборка выложена' => [0, static function (array $box) use ($v1, $codeB): void {
        t_cli_publish($box, str_repeat('6', 40), $codeB, $v1, 'new');
        t_cli_set_head($box, str_repeat('6', 40));
    }],
];
foreach ($paths as $name => [$wantCode, $setup]) {
    $box = t_cli_fake_github(t_cli_rollback_box($v1));
    t_cli_catalog($box, $v1, time() - 60, true, $maintenanceFailed());
    $setup($box);
    [$code, $out] = t_cli_run($box, '');
    t_equal($code, $wantCode, "сторож ($name): код выхода ($out)");
    t_true(str_contains($out, $say('maintenance')), "сторож ($name): сбой обслуживания доходит до чата ($out)");
    t_true(is_int(deploy_state_load($box['home'])['alerts']['maintenance'] ?? null), "сторож ($name): сообщение отмечено в state.json");
}

// Пробный прогон, откат и статус базу не читают: сообщений они не шлют.
$box = t_cli_fake_github(t_cli_rollback_box($v1));
file_put_contents($box['catalogHome'] . '/catalog.sqlite', 'это не база SQLite, а просто текст, достаточно длинный, чтобы SQLite не принял его за пустой файл');
t_cli_set_head($box, $bBuild);
foreach (['--status', '--dry-run', '--rollback'] as $mode) {
    [, $out] = t_cli_run($box, $mode);
    t_true(!str_contains($out, 'сторожа'), "сторож: режим $mode базу каталога не читает ($out)");
}

// --- Замок занят: вторая выкладка не стартует -------------------------------
$box = t_cli_sandbox();
$held = fopen($box['home'] . '/deploy.lock', 'c');
t_true($held !== false && flock($held, LOCK_EX | LOCK_NB), 'проверка заняла замок сама');
[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 0, 'замок занят: код выхода');
t_equal($out, 'Другая выкладка ещё идёт.', 'замок занят: ответ');
if (is_resource($held)) {
    flock($held, LOCK_UN);
    fclose($held);
}

// --- Замок не открыть — это не «занято», а ошибка прав на папку --------------
// Вместо файла папка: fopen её не откроет ни на Windows, ни на Linux.
$box = t_cli_sandbox();
mkdir($box['home'] . '/deploy.lock');
[$code, $out] = t_cli_run($box, '--rollback');
t_equal($code, 2, 'замок не открыть: код выхода');
t_true(
    str_contains($out, 'Не открыть ' . $box['home'] . '/deploy.lock — проверьте права на папку pion-deploy.'),
    "замок не открыть: ответ ($out)",
);
t_true(!str_contains($out, 'Другая выкладка'), "замок не открыть: не выдаётся за занятый ($out)");
[, $stdout] = t_cli_run($box, '--rollback', false);
t_equal($stdout, '', 'замок не открыть: сообщение идёт в STDERR, stdout пуст');

// --- deploy.php упал: в deploy.log пишется всегда, в чат — раз в час ---------
// Состояние не записать (на месте state.json.part папка), поэтому после отката
// падает сам deploy.php. Cron идёт в :00, :15, :30 и :45, сообщение уходит
// только в первую четверть часа. Какая из двух веток отработала, зависит от
// часов; запуск на границе четверти часа не проверяется.
$box = t_cli_rollback_box();
mkdir($box['home'] . '/state.json.part');
$minuteBefore = t_cli_minute();
[$code, $out] = t_cli_run($box, '--rollback');
$minuteAfter = t_cli_minute();
$log = (string)file_get_contents($box['home'] . '/deploy.log');
t_equal($code, 1, 'падение: код выхода');
t_true(str_contains($log, 'deploy.php упал: RuntimeException: не записать state.json'), "падение записано в deploy.log ($log)");
if (($minuteBefore < 15) === ($minuteAfter < 15)) {
    t_equal(
        str_contains($log, 'сообщение о падении: служебный чат не настроен'),
        $minuteBefore < 15,
        "падение в :$minuteBefore — сообщение в чат " . ($minuteBefore < 15 ? 'уходит' : 'не уходит'),
    );
    t_equal(
        str_contains($log, 'сообщение о падении пропущено до начала часа'),
        $minuteBefore >= 15,
        "падение в :$minuteBefore — пропуск " . ($minuteBefore >= 15 ? 'записан' : 'не нужен'),
    );
}
