<?php
/**
 * Общее для проверок админки: база с разделами и сотрудницей, вход, запросы.
 * Не *_test.php — run.php его сам не запускает.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/users.php';
require_once __DIR__ . '/../../server-pay/admin/lib/app.php';

/**
 * Окружение страниц: база с разделами и сотрудницей anna (пароль
 * «секрет-анны», начальный уже сменён), временный корень сайта и папка выкладки.
 */
function t_admin_ctx(?PDO $db = null): array
{
    $db ??= t_catalog_with_sections();
    $db->prepare("INSERT OR IGNORE INTO users (login, name, password_hash, must_change, created_at, updated_at)
        VALUES ('anna', 'Анна', ?, 0, '2026-10-01T10:00:00+05:00', '2026-10-01T10:00:00+05:00')")
        // Стоимость 4 — только ради скорости проверок.
        ->execute([password_hash('секрет-анны', PASSWORD_BCRYPT, ['cost' => 4])]);
    return ['db' => $db, 'now' => t_now(), 'webroot' => t_tmpdir(), 'deployHome' => t_tmpdir()];
}

/** Вход anna — куки для следующих запросов. */
function t_admin_login(array $ctx): array
{
    $result = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $ctx['now']->getTimestamp());
    return [ADMIN_COOKIE => $result['token']];
}

/** Запрос к странице от имени anna; в POST сам подставляет CSRF-токен сессии. */
function t_admin_call(
    array $ctx,
    string $name,
    callable $page,
    string $method = 'GET',
    array $query = [],
    array $post = [],
    ?array $cookies = null,
    array $files = [],
): array {
    $cookies ??= t_admin_login($ctx);
    if ($method === 'POST' && !array_key_exists('csrf', $post)) {
        $session = admin_session($ctx['db'], $cookies[ADMIN_COOKIE] ?? null, $ctx['now']->getTimestamp());
        $post['csrf'] = $session['csrf'] ?? '';
    }
    return admin_handle(admin_request($method, $query, $post, $cookies, $files), $ctx, $name, $page);
}
