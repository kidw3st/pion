<?php
/**
 * Загрузка выгрузки в пустую базу: «туда-обратно» и защита от порчи.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';
require_once __DIR__ . '/../../server-pay/catalog/sections.php';
require_once __DIR__ . '/../../server-pay/catalog/import.php';

/** Каталог, собранный правилами админки: всё, что выгрузка должна пережить. */
function t_rich_catalog(): PDO
{
    $db = t_catalog_with_sections();
    $now = t_now();
    $a = catalog_create_product($db, 'anna', t_fields([
        'title' => 'Букет А',
        'sections' => ['bukety', 'novinki'],
        'images' => ['/images/catalog/bukety/buket-a-1.webp'],
    ]), $now);
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), $now);
    $c = catalog_create_product($db, 'anna', t_fields(['title' => 'Роза', 'sections' => ['roses']]), $now);
    catalog_create_product($db, 'anna', t_fields(['title' => 'Черновик']), $now);
    foreach ([$a, $b, $c] as $uid) {
        catalog_publish($db, 'anna', $uid, 1, $now);
    }
    catalog_hide($db, 'anna', $b, 2, $now);
    catalog_delete($db, 'anna', $c, 2, $now);
    $slug = catalog_create_section($db, 'anna', 'Осень', $now);
    catalog_update_section($db, 'anna', $slug, ['visible' => false, 'seoTitle' => 'Осенние букеты'], $now);
    return $db;
}

t_case('туда и обратно', function (): void {
    $source = catalog_export(t_rich_catalog());
    $db = t_catalog_db();
    $counts = catalog_import($db, json_decode(json_encode($source, CATALOG_JSON), true), t_now('+1 day'));
    t_equal($counts, ['sections' => 4, 'products' => 2, 'redirects' => 1], 'загружено всё, что было в выгрузке');
    $again = catalog_export($db);
    t_equal($again['version'], $source['version'], 'выгрузка из загруженной базы — той же версии');
    unset($again['changedAt'], $source['changedAt']);
    t_equal($again, $source, 'и совпадает целиком');
    t_equal(catalog_export($db)['changedAt'], '2026-10-06T14:00:00+05:00', 'время изменения — время загрузки');
});

t_case('проверки загрузки', function (): void {
    $source = catalog_export(t_rich_catalog());
    $full = t_catalog_db();
    catalog_import($full, $source, t_now());
    t_throws(fn () => catalog_import($full, $source, t_now()), CatalogError::class, 'в непустую базу не загружается');

    $tampered = $source;
    $tampered['products'][0]['price'] = 1;
    t_throws(fn () => catalog_import(t_catalog_db(), $tampered, t_now()), CatalogError::class,
        'версия не сходится с содержимым — выгрузка испорчена, не загружается');

    $orphan = $source;
    unset($orphan['version']);
    $orphan['sections'][0]['products'] = [];
    $empty = t_catalog_db();
    t_throws(fn () => catalog_import($empty, $orphan, t_now()), CatalogError::class, 'букета нет в его главном разделе — не загружается');
    t_equal((int)$empty->query('SELECT COUNT(*) FROM sections')->fetchColumn(), 0, 'после отказа база осталась пустой');
});

