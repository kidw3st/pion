<?php
/**
 * Каждая страница админки проходит одни и те же ворота: есть ли база, вошёл
 * ли сотрудник, верен ли CSRF-токен у POST, сменён ли выданный пароль — и
 * только потом сама страница.
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/status.php';

/** Страницы, куда пускают без входа. */
const ADMIN_PUBLIC_PAGES = ['login'];

/**
 * Окружение страниц: база (null — её ещё нет), время, корень сайта (там
 * images/catalog) и папка выкладки (для строки статуса).
 */
function admin_context(): array
{
    $webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 3), '/');
    $file = catalog_db_path();
    return [
        'db' => is_file($file) ? catalog_db_open($file) : null,
        'now' => new DateTimeImmutable(),
        'webroot' => $webroot,
        'deployHome' => rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/'),
    ];
}

/** @param callable(array, array): array $page */
function admin_handle(array $req, array $ctx, string $name, callable $page): array
{
    $ctx['user'] = null;
    $ctx['status'] = '';
    $ctx['deployed'] = null;
    $ctx['inSync'] = false;
    if ($ctx['db'] === null) {
        return admin_html(admin_layout('Админка', '<h1>Админка ещё не подключена</h1>'
            . '<p>Базы каталога на сервере пока нет. Обратитесь к разработчику.</p>'), 503);
    }
    $ctx['token'] = admin_str($req['cookies'], ADMIN_COOKIE);
    $user = admin_session($ctx['db'], $ctx['token'], $ctx['now']->getTimestamp());
    if (!in_array($name, ADMIN_PUBLIC_PAGES, true)) {
        if ($user === null) {
            return admin_redirect('login.php');
        }
        if ($req['method'] === 'POST' && !admin_csrf_ok($user, $req)) {
            return admin_html(admin_layout('Форма устарела', '<h1>Форма устарела</h1>'
                . '<p>Вернитесь назад, обновите страницу и повторите.</p>', $user), 400);
        }
        if ($user['must_change'] && !in_array($name, ['password', 'logout'], true)) {
            return admin_redirect('password.php');
        }
    }
    $ctx['user'] = $user;
    if ($user !== null) {
        $ctx['deployed'] = admin_deployed_catalog($ctx['deployHome']);
        $status = admin_deploy_status(catalog_meta($ctx['db']), $ctx['deployed'], $ctx['now']);
        $ctx['inSync'] = $status['kind'] === 'synced';
        $ctx['status'] = admin_status_line($status);
    }
    return $page($req, $ctx);
}

/** Запуск страницы из её файла (pay/admin/<страница>.php). */
function admin_run(string $name, callable $page): void
{
    try {
        admin_emit(admin_handle(admin_request_from_globals(), admin_context(), $name, $page));
    } catch (Throwable $e) {
        error_log('admin: ' . $e->getMessage());
        admin_emit(admin_html(admin_layout('Ошибка', '<h1>Что-то пошло не так</h1>'
            . '<p>Попробуйте ещё раз. Если повторится — сообщите разработчику, что и когда вы делали.</p>'), 500));
    }
}
