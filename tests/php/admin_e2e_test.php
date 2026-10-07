<?php
/**
 * Админка целиком, как в браузере: встроенный сервер PHP раздаёт копию /pay/,
 * запросы идут по HTTP. Проверяется то, чего не видно в проверках страниц:
 * настоящие заголовки и куки, точки входа, загрузка фото, выгрузка после правки.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';

/** Копия server-pay как /pay/ во временном корне сайта. */
function t_e2e_site(): string
{
    $root = t_tmpdir();
    // Папка pay нужна до первого файла: порядок обхода зависит от файловой системы, и файл из корня
    // server-pay (тот же catalog-export.php) мог бы встретиться раньше любой подпапки.
    mkdir($root . '/pay', 0777, true);
    $src = str_replace('\\', '/', dirname(__DIR__, 2) . '/server-pay');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $root . '/pay/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
    return $root;
}

/** Свободный порт: система сама выбирает его, пока мы держим сокет, — случайный номер мог бы попасть на занятый. */
function t_e2e_free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException("не найти свободный порт: $errstr");
    }
    $port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

/**
 * Встроенный сервер PHP на свободном порту.
 *
 * Порт мог занять кто-то между выбором и запуском, и тогда fsockopen
 * постучался бы в чужой сервер, — поэтому ждём ещё и того, что наш процесс жив.
 *
 * @return array{proc: resource, pipes: array, port: int, log: string}
 */
function t_e2e_start(string $webroot, array $env): array
{
    $log = t_tmpdir() . '/server.log';
    for ($try = 0; $try < 5; $try++) {
        $port = t_e2e_free_port();
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $webroot],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $webroot,
            $env + getenv(),
        );
        if (!is_resource($proc)) {
            continue;
        }
        $server = ['proc' => $proc, 'pipes' => $pipes, 'port' => $port, 'log' => $log];
        for ($i = 0; $i < 50; $i++) {
            if (!(proc_get_status($proc)['running'] ?? false)) {
                break;
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return $server;
            }
            usleep(100000);
        }
        t_e2e_stop($server);
    }
    throw new RuntimeException('встроенный сервер PHP не запустился');
}

/**
 * Останавливает сервер и дожидается конца процесса: на Windows работающий
 * процесс держит открытым журнал и базу, и временную папку не удалить.
 */
function t_e2e_stop(array $server): void
{
    $status = proc_get_status($server['proc']);
    if ($status['running']) {
        proc_terminate($server['proc']);
        for ($i = 0; $i < 50 && (proc_get_status($server['proc'])['running'] ?? false); $i++) {
            usleep(100000);
        }
    }
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($server['proc']);
}

/** @return array{status: int, headers: array<string, list<string>>, body: string} */
function t_http(int $port, string $method, string $path, array $fields = [], string $cookie = '', ?string $jpeg = null, array $extraHeaders = []): array
{
    $headers = [];
    $content = '';
    if ($jpeg !== null) {
        $boundary = 'pion' . bin2hex(random_bytes(8));
        foreach ($fields as $name => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"photo.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n$jpeg\r\n--$boundary--\r\n";
        $headers[] = "Content-Type: multipart/form-data; boundary=$boundary";
    } elseif ($fields !== []) {
        $content = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    array_push($headers, ...$extraHeaders);
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m)) {
            $status = (int)$m[1];
            $out = [];
            continue;
        }
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $out[strtolower(trim($name))][] = trim($value);
    }
    return ['status' => $status, 'headers' => $out, 'body' => $body];
}

function t_csrf(string $html): string
{
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m);
    return $m[1] ?? '';
}

