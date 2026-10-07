<?php
/**
 * Выгрузка каталога: формат из спецификации (раздел 3), версия, changedAt и
 * сам pay/catalog-export.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/export.php';

/** В «Букетах» — снятый, черновик и букет в продаже (он же в «Новинках»), в «Розах» — удалённый. */
function t_export_catalog(?PDO $db = null): PDO
{
    $db = t_catalog_with_sections($db);
    t_put_product($db, '100000000001', 'active', 'bukety', ['bukety' => 2, 'novinki' => 0]);
    t_put_product($db, '100000000002', 'hidden', 'bukety', ['bukety' => 1]);
    t_put_product($db, '100000000003', 'draft', 'bukety', ['bukety' => 0]);
    t_put_product($db, '100000000004', 'deleted', 'roses', ['roses' => 0]);
    $db->exec("INSERT INTO redirects (from_path, to_path, created_at)
        VALUES ('/roses/buket-100000000004/', '/roses/', '2026-10-01T10:00:00+05:00')");
    return $db;
}

t_case('формат выгрузки', function (): void {
    $data = catalog_export_data(t_export_catalog());
    t_equal(array_keys($data), ['sections', 'tiles', 'products', 'redirects'], 'четыре части выгрузки');
    t_equal(array_column($data['sections'], 'slug'), ['bukety', 'novinki', 'roses'], 'разделы — все, и скрытые тоже, по slug');
    t_equal($data['sections'][0], [
        'slug' => 'bukety', 'label' => 'Букеты', 'tileImage' => '', 'visible' => true,
        'coverTitle' => 'Букеты', 'coverSub' => '', 'covers' => [], 'heading' => 'БУКЕТЫ', 'headingSub' => '',
        'hasNotFound' => true, 'seoTitle' => null, 'seoDescription' => null,
        'products' => ['100000000002', '100000000001'],
    ], 'раздел — все поля спецификации; букеты по местам, снятый на своём месте, черновика нет');
    t_equal($data['sections'][1]['products'], ['100000000001'], 'букет из двух разделов есть в обоих');
    t_equal($data['sections'][1]['visible'], false, 'скрытый раздел помечен');
    t_equal($data['sections'][2]['products'], [], 'удалённого в разделе нет');
    t_equal($data['tiles'], [
        ['type' => 'link', 'label' => 'Цветы', 'href' => '/flowers', 'image' => '/images/site/catalog-tiles/tile-0.webp'],
        ['type' => 'section', 'slug' => 'bukety'],
        ['type' => 'section', 'slug' => 'roses'],
    ], 'плитки — в порядке сетки, без скрытого раздела');
    t_equal(array_column($data['products'], 'uid'), ['100000000001', '100000000002'], 'букеты — в продаже и снятые, без черновиков и удалённых');
    t_equal($data['products'][0], [
        'uid' => '100000000001', 'slug' => 'buket-100000000001', 'title' => 'Букет 100000000001',
        'description' => 'Розы, эвкалипт', 'price' => 4400,
        'images' => ['/images/catalog/bukety/buket-100000000001.webp'],
        'mainSection' => 'bukety', 'status' => 'active',
    ], 'букет — все поля спецификации');
    t_equal($data['products'][1]['status'], 'hidden', 'снятый помечен');
    t_equal($data['redirects'], [['from' => '/roses/buket-100000000004/', 'to' => '/roses/']], 'переадресации');
});

t_case('версия', function (): void {
    $db = t_export_catalog();
    $version = catalog_export_version(catalog_export_data($db));
    t_true((bool)preg_match('/^[0-9a-f]{64}$/', $version), 'версия — sha256');
    t_equal(catalog_export_version(catalog_export_data($db)), $version, 'тот же каталог — та же версия');
    t_equal(
        catalog_export_version(['version' => 'x', 'changedAt' => 'y'] + catalog_export_data($db)),
        $version,
        'version и changedAt на версию не влияют',
    );
    $db->exec("UPDATE products SET price = 4800 WHERE uid = '100000000001'");
    $changed = catalog_export_version(catalog_export_data($db));
    t_true($changed !== $version, 'новая цена — новая версия');
    $db->exec("UPDATE products SET price = 9999 WHERE uid = '100000000003'");
    t_equal(catalog_export_version(catalog_export_data($db)), $changed, 'правка черновика версию не меняет');
});

t_case('changedAt', function (): void {
    $db = t_export_catalog();
    t_equal(catalog_export($db)['changedAt'], null, 'пока ничего не сохраняли — времени изменения нет');
    t_equal(
        array_keys(catalog_export($db)),
        ['version', 'changedAt', 'sections', 'tiles', 'products', 'redirects'],
        'порядок частей выгрузки',
    );
    catalog_touch($db, t_now());
    t_equal(catalog_export($db)['changedAt'], '2026-10-05T14:00:00+05:00', 'первое сохранение ставит время');
    catalog_touch($db, t_now('+1 hour'));
    t_equal(catalog_export($db)['changedAt'], '2026-10-05T14:00:00+05:00', 'сохранение без изменений в выгрузке время не трогает');
    $db->exec("UPDATE products SET price = 4800 WHERE uid = '100000000001'");
    catalog_touch($db, t_now('+2 hours'));
    t_equal(catalog_export($db)['changedAt'], '2026-10-05T16:00:00+05:00', 'изменение выгрузки — новое время');
    t_equal(catalog_meta($db)['version'], catalog_export($db)['version'], 'в базе запомнена версия выгрузки');
});

t_case('catalog-export.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog-export.php", $home);
    t_equal([$code, json_decode($out, true)], [0, ['error' => 'Каталог ещё не создан']], 'базы нет — понятная ошибка');
    t_equal(is_file("$home/catalog.sqlite"), false, 'запрос выгрузки базу не создаёт');

    $db = t_catalog_db("$home/catalog.sqlite");
    [, $out] = t_catalog_cli("$scripts/catalog-export.php", $home);
    t_equal(json_decode($out, true), ['error' => 'Каталог ещё не создан'], 'пустая база (только учётные записи) — тоже «не создан»');

    t_export_catalog($db);
    catalog_touch($db, t_now());
    [$code, $out] = t_catalog_cli("$scripts/catalog-export.php", $home);
    t_equal($code, 0, 'выгрузка отдаётся');
    t_equal(json_decode($out, true), catalog_export($db), 'отдаёт ровно catalog_export()');
});
