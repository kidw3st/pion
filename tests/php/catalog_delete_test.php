<?php
/**
 * Удаление и восстановление, адрес удалённого тёзки, смена главного раздела.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('удалить черновик', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_delete($db, 'anna', $uid, 1, t_now());
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'черновик исчезает совсем — на сайте его не было');
    t_equal(t_section_order($db, 'bukety'), [], 'и из раздела тоже');
});

t_case('удалить и восстановить', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $b, 1, t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_delete($db, 'anna', $a, 2, t_now('+1 day'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['status_before_delete'], $p['deleted_at']], ['deleted', 'active', '2026-10-06T14:00:00+05:00'],
        'удалён, прежний статус запомнен');
    t_equal(t_redirects($db), ['/bukety/buket-a/' => '/bukety/'], 'с адреса — в главный раздел');
    t_equal(array_column(catalog_export_data($db)['products'], 'uid'), [$b], 'удалённого в выгрузке нет');
    t_throws(fn () => catalog_delete($db, 'anna', $a, 3, t_now()), CatalogError::class, 'удалить второй раз нельзя');
    t_throws(
        fn () => catalog_update_product($db, 'anna', $a, 3, t_fields(['title' => 'Букет А']), t_now()),
        CatalogError::class,
        'удалённый не правится',
    );
    t_equal(catalog_find_namesake($db, 'Букет А', t_now('+90 days'))['uid'] ?? null, $a, 'удалённый тёзка предлагается 90 дней');
    t_equal(catalog_find_namesake($db, 'Букет А', t_now('+92 days')), null, 'потом — нет');
    catalog_restore($db, 'anna', $a, 3, t_now('+91 days'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['slug'], $p['deleted_at']], ['active', 'buket-a', null], 'восстановлен в продажу по тому же адресу');
    t_equal(t_redirects($db), [], 'переадресация снята');
    t_equal(t_section_order($db, 'bukety'), [$a, $b], 'место в разделе прежнее');
});

t_case('восстановить — только 90 дней', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    catalog_hide($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    t_throws(fn () => catalog_restore($db, 'anna', $uid, 4, t_now('+91 days')), CatalogError::class, 'через 91 день не восстановить');
    catalog_restore($db, 'anna', $uid, 4, t_now('+90 days'));
    t_equal(t_row($db, $uid)['status'], 'hidden', 'через 90 — можно, и букет возвращается снятым, каким был');
});

t_case('адрес удалённого тёзки', function (): void {
    $db = t_catalog_with_sections();
    $old = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $old, 1, t_now());
    catalog_delete($db, 'anna', $old, 2, t_now());
    $new = catalog_create_product($db, 'anna', t_fields(), t_now('+1 hour'));
    catalog_publish($db, 'anna', $new, 1, t_now('+1 hour'));
    t_equal(t_row($db, $new)['slug'], 'buket-nezhnost', 'удалённые не в счёт: адрес достаётся новому букету');
    t_equal(t_redirects($db), [], 'страница по адресу снова живая');
    catalog_restore($db, 'anna', $old, 3, t_now('+2 hours'));
    t_equal(t_row($db, $old)['slug'], 'buket-nezhnost-2', 'восстановленный получает адрес с -2');
});

t_case('смена главного раздела', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['bukety', 'roses']]), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'roses']), t_now());
    t_equal(t_redirects($db), ['/bukety/buket-nezhnost/' => '/roses/buket-nezhnost/'], 'со старого адреса — на новый');
    catalog_update_product($db, 'anna', $uid, 3, t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'bukety']), t_now());
    t_equal(t_redirects($db), ['/roses/buket-nezhnost/' => '/bukety/buket-nezhnost/'],
        'вернули обратно — цепочка схлопнулась, с живого адреса переадресации нет');
});

t_case('правка без главного раздела не двигает адрес', function (): void {
    $db = t_catalog_with_sections();
    // Главный раздел выбран явно — «Розы», хотя по умолчанию был бы «Букеты»: он первый в сетке.
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'roses']), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    // Правят только цену: главный раздел в форме не прислали — ни пустым (null), ни пустой строкой.
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['sections' => ['bukety', 'roses'], 'price' => 4800]), t_now('+1 hour'));
    catalog_update_product($db, 'anna', $uid, 3, t_fields(['sections' => ['bukety', 'roses'], 'price' => 5200, 'mainSection' => '']), t_now('+2 hours'));
    $p = t_row($db, $uid);
    t_equal([$p['main_section'], $p['price']], ['roses', 5200], 'главный раздел остался прежним, цена сохранилась');
    t_equal(t_redirects($db), [], 'адрес не менялся — переадресации нет');

    // Галочку с главного раздела сняли: пересчёт по умолчанию, и у опубликованного адрес меняется.
    catalog_update_product($db, 'anna', $uid, 4, t_fields(['sections' => ['bukety']]), t_now('+3 hours'));
    t_equal(t_row($db, $uid)['main_section'], 'bukety', 'главный раздел больше не отмечен — выбран по умолчанию');
    t_equal(t_redirects($db), ['/roses/buket-nezhnost/' => '/bukety/buket-nezhnost/'], 'со старого адреса — переадресация на новый');
});

t_case('правка черновика без главного раздела', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'roses']), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['sections' => ['bukety', 'roses'], 'title' => 'Букет «Весна»']), t_now('+1 hour'));
    t_equal(t_row($db, $uid)['main_section'], 'roses', 'у черновика выбранный главный раздел тоже не пересчитывается');
});

t_case('адрес в новом разделе занят', function (): void {
    $db = t_catalog_with_sections();
    // Одна из трёх старых одноимённых пар: «pion» и в «Розах», и в «Букетах».
    t_put_product($db, '100000000001', 'active', 'roses', ['roses' => 0], 'pion');
    t_put_product($db, '100000000002', 'active', 'bukety', ['bukety' => 0, 'roses' => 1], 'pion');
    t_throws(
        fn () => catalog_update_product($db, 'anna', '100000000002', 1, t_fields([
            'title' => 'Пион', 'sections' => ['bukety', 'roses'], 'mainSection' => 'roses',
        ]), t_now()),
        CatalogError::class,
        'главным не сделать раздел, где такой адрес уже занят',
    );
    t_equal(t_row($db, '100000000002')['main_section'], 'bukety', 'главный раздел не изменился');
});
