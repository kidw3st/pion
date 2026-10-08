<?php
/**
 * Выгрузка, собранная на JS (образец tests/php/fixtures/catalog-export-small.json),
 * для PHP та же самая: версия совпадает, база загружает её и выгружает обратно
 * слово в слово. Тот же файл проверяет vitest (scripts/lib/catalog-export.test.mjs),
 * поэтому канонический JSON двух сторон не разъедется.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/import.php';

function t_small_export(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/catalog-export-small.json'), true, 64, JSON_THROW_ON_ERROR);
}

t_case('выгрузка-образец: версия на PHP та же, что на JS', function (): void {
    $e = t_small_export();
    t_equal(catalog_export_version($e), '4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc', 'тот же sha256, что в vitest');
    t_equal($e['version'], catalog_export_version($e), 'версия в файле сходится с содержимым');
});

t_case('выгрузка-образец: база загружает и выгружает её обратно', function (): void {
    $e = t_small_export();
    $db = t_catalog_db();
    catalog_import($db, $e, t_now());
    $want = ['sections' => $e['sections'], 'tiles' => $e['tiles'], 'products' => $e['products'], 'redirects' => $e['redirects']];
    t_equal(json_encode(catalog_export_data($db), CATALOG_JSON), json_encode($want, CATALOG_JSON), 'выгрузка из базы — тот же JSON');
});
