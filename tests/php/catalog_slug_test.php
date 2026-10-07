<?php
/**
 * slugify в PHP даёт ровно то же, что в JS, — на названиях всего каталога.
 * Иначе админка построила бы перенесённому букету другой адрес.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/slug.php';

t_case('slugify как в JS', function (): void {
    $pairs = json_decode((string)file_get_contents(__DIR__ . '/fixtures/slugs.json'), true);
    t_true(is_array($pairs) && count($pairs) > 400, 'образец названий на месте');
    $wrong = [];
    foreach ($pairs as ['title' => $title, 'slug' => $slug]) {
        $got = catalog_slugify($title);
        if ($got !== $slug) {
            $wrong[] = "$title → $got (ждали $slug)";
        }
    }
    t_equal(array_slice($wrong, 0, 5), [], 'на всех названиях каталога PHP и JS совпадают');
    t_equal(catalog_slugify('Букет «Бархатные грани»'), 'buket-barhatnye-grani', 'букет из каталога');
    t_equal(catalog_slugify('!!!'), 'tovar', 'пустой slug заменяется на tovar');
});
