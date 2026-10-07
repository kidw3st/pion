<?php
/**
 * Разделы: создание, карточка раздела, порядок плиток.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/sections.php';

t_case('новый раздел', function (): void {
    $db = t_catalog_with_sections();
    $slug = catalog_create_section($db, 'anna', 'Осенняя коллекция', t_now());
    t_equal($slug, 'osennyaya-kollektsiya', 'slug из названия');
    $data = catalog_export_data($db);
    $section = array_values(array_filter($data['sections'], fn (array $s): bool => $s['slug'] === $slug))[0];
    t_equal(
        [$section['label'], $section['visible'], $section['coverTitle'], $section['heading'], $section['products']],
        ['Осенняя коллекция', true, 'Осенняя коллекция', 'ОСЕННЯЯ КОЛЛЕКЦИЯ', []],
        'раздел с плиткой; обложка и заголовок — из названия',
    );
    t_equal($data['tiles'][count($data['tiles']) - 1], ['type' => 'section', 'slug' => $slug], 'плитка — в конце сетки');
});

t_case('занятые адреса', function (): void {
    $db = t_catalog_with_sections();
    t_equal(catalog_create_section($db, 'anna', 'Букеты', t_now()), 'bukety-2', 'такой раздел уже есть — -2');
    t_equal(catalog_create_section($db, 'anna', 'Блог', t_now()), 'blog-2', 'адрес страницы сайта раздел не займёт');
    t_throws(fn () => catalog_create_section($db, 'anna', '  ', t_now()), CatalogError::class, 'без названия раздел не создать');
});

t_case('карточка раздела', function (): void {
    $db = t_catalog_with_sections();
    catalog_update_section($db, 'anna', 'roses', [
        'label' => 'Розы поштучно',
        'visible' => false,
        'seoTitle' => '  ',
        'covers' => ['/images/site/category-covers/roses-0.webp'],
    ], t_now());
    $roses = catalog_export_data($db)['sections'][2];
    t_equal(
        [$roses['label'], $roses['visible'], $roses['seoTitle'], $roses['covers']],
        ['Розы поштучно', false, null, ['/images/site/category-covers/roses-0.webp']],
        'поля сохранены; пустой SEO-заголовок — значит, по шаблону',
    );
    t_equal(array_column(catalog_export_data($db)['tiles'], 'slug'), ['bukety'], 'скрытый раздел пропал из сетки');
    t_equal(
        $db->query("SELECT field FROM audit WHERE object_type = 'section' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        ['label', 'visible', 'covers'],
        'в журнале — изменённые поля',
    );
    $bad = [
        'пустое название' => ['label' => ''],
        'четыре фото обложки' => ['covers' => array_fill(0, 4, '/images/site/a.webp')],
        'фото не с сайта' => ['tileImage' => 'http://evil.example/x.webp'],
    ];
    foreach ($bad as $what => $fields) {
        t_throws(fn () => catalog_update_section($db, 'anna', 'roses', $fields, t_now()), CatalogError::class, "не сохраняется: $what");
    }
    t_throws(fn () => catalog_update_section($db, 'anna', 'nope', ['label' => 'Х'], t_now()), CatalogError::class, 'нет такого раздела');
});

t_case('порядок плиток', function (): void {
    $db = t_catalog_with_sections();
    $ids = array_map('intval', $db->query('SELECT id FROM tiles ORDER BY position')->fetchAll(PDO::FETCH_COLUMN));
    catalog_reorder_tiles($db, 'anna', array_reverse($ids), t_now());
    t_equal(catalog_export_data($db)['tiles'], [
        ['type' => 'section', 'slug' => 'roses'],
        ['type' => 'section', 'slug' => 'bukety'],
        ['type' => 'link', 'label' => 'Цветы', 'href' => '/flowers', 'image' => '/images/site/catalog-tiles/tile-0.webp'],
    ], 'сетка в новом порядке');
    t_throws(
        fn () => catalog_reorder_tiles($db, 'anna', array_slice($ids, 1), t_now()),
        CatalogError::class,
        'порядок без одной плитки не принимается',
    );
});
