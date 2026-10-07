<?php
/**
 * Вход, подбор пароля, сессии, CSRF, первая смена пароля, выход и пропуск на страницы.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-auth.php';

/** Страница-заглушка: показывает, что до неё дошли, и кто вошёл. */
function t_admin_probe(array $req, array $ctx): array
{
    return admin_html('страница: ' . ($ctx['user']['login'] ?? 'никто'));
}

t_case('вход', function (): void {
    $ctx = t_admin_ctx();
    $form = admin_handle(admin_request(), $ctx, 'login', 'admin_page_login');
    t_true($form['status'] === 200 && str_contains($form['body'], 'name="password"'), 'страница входа открывается без входа');
    t_equal($form['headers']['X-Robots-Tag'], 'noindex, nofollow', 'и закрыта от поиска');

    $wrong = admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true($wrong['status'] === 200 && str_contains($wrong['body'], 'Неверный логин или пароль.') && $wrong['cookies'] === [], 'неверный пароль — отказ без куки');
    $nobody = admin_handle(admin_request('POST', post: ['login' => 'olga', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true(str_contains($nobody['body'], 'Неверный логин или пароль.'), 'несуществующий логин — тот же ответ: не узнать, кто есть');

    $ok = admin_handle(admin_request('POST', post: ['login' => ' Anna ', 'password' => 'секрет-анны']), $ctx, 'login', 'admin_page_login');
    t_equal([$ok['status'], $ok['headers']['Location']], [303, '/pay/admin/'], 'верный пароль — в админку (логин без учёта регистра и пробелов)');
    $cookie = $ok['cookies'][0] ?? '';
    t_true(str_starts_with($cookie, 'pion_admin=') && str_ends_with($cookie, '; Path=/pay/admin/; HttpOnly; Secure; SameSite=Strict'),
        'кука сессии: только для админки, только https, не видна скриптам, не уходит с чужих сайтов');
    $token = substr(explode(';', $cookie)[0], strlen('pion_admin='));
    t_equal($ctx['db']->query('SELECT token_hash FROM sessions')->fetchAll(PDO::FETCH_COLUMN), [hash('sha256', $token)], 'в базе — только хэш токена');
    $again = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $ctx['now']->getTimestamp());
    t_true($again['token'] !== $token, 'каждый вход — новый идентификатор сессии');
});

t_case('подбор пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, 'anna', 'не тот', '10.0.0.1', $now);
    }
    $blocked = admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 60);
    t_equal($blocked['ok'], false, 'пять неверных попыток — логин закрыт, даже с другого адреса и с верным паролем');
    t_true(str_contains($blocked['error'], '10 минут'), 'сотрудник видит, сколько ждать');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 601)['ok'], true, 'через 10 минут вход снова открыт');
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, "x$i", 'не тот', '10.0.0.9', $now + 700);
    }
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.9', $now + 700)['ok'], false, 'пять неверных попыток с одного адреса — адрес закрыт для всех логинов');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.3', $now + 700)['ok'], true, 'с другого адреса этот логин входит');
});

