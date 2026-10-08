<?php
/**
 * Готовность к переносу (этап 3А): ловушки, которые админка не должна
 * пропускать, когда в базе появится настоящий каталог.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';

t_case('вернуть в продажу без цены нельзя', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    catalog_hide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    // Так выглядят 19 коробок из Tilda после загрузки: сняты, цены нет.
    $db->prepare('UPDATE products SET price = 0 WHERE uid = ?')->execute([$uid]);
    $e = t_throws(fn () => catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now()), CatalogError::class, 'без цены — отказ');
    t_true($e !== null && str_contains($e->getMessage(), 'Сначала укажите цену'), 'понятное объяснение');
    t_equal(t_row($db, $uid)['status'], 'hidden', 'букет остался снятым');

    $db->prepare('UPDATE products SET price = 4400 WHERE uid = ?')->execute([$uid]);
    catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    t_equal(t_row($db, $uid)['status'], 'active', 'с ценой — вернулся в продажу');
});

t_case('опубликовать черновик без фото нельзя', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $post = [
        'action' => 'publish', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
    ];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_true($r['status'] === 422 && str_contains($r['body'], 'Добавьте хотя бы одно фото'), 'без фото — объяснение, форма на месте');
    t_equal(t_row($ctx['db'], $uid)['status'], 'draft', 'остался черновиком');

    $post['images'] = ['/images/catalog/bukety/buket-nezhnost-' . $uid . '-aaaaaaaa.webp'];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_equal($r['status'], 303, 'с фото — опубликован');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'в продаже');
});

t_case('новый раздел создаётся скрытым', function (): void {
    $db = t_catalog_with_sections();
    $slug = catalog_create_section($db, 'anna', 'Осень', t_now());
    $q = $db->prepare('SELECT visible FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    t_equal((int)$q->fetchColumn(), 0, 'без фото плитки раздел в каталоге не показывается');
    t_true(!in_array(['type' => 'section', 'slug' => $slug], catalog_export_data($db)['tiles'], true), 'в сетке выгрузки его нет');
    t_true(str_contains(ADMIN_NOTICES['section-created'], 'скрыт'), 'сотруднику объяснено, что раздел пока скрыт');
});

t_case('сохранение карточки возвращает фото из корзины', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $path = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-bbbbbbbb.webp';
    t_put_files($ctx['webroot'], [ltrim($path, '/') => 'webp']);
    // Фото загрузили, но карточку не сохраняли больше суток — ночная уборка унесла файл в корзину.
    catalog_photo_trash($ctx['webroot'], $path, t_now());
    t_true(!is_file($ctx['webroot'] . $path), 'файл в корзине');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'save', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => [$path],
    ]);
    t_equal($r['status'], 303, 'сохранено');
    t_true(is_file($ctx['webroot'] . $path), 'фото вернулось на место');
});

t_case('мусор вместо даты выкладки не роняет страницы', function (): void {
    $ctx = t_admin_ctx();
    $junkList = [
        'вчера вечером', 12345, ['2026'], '',
        // По форме похоже на дату, но не разбирается — на этом месте раньше падал журнал.
        '2026-13-45T10:00:00+05:00', '2026-10-05T25:61:61+05:00', '2026-10-05T14:05:00+99:00',
    ];
    foreach ($junkList as $junk) {
        $what = json_encode($junk, JSON_UNESCAPED_UNICODE);
        file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
            'catalogVersion' => 'v1', 'catalogChangedAt' => $junk,
        ]]));
        $deployed = admin_deployed_catalog($ctx['deployHome']);
        t_equal($deployed['changedAt'], '', 'значение ' . $what . ' — неизвестно');
        // Настоящее место падения: отметка у изменения в журнале и карточке.
        try {
            t_equal(admin_change_on_site('2026-10-05T14:00:00+05:00', $deployed), null, 'значение ' . $what . ' — отметка неизвестна, страница цела');
        } catch (Throwable $e) {
            t_true(false, 'значение ' . $what . ' уронило отметку: ' . $e->getMessage());
        }
    }
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $deployed = admin_deployed_catalog($ctx['deployHome']);
    t_equal($deployed['changedAt'], '2026-10-05T14:05:00+05:00', 'нормальная дата — как есть');
    t_equal(admin_change_on_site('2026-10-05T14:00:00+05:00', $deployed), true, 'с нормальной датой отметка считается');
});

t_case('публикация из карточки тоже возвращает фото из корзины', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $path = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-cccccccc.webp';
    t_put_files($ctx['webroot'], [ltrim($path, '/') => 'webp']);
    catalog_photo_trash($ctx['webroot'], $path, t_now());
    t_true(!is_file($ctx['webroot'] . $path), 'файл в корзине');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'publish', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => [$path],
    ]);
    t_equal($r['status'], 303, 'опубликовано');
    t_true(is_file($ctx['webroot'] . $path), 'фото вернулось на место');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'в продаже');
});
