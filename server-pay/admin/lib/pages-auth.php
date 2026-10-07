<?php
/**
 * Экраны входа, выхода и смены пароля.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function admin_page_login(array $req, array $ctx): array
{
    if ($ctx['user'] !== null) {
        return admin_redirect('');
    }
    $error = '';
    if ($req['method'] === 'POST') {
        // У формы входа нет CSRF-токена: токен лежит в сессии, а её ещё нет. Защита здесь — кука SameSite=Strict
        // и заголовок браузера: форма, отправленная со страницы чужого сайта, не входит и попыток не тратит.
        // Нет заголовка (старый браузер) — пропускаем: иначе такие браузеры не смогли бы войти.
        if ($req['fetchSite'] === 'cross-site') {
            return admin_html(admin_layout('Форма устарела', '<h1>Форма устарела</h1>'
                . '<p>Обновите страницу входа и повторите.</p>'), 400);
        }
        $result = admin_login(
            $ctx['db'],
            admin_str($req['post'], 'login'),
            admin_str($req['post'], 'password'),
            $req['ip'],
            $ctx['now']->getTimestamp(),
        );
        if ($result['ok']) {
            $response = admin_redirect('');
            $response['cookies'][] = admin_session_cookie($result['token']);
            return $response;
        }
        $error = $result['error'];
    }
    $html = '<h1>Вход</h1>' . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'login.php" class="form">'
        . '<label>Логин<input name="login" autocomplete="username" autocapitalize="none" required value="'
        . h(admin_str($req['post'], 'login')) . '"></label>'
        . '<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<p class="buttons"><button class="btn" type="submit">Войти</button></p></form>';
    return admin_html(admin_layout('Вход', $html));
}

function admin_page_logout(array $req, array $ctx): array
{
    if ($req['method'] !== 'POST') {
        return admin_redirect('');
    }
    admin_logout($ctx['db'], $ctx['token']);
    $response = admin_redirect('login.php');
    $response['cookies'][] = admin_clear_cookie();
    return $response;
}

function admin_page_password(array $req, array $ctx): array
{
    $user = $ctx['user'];
    $error = '';
    if ($req['method'] === 'POST') {
        $error = admin_change_password(
            $ctx['db'],
            $user,
            $req['ip'],
            admin_str($req['post'], 'current'),
            admin_str($req['post'], 'new'),
            admin_str($req['post'], 'repeat'),
            $ctx['token'],
            $ctx['now'],
        ) ?? '';
        if ($error === '') {
            return admin_redirect('', ['notice' => 'password']);
        }
    }
    $intro = $user['must_change']
        ? '<p>Это первый вход: придумайте свой пароль вместо выданного — не короче 8 знаков.</p>'
        : '';
    $html = '<h1>Смена пароля</h1>' . $intro . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'password.php" class="form">' . admin_csrf_field($user)
        . '<label>Текущий пароль<input name="current" type="password" autocomplete="current-password" required></label>'
        . '<label>Новый пароль<input name="new" type="password" autocomplete="new-password" minlength="8" required></label>'
        . '<label>Повторите новый пароль<input name="repeat" type="password" autocomplete="new-password" minlength="8" required></label>'
        . '<p class="buttons"><button class="btn" type="submit">Сменить пароль</button></p></form>';
    return admin_html(admin_layout('Смена пароля', $html, $user, $ctx['status']));
}
