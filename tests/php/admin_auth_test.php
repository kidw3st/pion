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