t_case('админка по HTTP', function (): void {
    $webroot = t_e2e_site();
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $password = catalog_user_add($db, 'olga', 'Ольга', t_now());
    $db = null;
    $server = t_e2e_start($webroot, ['PION_CATALOG_HOME' => $home, 'PION_WEBROOT' => $webroot, 'PION_DEPLOY_HOME' => t_tmpdir()]);
    $port = $server['port'];
    $failedBefore = $GLOBALS['t_failed'];
    try {
        $r = t_http($port, 'GET', '/pay/admin/');
        t_equal([$r['status'], $r['headers']['location'][0] ?? ''], [303, '/pay/admin/login.php'], 'без входа — на страницу входа');

        $r = t_http($port, 'GET', '/pay/admin/login.php');
        t_equal([$r['status'], $r['headers']['x-robots-tag'][0] ?? '', $r['headers']['cache-control'][0] ?? ''],
            [200, 'noindex, nofollow', 'no-store'], 'страница входа закрыта от поиска и кэша');

        $r = t_http($port, 'POST', '/pay/admin/login.php', ['login' => 'olga', 'password' => 'не тот']);
        t_true($r['status'] === 200 && str_contains($r['body'], 'Неверный логин или пароль'), 'неверный пароль — отказ');

        // Браузер сам ставит Sec-Fetch-Site: форма со страницы чужого сайта не входит, даже с верным паролем.
        $r = t_http($port, 'POST', '/pay/admin/login.php', ['login' => 'Olga', 'password' => $password], '', null, ['Sec-Fetch-Site: cross-site']);
        t_true($r['status'] === 400 && !isset($r['headers']['set-cookie']) && str_contains($r['body'], 'Форма устарела'), 'вход с чужого сайта — 400 без куки');

        $r = t_http($port, 'POST', '/pay/admin/login.php', ['login' => 'Olga', 'password' => $password], '', null, ['Sec-Fetch-Site: same-origin']);
        t_equal($r['status'], 303, 'вход со своего сайта проходит');
        $setCookie = $r['headers']['set-cookie'][0] ?? '';
        t_true($r['status'] === 303 && str_contains($setCookie, 'HttpOnly') && str_contains($setCookie, 'Secure') && str_contains($setCookie, 'SameSite=Strict'),
            'вход — кука сессии с защитными флагами');
        $cookie = explode(';', $setCookie)[0];

        $r = t_http($port, 'GET', '/pay/admin/', [], $cookie);
        t_equal([$r['status'], $r['headers']['location'][0] ?? ''], [303, '/pay/admin/password.php'], 'первый вход — сначала смена пароля');

        $csrf = t_csrf(t_http($port, 'GET', '/pay/admin/password.php', [], $cookie)['body']);
        $r = t_http($port, 'POST', '/pay/admin/password.php', ['csrf' => $csrf, 'current' => $password, 'new' => 'новый-пароль-ольги', 'repeat' => 'новый-пароль-ольги'], $cookie);
        t_equal($r['status'], 303, 'пароль сменён');

        $r = t_http($port, 'POST', '/pay/admin/product.php', ['csrf' => $csrf, 'action' => 'create', 'title' => 'Букет «Сквозной»', 'price' => '4 400', 'sections' => ['bukety']], $cookie);
        t_equal($r['status'], 303, 'черновик создан');
        parse_str((string)parse_url($r['headers']['location'][0] ?? '', PHP_URL_QUERY), $q);
        $uid = (string)($q['uid'] ?? '');

        $r = t_http($port, 'POST', '/pay/admin/photo.php', ['csrf' => $csrf, 'uid' => $uid], $cookie, t_jpeg(1200, 1600));
        $photo = json_decode($r['body'], true);
        t_true(($photo['ok'] ?? false) === true && is_file($webroot . $photo['path']), 'фото загружено и пересохранено в WebP');

        $r = t_http($port, 'POST', '/pay/admin/product.php', [
            'csrf' => $csrf, 'action' => 'publish', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Сквозной»', 'price' => '4400',
            'description' => '', 'sections' => ['bukety'], 'mainSection' => 'bukety', 'images' => [$photo['path'] ?? ''],
        ], $cookie);
        t_equal($r['status'], 303, 'опубликован');

        $export = json_decode(t_http($port, 'GET', '/pay/catalog-export.php')['body'], true);
        t_equal(array_column($export['products'] ?? [], 'title'), ['Букет «Сквозной»'], 'новый букет — в выгрузке для сайта');
        t_equal($export['products'][0]['images'] ?? [], [$photo['path'] ?? ''], 'с фото');

        $r = t_http($port, 'POST', '/pay/admin/product.php', ['action' => 'create', 'title' => 'Без токена', 'price' => '100', 'sections' => ['bukety']], $cookie);
        t_equal($r['status'], 400, 'POST без CSRF-токена отклонён');

        t_http($port, 'POST', '/pay/admin/logout.php', ['csrf' => $csrf], $cookie);
        t_equal(t_http($port, 'GET', '/pay/admin/', [], $cookie)['status'], 303, 'после выхода кука больше не пускает');
    } finally {
        t_e2e_stop($server);
        // Что отвечал сервер — видно только в его журнале; при сбое показываем хвост.
        if ($GLOBALS['t_failed'] > $failedBefore && is_file($server['log'])) {
            fwrite(STDERR, '  журнал встроенного сервера (хвост):' . PHP_EOL
                . substr((string)file_get_contents($server['log']), -3000) . PHP_EOL);
        }
    }
});