t_case('подбор пароля: одновременные запросы не получают лишних попыток', function (): void {
    // Предел «5 попыток» должен держаться и когда запросы идут одновременно: проверка предела и запись
    // попытки — одно неделимое действие. Одновременности внутри одного PHP не воспроизвести, поэтому
    // здесь настоящие процессы: все двенадцать открывают базу, ждут общей команды и разом пробуют войти
    // с неверным паролем. У хэша стоимость 10, как на сайте: проверка идёт ~50 мс, и именно в это окно
    // раньше успевали пройти лишние запросы.
    $root = t_tmpdir();
    $file = "$root/catalog.sqlite";
    $ctx = t_admin_ctx(t_catalog_db($file));
    $db = $ctx['db'];
    $db->prepare("UPDATE users SET password_hash = ? WHERE login = 'anna'")
        ->execute([password_hash('секрет-анны', PASSWORD_BCRYPT, ['cost' => 10])]);

    // Копия кода во временной папке: путь без кириллицы, как в t_catalog_scripts() — так отдельные
    // процессы надёжно запускаются и на Windows.
    $src = dirname(__DIR__, 2) . '/server-pay';
    foreach (['admin/lib/auth.php', 'admin/lib/http.php', 'catalog/db.php'] as $rel) {
        if (!is_dir(dirname("$root/pay/$rel"))) {
            mkdir(dirname("$root/pay/$rel"), 0777, true);
        }
        copy("$src/$rel", "$root/pay/$rel");
    }
    file_put_contents("$root/racer.php", <<<'PHP'
        <?php
        declare(strict_types=1);

        [, $file, $dir, $n, $now] = $argv;
        require __DIR__ . '/pay/admin/lib/auth.php';
        $db = catalog_db_open($file);
        touch("$dir/ready-$n");
        $until = microtime(true) + 60;
        while (!is_file("$dir/go") && microtime(true) < $until) {
            usleep(500);
        }
        $result = admin_login($db, 'anna', 'не тот', '10.0.0.1', (int)$now);
        echo $result['ok'] ? 'вошёл' : $result['error'];
        PHP);

    $count = 12;
    $children = [];
    $pipes = [];
    for ($i = 0; $i < $count; $i++) {
        $process = proc_open(
            [PHP_BINARY, "$root/racer.php", $file, $root, (string)$i, (string)$ctx['now']->getTimestamp()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes[$i],
        );
        if ($process !== false) {
            fclose($pipes[$i][0]);
            $children[$i] = $process;
        }
    }
    t_equal(count($children), $count, 'все процессы запущены');
    $deadline = microtime(true) + 60;
    do {
        $ready = count(array_filter(array_keys($children), fn (int $i): bool => is_file("$root/ready-$i")));
        usleep(5000);
    } while ($ready < count($children) && microtime(true) < $deadline);
    touch("$root/go");
    $answers = [];
    foreach ($children as $i => $process) {
        $answers[] = trim((string)stream_get_contents($pipes[$i][1]));
        fclose($pipes[$i][1]);
        proc_close($process);
    }

    $counts = array_count_values($answers);
    ksort($counts);
    $want = ['Неверный логин или пароль.' => 5, 'Слишком много неудачных попыток. Подождите 10 минут и попробуйте снова.' => 7];
    ksort($want);
    t_equal($counts, $want, 'из двенадцати одновременных попыток пароль проверяется пять раз, остальные закрыты сразу');
    t_equal((int)$db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(), 5, 'в таблице — ровно пять попыток');
});

t_case('длинный логин из запроса не раздувает базу', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    t_equal(admin_login($db, str_repeat('я', 200000), 'не тот', '10.0.0.1', $now)['ok'], false, 'логин в 200 000 знаков — просто отказ');
    t_equal((int)$db->query('SELECT MAX(LENGTH(login)) FROM login_attempts')->fetchColumn(), ADMIN_LOGIN_KEY_MAX, 'в таблицу попыток пишется не больше ' . ADMIN_LOGIN_KEY_MAX . ' знаков');
    t_equal(admin_login_key('  ' . str_repeat('Ab', 100)), str_repeat('ab', 32), 'ключ — обрезанный, без регистра и пробелов');
    t_equal(admin_login($db, 'anna' . str_repeat(' ', 100) . 'x', 'секрет-анны', '10.0.0.1', $now)['ok'], false, 'логин, похожий на настоящий только началом, не входит');
    t_equal(admin_login($db, 'ANNA', 'секрет-анны', '10.0.0.1', $now)['ok'], true, 'обычный логин работает как раньше');
});

t_case('сессия: 12 часов без действий', function (): void {
    $ctx = t_admin_ctx();
    $now = $ctx['now']->getTimestamp();
    $token = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $now)['token'];
    t_equal(admin_session($ctx['db'], $token, $now + 3600)['login'] ?? null, 'anna', 'через час — сессия жива');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + ADMIN_IDLE_SECONDS - 1)['login'] ?? null, 'anna', 'считается от последнего действия, а не от входа');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + 2 * ADMIN_IDLE_SECONDS), null, 'больше 12 часов без действий — выход');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 0, 'просроченная сессия удалена');
    t_equal(admin_session($ctx['db'], 'не токен', $now), null, 'мусор вместо токена — не вошли');
});