t_case('файл не по форме выгрузки', function (): void {
    $source = catalog_export(t_rich_catalog());
    // Данные верные, а версия пересчитана по самому файлу — поэтому «сходится». Но база выгрузит
    // свой порядок и свои поля, и версия станет другой. Без проверки такая загрузка проходила бы,
    // а повторить её в непустую базу уже нельзя — пришлось бы удалять базу руками.
    $bent = [
        'разделы не по алфавиту' => function (array $file): array {
            $file['sections'] = array_reverse($file['sections']);
            return $file;
        },
        'лишнее поле у букета' => function (array $file): array {
            $file['products'][0]['note'] = 'лишнее';
            return $file;
        },
    ];
    foreach ($bent as $what => $bend) {
        $file = $bend($source);
        $file['version'] = catalog_export_version($file);
        $db = t_catalog_db();
        $e = t_throws(fn () => catalog_import($db, $file, t_now()), CatalogError::class, "не загружается: $what");
        t_true(str_contains((string)$e?->getMessage(), 'с другой версией'), "причина названа: $what");
        t_equal(
            [(int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn(), (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()],
            [0, 0],
            "после отказа база пуста: $what",
        );
    }
    $loaded = true;
    try {
        catalog_import($db, $source, t_now());
    } catch (CatalogError) {
        $loaded = false;
    }
    t_true($loaded, 'после отказа исправленный файл загружается — базу удалять не нужно');
});

t_case('import-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $file = t_tmpdir() . '/export.json';
    $source = catalog_export(t_rich_catalog());
    file_put_contents($file, json_encode($source, CATALOG_JSON));
    // Корень сайта скрипту задают переменной PION_WEBROOT; в нём должны лежать все фото выгрузки.
    $webroot = t_tmpdir();
    putenv('PION_WEBROOT=' . $webroot);
    try {
        [$code, $out] = t_catalog_cli("$scripts/catalog/import-cli.php", $home, [$file]);
        t_equal($code, 1, 'фото на месте нет — отказ');
        t_true(str_contains($out, 'не найдены'), 'причина названа: фото не найдены');
        $paths = [];
        foreach ($source['products'] as $p) {
            $paths = array_merge($paths, $p['images']);
        }
        foreach ($source['sections'] as $s) {
            $paths = array_merge($paths, $s['tileImage'] !== '' ? [$s['tileImage']] : [], $s['covers']);
        }
        foreach ($source['tiles'] as $t) {
            if (isset($t['image'])) {
                $paths[] = $t['image'];
            }
        }
        $unique = count(array_unique($paths));
        t_true(str_contains($out, "Фото не найдены на сервере: $unique (искали в $webroot)"),
            'каждое фото считается один раз, и названа папка, где искали');
        t_true(!is_file("$home/catalog.sqlite"), 'неудачная загрузка базу не оставляет — админка по-прежнему отвечает 503');
        t_true(!is_file("$home/catalog.sqlite" . '.import'), 'и временный файл убран');
        foreach ($source['products'] as $p) {
            foreach ($p['images'] as $path) {
                t_put_files($webroot, [ltrim($path, '/') => 'webp']);
            }
        }
        foreach ($source['sections'] as $s) {
            foreach (array_merge($s['tileImage'] !== '' ? [$s['tileImage']] : [], $s['covers']) as $path) {
                t_put_files($webroot, [ltrim($path, '/') => 'webp']);
            }
        }
        foreach ($source['tiles'] as $t) {
            if (isset($t['image'])) {
                t_put_files($webroot, [ltrim($t['image'], '/') => 'webp']);
            }
        }
        [$code, $out] = t_catalog_cli("$scripts/catalog/import-cli.php", $home, [$file]);
        t_equal($code, 0, 'загрузка прошла');
        t_true(is_file("$home/catalog.sqlite"), 'после удачной загрузки база на месте');
        t_true(!is_file("$home/catalog.sqlite" . '.import'), 'временного файла нет');
        t_true(str_contains($out, 'букетов 2') && str_contains($out, $source['version']), 'печатает, сколько загружено, и версию');
        [$code] = t_catalog_cli("$scripts/catalog/import-cli.php", $home, [$file]);
        t_equal($code, 1, 'второй раз в ту же базу — отказ');
    } finally {
        putenv('PION_WEBROOT');
    }
});

function t_import_sample(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/catalog-export-small.json'), true, 64, JSON_THROW_ON_ERROR);
}

t_case('загрузка: пути фото проверяются', function (): void {
    $e = t_import_sample();
    unset($e['version']);
    $e['products'][0]['images'] = ['/images/site/new-pion.webp'];
    $err = t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'фото букета не из images/catalog — отказ');
    t_true($err !== null && str_contains($err->getMessage(), '100000000001'), 'в сообщении — какой букет');

    $e = t_import_sample();
    unset($e['version']);
    $e['products'][0]['images'] = [['/images/catalog/bukety/a.webp']];
    t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'запись не строкой — отказ');

    $e = t_import_sample();
    unset($e['version']);
    $e['sections'][0]['covers'] = ['/images/catalog/_deleted/x.webp'];
    t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'обложка из корзины — отказ');
});

t_case('загрузка: с корнем сайта — каждое фото должно лежать на месте', function (): void {
    $webroot = t_tmpdir();
    $err = t_throws(fn () => catalog_import(t_catalog_db(), t_import_sample(), t_now(), $webroot), CatalogError::class, 'фото нет — отказ');
    t_true($err !== null && str_contains($err->getMessage(), 'не найдены'), 'объяснение: фото не найдены');
    $sample = t_import_sample();
    foreach ($sample['products'] as $p) {
        foreach ($p['images'] as $path) {
            t_put_files($webroot, [ltrim($path, '/') => 'webp']);
        }
    }
    foreach ($sample['sections'] as $s) {
        foreach (array_merge($s['tileImage'] !== '' ? [$s['tileImage']] : [], $s['covers']) as $path) {
            t_put_files($webroot, [ltrim($path, '/') => 'webp']);
        }
    }
    foreach ($sample['tiles'] as $t) {
        if (isset($t['image'])) {
            t_put_files($webroot, [ltrim($t['image'], '/') => 'webp']);
        }
    }
    t_equal(catalog_import(t_catalog_db(), $sample, t_now(), $webroot)['products'], 5, 'все на месте — загружено');
});

t_case('загрузка: одно фото у двух букетов считается один раз', function (): void {
    $export = t_import_sample();
    $shared = $export['products'][0]['images'][0];
    $export['products'][1]['images'] = [$shared];
    $webroot = t_tmpdir();
    $all = [];
    foreach ($export['products'] as $p) {
        $all = array_merge($all, $p['images']);
    }
    foreach ($export['sections'] as $s) {
        $all = array_merge($all, $s['tileImage'] !== '' ? [$s['tileImage']] : [], $s['covers']);
    }
    foreach ($export['tiles'] as $t) {
        if (isset($t['image'])) {
            $all[] = $t['image'];
        }
    }
    $expected = count(array_unique($all));
    try {
        catalog_import(t_catalog_db(), $export, t_now(), $webroot);
        t_true(false, 'без фото загрузка должна отказать');
    } catch (CatalogError $e) {
        t_true(str_contains($e->getMessage(), "Фото не найдены на сервере: $expected (искали в $webroot)"), 'общее фото — один раз: ' . $e->getMessage());
    }
});
