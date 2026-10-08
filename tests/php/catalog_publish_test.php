<?php
/**
 * Публикация, снятие с продажи и возврат, тёзки среди снятых.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('публикация', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now('+1 hour'));
    $p = t_row($db, $uid);
    t_equal(
        [$p['status'], $p['slug'], $p['slug_pinned'], $p['published_at'], $p['version']],
        ['active', 'buket-nezhnost', 1, '2026-10-05T15:00:00+05:00', 2],
        'в продаже, slug закреплён',
    );
    t_equal(array_column(catalog_export_data($db)['products'], 'uid'), [$uid], 'опубликованный — в выгрузке');
    t_equal(catalog_meta($db)['changed_at'], '2026-10-05T15:00:00+05:00', 'публикация ждёт выкладки');
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['title' => 'Букет «Весна»']), t_now('+2 hours'));
    t_equal(t_row($db, $uid)['slug'], 'buket-nezhnost', 'после публикации переименование адрес не меняет');
    t_throws(fn () => catalog_publish($db, 'anna', $uid, 3, t_now()), CatalogError::class, 'опубликовать второй раз нельзя');
});

t_case('тёзки получают -2, -3', function (): void {
    $db = t_catalog_with_sections();
    $slugs = [];
    foreach (['bukety', 'roses', 'bukety'] as $section) {
        $uid = catalog_create_product($db, 'anna', t_fields(['sections' => [$section]]), t_now());
        catalog_publish($db, 'anna', $uid, 1, t_now());
        $slugs[] = t_row($db, $uid)['slug'];
    }
    t_equal($slugs, ['buket-nezhnost', 'buket-nezhnost-2', 'buket-nezhnost-3'], 'закреплённый slug уникален во всём каталоге');
});

t_case('опубликованный — первым в разделе', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    t_equal(t_section_order($db, 'bukety'), [$a, $b], 'опубликованный из черновика встаёт первым');
});

t_case('снять и вернуть', function (): void {
    $db = t_catalog_with_sections();
    // Вернуть в продажу можно только букет с фото.
    $photo = fn (string $name): array => ['images' => ['/images/catalog/bukety/' . $name . '.webp']];
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А'] + $photo('buket-a')), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б'] + $photo('buket-b')), t_now());
    catalog_publish($db, 'anna', $b, 1, t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_hide($db, 'anna', $a, 2, t_now('+1 hour'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['slug'], $p['status_changed_at']], ['hidden', 'buket-a', '2026-10-05T15:00:00+05:00'], 'снят, адрес тот же');
    t_equal(catalog_export_data($db)['sections'][0]['products'], [$a, $b], 'в выгрузке снятый остаётся на своём месте');
    catalog_unhide($db, 'anna', $a, 3, t_now('+2 hours'));
    t_equal([t_row($db, $a)['status'], t_section_order($db, 'bukety')], ['active', [$a, $b]], 'вернулся в продажу на прежнее место');
    t_throws(fn () => catalog_unhide($db, 'anna', $a, 4, t_now()), CatalogError::class, 'вернуть можно только снятый');
    $draft = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет В']), t_now());
    t_throws(fn () => catalog_hide($db, 'anna', $draft, 1, t_now()), CatalogError::class, 'черновик снять нельзя — его нет на сайте');
});

t_case('тёзка среди снятых', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    t_equal(catalog_find_namesake($db, 'Букет «Нежность»', t_now()), null, 'черновик тёзкой не считается');
    catalog_publish($db, 'anna', $uid, 1, t_now());
    t_equal(catalog_find_namesake($db, 'Букет «Нежность»', t_now()), null, 'букет в продаже — не повод предлагать возврат');
    catalog_hide($db, 'anna', $uid, 2, t_now('+1 day'));
    t_equal(catalog_find_namesake($db, 'букет  "нежность"', t_now('+2 days')), [
        'uid' => $uid, 'title' => 'Букет «Нежность»', 'status' => 'hidden', 'since' => '2026-10-06T14:00:00+05:00',
    ], 'снятый тёзка находится — кавычки, регистр и пробелы не мешают');
});

t_case('публикация снимает переадресацию с адреса', function (): void {
    $db = t_catalog_with_sections();
    $db->exec("INSERT INTO redirects (from_path, to_path, created_at)
        VALUES ('/bukety/buket-nezhnost/', '/bukety/', '2026-10-01T10:00:00+05:00')");
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    t_equal(t_redirects($db), [], 'адрес снова живой — переадресации с него нет');
});
