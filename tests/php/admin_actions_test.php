<?php
/**
 * Статусы из карточки: опубликовать, снять (с подтверждением), вернуть,
 * удалить (с подтверждением), восстановить; кнопки по статусу.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

/** Действие из карточки: POST с uid и версией. */
function t_action(array $ctx, string $uid, string $action, int $version, array $extra = []): array
{
    return t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: ['action' => $action, 'uid' => $uid, 'version' => (string)$version] + $extra);
}

function t_card_body(array $ctx, string $uid): string
{
    return t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
}

t_case('опубликовать из карточки', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $r = t_action($ctx, $uid, 'publish', 1, ['title' => 'Букет «Весна»', 'price' => '4400', 'sections' => ['bukety'], 'images' => ['/images/catalog/bukety/buket-vesna-' . $uid . '-aaaaaaaa.webp']]);
    t_equal($r['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=published', 'опубликован');
    $p = t_row($ctx['db'], $uid);
    t_equal([$p['status'], $p['title'], $p['slug']], ['active', 'Букет «Весна»', 'buket-vesna'], 'правки сохранены, адрес закреплён по новому названию');
});

t_case('снять с продажи и вернуть', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $ask = t_action($ctx, $uid, 'hide', 2);
    t_true($ask['status'] === 200 && str_contains($ask['body'], 'с продажи?') && str_contains($ask['body'], 'name="confirm" value="1"'), 'сначала — вопрос');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'без подтверждения ничего не изменилось');
    t_equal(t_action($ctx, $uid, 'hide', 2, ['confirm' => '1'])['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=hidden', 'подтвердили — снят');
    t_equal(t_row($ctx['db'], $uid)['status'], 'hidden', 'в базе — снят');
    t_action($ctx, $uid, 'unhide', 3);
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'вернули в продажу без лишних вопросов');
});

t_case('удалить и восстановить', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $ask = t_action($ctx, $uid, 'delete', 2);
    t_true(str_contains($ask['body'], 'Восстановить букет можно в течение 90 дней'), 'вопрос объясняет последствия');
    t_equal(t_action($ctx, $uid, 'delete', 2, ['confirm' => '1'])['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=deleted', 'удалён');
    $card = t_card_body($ctx, $uid);
    t_true(str_contains($card, 'value="restore"') && !str_contains($card, 'value="save"'), 'у удалённого — только «Восстановить»');
    t_action($ctx, $uid, 'restore', 3);
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'восстановлен в продажу');
});

t_case('удалить черновик', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    t_true(str_contains(t_action($ctx, $uid, 'delete', 1)['body'], 'на сайте его не было'), 'вопрос про черновик');
    t_equal(t_action($ctx, $uid, 'delete', 1, ['confirm' => '1'])['headers']['Location'], '/pay/admin/?notice=removed', 'после удаления — к списку');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'черновика больше нет');
});

t_case('кнопки по статусу', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $draft = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $body = t_card_body($ctx, $draft);
    t_true(str_contains($body, 'value="publish"') && str_contains($body, 'value="delete"') && !str_contains($body, 'value="hide"'), 'черновик: опубликовать, удалить');
    $active = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $active, 1, t_now());
    $body = t_card_body($ctx, $active);
    t_true(str_contains($body, 'value="hide"') && !str_contains($body, 'value="publish"'), 'в продаже: снять с продажи');
    t_true(str_contains($body, 'Несохранённые правки'), 'подсказка: действия не сохраняют правки в карточке');
    catalog_hide($db, 'anna', $active, 2, t_now());
    t_true(str_contains(t_card_body($ctx, $active), 'value="unhide"'), 'снят: вернуть в продажу');
});

t_case('устаревшая версия', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $r = t_action($ctx, $uid, 'hide', 1, ['confirm' => '1']);
    t_true($r['status'] === 409 && str_contains($r['body'], 'уже изменён'), 'карточку открыли до чужой правки — действие не выполнено, объяснено');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'статус не изменился');
});

t_case('удалённый больше 90 дней', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now('-100 days'));
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now('-100 days'));
    catalog_delete($ctx['db'], 'anna', $uid, 2, t_now('-95 days'));
    $body = t_card_body($ctx, $uid);
    t_true(str_contains($body, 'восстановить его уже нельзя') && !str_contains($body, 'value="restore"'), 'кнопки нет — объяснение есть');
});
