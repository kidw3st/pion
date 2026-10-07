<?php
/**
 * Каркас админки: заголовки безопасности, переходы, экранирование, деньги и
 * даты по-русски, страница с меню.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/view.php';

t_case('ответы', function (): void {
    $page = admin_html('<p>x</p>');
    t_equal($page['status'], 200, 'страница — 200');
    foreach (ADMIN_SECURITY_HEADERS as $name => $value) {
        t_equal($page['headers'][$name] ?? null, $value, "заголовок $name у каждой страницы");
    }
    t_equal($page['headers']['X-Robots-Tag'], 'noindex, nofollow', 'админку не индексируют');
    t_equal($page['headers']['Cache-Control'], 'no-store', 'и не кэшируют');
    $go = admin_redirect('product.php', ['uid' => '553645466981', 'notice' => 'saved']);
    t_equal([$go['status'], $go['headers']['Location']], [303, '/pay/admin/product.php?uid=553645466981&notice=saved'], 'переход после POST — 303 на страницу админки');
    t_equal(admin_redirect('')['headers']['Location'], '/pay/admin/', 'переход в начало админки');
    t_equal(admin_redirect('')['headers']['X-Robots-Tag'], 'noindex, nofollow', 'у перехода — те же заголовки');
    $json = admin_json(['ok' => true, 'path' => '/images/catalog/a.webp']);
    t_equal([$json['headers']['Content-Type'], $json['body']], ['application/json; charset=utf-8', '{"ok":true,"path":"/images/catalog/a.webp"}'], 'JSON без экранирования слэшей');
});

t_case('поля формы', function (): void {
    $post = ['title' => 'Розы', 'price' => ['1'], 'sections' => ['bukety', ['x'], 'roses'], 'images' => 'не список'];
    t_equal(admin_str($post, 'title'), 'Розы', 'строка — как есть');
    t_equal(admin_str($post, 'price'), '', 'массив вместо строки (подделанный запрос) — пусто');
    t_equal(admin_str($post, 'nope'), '', 'нет поля — пусто');
    t_equal(admin_list($post, 'sections'), ['bukety', 'roses'], 'из списка — только строки');
    t_equal(admin_list($post, 'images'), [], 'строка вместо списка — пустой список');
});

t_case('текст, деньги, даты', function (): void {
    t_equal(h('<b>"x"&\'</b>'), '&lt;b&gt;&quot;x&quot;&amp;&#039;&lt;/b&gt;', 'h() экранирует всё опасное');
    t_equal(admin_rub(4400), "4\u{00A0}400\u{00A0}₽", 'цена с неразрывными пробелами');
    t_equal(admin_rub(300000), "300\u{00A0}000\u{00A0}₽", 'шестизначная цена');
    t_equal(admin_day('2026-03-12T09:05:00Z'), '12 марта', 'день по Перми');
    t_equal(admin_date('2026-03-12T21:05:00Z'), '13 марта, 02:05', 'дата и время по Перми — с переходом через полночь');
});

t_case('сообщения', function (): void {
    t_equal(admin_notice(admin_request(query: ['notice' => 'saved'])), 'Сохранено.', 'сообщение по ключу');
    t_equal(admin_notice(admin_request(query: ['notice' => '<script>'])), '', 'произвольный текст из адресной строки не показывается');
    t_equal(admin_error(''), '', 'нет ошибки — нет блока');
    t_equal(admin_error('Цена <неверна>'), '<p class="error">Цена &lt;неверна&gt;</p>', 'ошибка экранирована');
});

t_case('страница', function (): void {
    $user = ['name' => 'Анна <script>', 'csrf' => 'abc123'];
    $html = admin_layout('Букеты', '<p>содержимое</p>', $user, '<p class="status">статус</p>', 'Сохранено.');
    t_true(str_contains($html, '<meta name="robots" content="noindex, nofollow">'), 'запрет индексации и в самой странице');
    t_true(str_contains($html, 'Анна &lt;script&gt;'), 'имя в меню экранировано');
    t_true(str_contains($html, '<input type="hidden" name="csrf" value="abc123">'), 'выход — POST с CSRF-токеном');
    t_true(str_contains($html, 'href="/pay/admin/sections.php"') && str_contains($html, 'href="/pay/admin/log.php"'), 'меню: разделы и журнал');
    t_true(str_contains($html, '/pay/admin/assets/admin.css?v='), 'стили с меткой версии: после выкладки браузер берёт новые');
    t_true(str_contains($html, '<p class="notice">Сохранено.</p>') && str_contains($html, 'статус'), 'сообщение и строка статуса');
    t_true(!str_contains($html, 'style="') && !preg_match('/<script>/', $html), 'ни встроенных стилей, ни встроенных скриптов (CSP)');
    t_true(!str_contains(admin_layout('Вход', '<p>x</p>'), '<nav'), 'без входа — без меню');
    $ctx = ['user' => $user, 'status' => ''];
    $missing = admin_not_found($ctx, 'Такого раздела нет', 'sections.php');
    t_true($missing['status'] === 404 && str_contains($missing['body'], 'Такого раздела нет') && str_contains($missing['body'], 'href="/pay/admin/sections.php"'), '404 — понятная страница со ссылкой назад');
});