t_case('занята запись: ожидание, а не мгновенный отказ', function (): void {
    // Пока другое соединение держит запись, наше обязано подождать busy_timeout. Но если в нём самом
    // ещё открыто чтение (запрос вернул строку и не закрыт), SQLite отвечает «database is locked» сразу,
    // не дожидаясь, — иначе два таких соединения зашли бы в тупик. Поэтому чтение закрывается до любой
    // записи. Ожидание сокращено до 0,3 с, чтобы проверка не тянулась.
    $file = t_tmpdir() . '/catalog.sqlite';
    $ctx = t_admin_ctx(t_catalog_db($file));
    $a = $ctx['db'];
    $a->exec('PRAGMA busy_timeout = 300');
    $now = $ctx['now']->getTimestamp();
    $cookies = [ADMIN_COOKIE => admin_login($a, 'anna', 'секрет-анны', '127.0.0.1', $now)['token']];
    $csrf = admin_session($a, $cookies[ADMIN_COOKIE], $now)['csrf'];

    $other = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('BEGIN IMMEDIATE');
    /** @return array{0: float, 1: string} сколько секунд вызов ждал и чем кончил */
    $wait = static function (callable $call): array {
        $start = microtime(true);
        $error = '';
        try {
            $call();
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
        return [microtime(true) - $start, $error];
    };
    $change = ['csrf' => $csrf, 'current' => 'секрет-анны', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'];
    $calls = [
        'продление сессии' => fn () => admin_session($a, $cookies[ADMIN_COOKIE], $now + 120),
        'вход существующего сотрудника' => fn () => admin_login($a, 'anna', 'не тот', '10.0.0.1', $now),
        // Оба входа обязаны вести себя одинаково: иначе по отказам «database is locked» видно, какие логины есть.
        'вход несуществующего' => fn () => admin_login($a, 'olga', 'не тот', '10.0.0.1', $now),
        'смена пароля' => fn () => admin_handle(admin_request('POST', post: $change, cookies: $cookies), $ctx, 'password', 'admin_page_password'),
    ];
    foreach ($calls as $what => $call) {
        [$took, $error] = $wait($call);
        t_true($took >= 0.25 && str_contains($error, 'locked'), "$what: ждёт, пока запись освободится, и только потом отказывает (ждали " . round($took, 3) . ' с)');
    }
    $other->exec('ROLLBACK');
});

t_case('пропуск на страницы', function (): void {
    $ctx = t_admin_ctx();
    $anon = admin_handle(admin_request(), $ctx, 'products', 't_admin_probe');
    t_equal([$anon['status'], $anon['headers']['Location']], [303, '/pay/admin/login.php'], 'без входа — на страницу входа');
    $cookies = t_admin_login($ctx);
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: anna', 'после входа страница открывается');
    $bad = admin_handle(admin_request('POST', post: ['csrf' => 'чужой'], cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_true($bad['status'] === 400 && !str_contains($bad['body'], 'страница:'), 'POST без верного CSRF-токена до страницы не доходит');
    $session = admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp());
    t_equal(admin_handle(admin_request('POST', post: ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'],
        'страница: anna', 'с верным токеном — доходит');
    $noDb = $ctx;
    $noDb['db'] = null;
    t_equal(admin_handle(admin_request(), $noDb, 'login', 'admin_page_login')['status'], 503, 'базы нет — админка честно говорит, что не подключена');
});

t_case('первый вход и смена пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $initial = catalog_user_add($db, 'olga', 'Ольга', t_now());
    $cookies = [ADMIN_COOKIE => admin_login($db, 'olga', $initial, '127.0.0.1', $now)['token']];
    $page = admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_equal([$page['status'], $page['headers']['Location']], [303, '/pay/admin/password.php'], 'с выданным паролем — сначала смена пароля');
    $form = admin_handle(admin_request(cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($form['body'], 'первый вход'), 'страница смены пароля объясняет, зачем');

    $phone = admin_login($db, 'olga', $initial, '127.0.0.2', $now)['token'];
    $session = admin_session($db, $cookies[ADMIN_COOKIE], $now);
    $try = fn (array $fields): array => admin_handle(
        admin_request('POST', post: $fields + ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($try(['current' => 'не тот', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'])['body'], 'Текущий пароль введён неверно'), 'неверный текущий пароль');
    t_true(str_contains($try(['current' => $initial, 'new' => 'коротко', 'repeat' => 'коротко'])['body'], 'не короче 8'), 'слишком короткий');
    t_true(str_contains($try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'другой-пароль'])['body'], 'не совпадают'), 'повтор не совпал');
    $done = $try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'новый-пароль']);
    t_equal([$done['status'], $done['headers']['Location']], [303, '/pay/admin/?notice=password'], 'пароль сменён');
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: olga', 'дальше — обычная работа');
    t_equal(admin_session($db, $phone, $now), null, 'вход с другого устройства закрыт: пароль могли сменить из-за утечки');
    t_true(admin_login($db, 'olga', 'новый-пароль', '127.0.0.3', $now)['ok'], 'новый пароль подходит');
    $log = implode(' ', $db->query("SELECT COALESCE(new_value, '') FROM audit WHERE object_type = 'user'")->fetchAll(PDO::FETCH_COLUMN));
    t_true(!str_contains($log, 'новый-пароль'), 'пароль в журнал не попадает');
});

t_case('выход', function (): void {
    $ctx = t_admin_ctx();
    $cookies = t_admin_login($ctx);
    $out = t_admin_call($ctx, 'logout', 'admin_page_logout', 'POST', cookies: $cookies);
    t_equal([$out['status'], $out['headers']['Location']], [303, '/pay/admin/login.php'], 'выход — на страницу входа');
    t_true(str_contains($out['cookies'][0] ?? '', 'Max-Age=0'), 'кука стирается');
    t_equal(admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp()), null, 'сессия удалена');
});
