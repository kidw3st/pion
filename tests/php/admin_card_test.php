<?php
/**
 * Карточка букета: создание черновика, тёзка среди снятых, правка, вопрос о
 * цене, одновременная правка.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

function t_card(array $ctx, string $method = 'GET', array $query = [], array $post = []): array
{
    return t_admin_call($ctx, 'product', 'admin_page_product', $method, $query, $post);
}

t_case('цена из формы', function (): void {
    t_equal(admin_parse_price('4 400'), 4400, 'с пробелом');
    t_equal(admin_parse_price("4\u{00A0}400\u{00A0}₽"), 4400, 'как её пишет админка');
    t_equal(admin_parse_price(' 4400 руб. '), 4400, 'с «руб.»');
    foreach (['', 'дорого', '4400.50', '-100', '12345678'] as $bad) {
        t_throws(fn () => admin_parse_price($bad), CatalogError::class, "не цена: «" . $bad . "»");
    }
    t_equal([admin_price_jump(4400, 44000), admin_price_jump(4400, 8800), admin_price_jump(4400, 2199), admin_price_jump(4400, 2200)],
        [true, false, true, false], 'больше чем вдвое — в обе стороны; ровно вдвое — без вопроса');
});

t_case('новый букет', function (): void {
    $ctx = t_admin_ctx();
    $form = t_card($ctx, query: ['new' => '1'])['body'];
    t_true(str_contains($form, 'name="action" value="create"') && str_contains($form, 'name="sections[]" value="bukety"'), 'форма нового букета с разделами');
    t_true(str_contains($form, '(скрыт)'), 'скрытые разделы подписаны');
    $r = t_card($ctx, 'POST', post: ['action' => 'create', 'title' => 'Букет «Нежность»', 'price' => '4 400 ₽', 'description' => 'Розы', 'sections' => ['bukety']]);
    t_equal($r['status'], 303, 'черновик создан');
    parse_str((string)parse_url($r['headers']['Location'], PHP_URL_QUERY), $q);
    t_equal($q['notice'] ?? '', 'created', 'с сообщением');
    $p = t_row($ctx['db'], $q['uid']);
    t_equal([$p['status'], $p['price'], $p['title']], ['draft', 4400, 'Букет «Нежность»'], 'цена «4 400 ₽» понята как 4400');
});

t_case('ошибки в форме нового букета', function (): void {
    $ctx = t_admin_ctx();
    $r = t_card($ctx, 'POST', post: ['action' => 'create', 'title' => 'Букет <Тест>', 'price' => 'дорого', 'sections' => ['bukety']]);
    t_equal($r['status'], 422, 'не сохранено');
    t_true(str_contains($r['body'], 'Цена — целое число рублей'), 'объяснение для сотрудника');
    t_true(str_contains($r['body'], 'value="Букет &lt;Тест&gt;"'), 'введённое название не потерялось и экранировано');
    t_true(str_contains($r['body'], 'name="sections[]" value="bukety" checked'), 'и отмеченный раздел тоже');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'в базе ничего');
});

t_case('тёзка среди снятых', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $old = catalog_create_product($db, 'anna', t_fields(), t_now('-10 days'));
    catalog_publish($db, 'anna', $old, 1, t_now('-10 days'));
    catalog_hide($db, 'anna', $old, 2, t_now('-5 days'));
    $post = ['action' => 'create', 'title' => 'Букет «Нежность»', 'price' => '4400', 'sections' => ['bukety']];
    $ask = t_card($ctx, 'POST', post: $post);
    t_true(str_contains($ask['body'], 'Такой букет уже был — снят с продажи 30 сентября. Вернуть его?'), 'админка предлагает вернуть прежний');
    t_true(str_contains($ask['body'], 'href="/pay/admin/product.php?uid=' . $old . '"'), 'со ссылкой на него');
    t_true(str_contains($ask['body'], 'name="confirm_new" value="1"'), 'повторное «Создать» — если это другой букет');
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 1, 'новый пока не создан');
    t_equal(t_card($ctx, 'POST', post: $post + ['confirm_new' => '1'])['status'], 303, 'это другой букет — создаётся');
});

t_case('карточка и сохранение', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(['images' => ['/images/catalog/bukety/a.webp']]), t_now());
    $card = t_card($ctx, query: ['uid' => $uid])['body'];
    t_true(str_contains($card, 'value="Букет «Нежность»"') && str_contains($card, 'Адрес появится после публикации'), 'карточка черновика');
    t_true(str_contains($card, 'name="images[]" value="/images/catalog/bukety/a.webp"'), 'фото в форме — сохранение их не потеряет');
    t_true(str_contains($card, 'value="save"') && str_contains($card, 'value="publish"'), 'у черновика — «Сохранить черновик» и «Опубликовать»');
    $post = [
        'action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Весна»', 'price' => '4800', 'description' => 'Розы',
        'sections' => ['bukety', 'roses'], 'mainSection' => 'roses', 'images' => ['/images/catalog/bukety/a.webp'],
    ];
    $r = t_card($ctx, 'POST', post: $post);
    t_equal([$r['status'], $r['headers']['Location']], [303, '/pay/admin/product.php?uid=' . $uid . '&notice=saved'], 'сохранено');
    $p = t_row($db, $uid);
    t_equal([$p['title'], $p['price'], $p['main_section'], $p['images'], $p['version']],
        ['Букет «Весна»', 4800, 'roses', '["/images/catalog/bukety/a.webp"]', 2], 'всё записано');
});

t_case('цена изменилась больше чем вдвое', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $post = ['action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Нежность»', 'price' => '44000', 'sections' => ['bukety']];
    $ask = t_card($ctx, 'POST', post: $post);
    t_true(str_contains($ask['body'], "Цена была 4\u{00A0}400\u{00A0}₽, станет 44\u{00A0}000\u{00A0}₽. Всё верно?"), 'админка переспрашивает');
    t_true(str_contains($ask['body'], 'name="confirm_price" value="1"'), 'повторное «Сохранить» подтвердит цену');
    t_equal(t_row($ctx['db'], $uid)['price'], 4400, 'пока не подтвердили — цена прежняя');
    t_equal(t_card($ctx, 'POST', post: $post + ['confirm_price' => '1'])['status'], 303, 'подтвердили — сохранено');
    t_equal(t_row($ctx['db'], $uid)['price'], 44000, 'новая цена');
});

t_case('одновременная правка', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_update_product($ctx['db'], 'anna', $uid, 1, t_fields(['price' => 4500]), t_now());
    $r = t_card($ctx, 'POST', post: ['action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Мой вариант', 'price' => '4600', 'sections' => ['bukety']]);
    t_true($r['status'] === 409 && str_contains($r['body'], 'уже изменён'), 'чужую правку не затёрли — объяснили');
    t_true(str_contains($r['body'], 'value="Мой вариант"'), 'свой текст виден — его можно скопировать');
    t_equal(t_row($ctx['db'], $uid)['price'], 4500, 'в базе — правка коллеги');
});

t_case('нет такого букета', function (): void {
    $ctx = t_admin_ctx();
    t_equal(t_card($ctx, query: ['uid' => '999'])['status'], 404, 'понятная страница вместо ошибки');
    t_equal(t_card($ctx, 'POST', post: ['action' => 'save', 'uid' => '999'])['status'], 404, 'и при сохранении');
});
