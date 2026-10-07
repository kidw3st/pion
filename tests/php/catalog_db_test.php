<?php
/**
 * База каталога: схема, транзакции, время по Перми, журнал.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';

t_case('схема', function (): void {
    $db = t_catalog_db();
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    t_equal(
        $tables,
        ['audit', 'login_attempts', 'meta', 'product_sections', 'products', 'redirects', 'sections', 'tiles', 'users'],
        'база создаётся со всеми таблицами спецификации',
    );
    t_throws(
        fn () => $db->exec("INSERT INTO product_sections (uid, section, position) VALUES ('1', 'nope', 0)"),
        PDOException::class,
        'внешние ключи включены: участие в разделе без букета и раздела не записать',
    );
});

t_case('повторное открытие', function (): void {
    $file = t_tmpdir() . '/nested/dir/catalog.sqlite';
    $first = catalog_db_open($file);
    $first->exec("INSERT INTO meta (key, value) VALUES ('probe', '1')");
    $first = null;
    t_equal(catalog_meta(catalog_db_open($file)), ['probe' => '1'], 'папка создаётся, повторное открытие данные не теряет');
});

t_case('время по Перми', function (): void {
    t_equal(
        catalog_iso(new DateTimeImmutable('2026-10-05T09:32:10Z')),
        '2026-10-05T14:32:10+05:00',
        'UTC переводится в пермское время',
    );
});

t_case('транзакция', function (): void {
    $db = t_catalog_db();
    t_throws(function () use ($db): void {
        catalog_tx($db, function () use ($db): void {
            $db->exec("INSERT INTO meta (key, value) VALUES ('a', '1')");
            throw new CatalogError('стоп');
        });
    }, CatalogError::class, 'ошибка внутри транзакции выходит наружу');
    t_equal(catalog_meta($db), [], 'после ошибки ничего не записано');
    t_equal(catalog_tx($db, fn () => 42), 42, 'транзакция возвращает результат функции');
});

t_case('журнал', function (): void {
    $db = t_catalog_db();
    catalog_audit($db, 'anna', t_now(), 'product', '553645466981', 'price', '4400', '4800');
    t_equal(
        $db->query('SELECT at, login, object_type, object_id, field, old_value, new_value FROM audit')->fetchAll(),
        [[
            'at' => '2026-10-05T14:00:00+05:00', 'login' => 'anna', 'object_type' => 'product',
            'object_id' => '553645466981', 'field' => 'price', 'old_value' => '4400', 'new_value' => '4800',
        ]],
        'строка журнала: кто, когда, что — было → стало',
    );
});

t_case('папка базы', function (): void {
    putenv('PION_CATALOG_HOME=/srv/test-catalog/');
    try {
        t_equal(catalog_db_path(), '/srv/test-catalog/catalog.sqlite', 'PION_CATALOG_HOME задаёт папку базы');
    } finally {
        putenv('PION_CATALOG_HOME');
    }
});
