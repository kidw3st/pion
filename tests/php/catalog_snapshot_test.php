<?php
/**
 * Снимок каталога data/catalog-export.json (его читает сайт) годится и для
 * базы админки: версия на PHP та же, загрузка в пустую базу проходит, база
 * выгружает его обратно слово в слово. Это вторая проверка переноса из
 * спецификации — заранее, до этапа 3.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/import.php';

t_case('снимок каталога: база загружает и выгружает его тем же', function (): void {
    $e = json_decode((string)file_get_contents(__DIR__ . '/../../data/catalog-export.json'), true, 64, JSON_THROW_ON_ERROR);
    t_equal(catalog_export_version($e), $e['version'], 'версия на PHP та же, что посчитал JS');
    $db = t_catalog_db();
    $r = catalog_import($db, $e, t_now());
    t_equal([$r['sections'], $r['products']], [13, 487], 'загружены все разделы и букеты');
    $want = ['sections' => $e['sections'], 'tiles' => $e['tiles'], 'products' => $e['products'], 'redirects' => $e['redirects']];
    t_true(json_encode(catalog_export_data($db), CATALOG_JSON) === json_encode($want, CATALOG_JSON), 'выгрузка из базы — тот же JSON');
});
