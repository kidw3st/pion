<?php
/**
 * База каталога: схема и её версия, транзакции, время по Перми, журнал.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';

/** Версия схемы, записанная в самой базе. */
function t_schema_version(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

/** Есть ли в базе таблица. */
function t_has_table(PDO $db, string $name): bool
{
    $q = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $q->execute([$name]);
    return (int)$q->fetchColumn() === 1;
}

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

t_case('версия схемы', function (): void {
    $file = t_tmpdir() . '/catalog.sqlite';
    $db = catalog_db_open($file);
    t_equal(t_schema_version($db), 1, 'новая база помечена версией схемы 1');
    $db->exec("INSERT INTO meta (key, value) VALUES ('probe', '1')");
    $db = null;
    $again = catalog_db_open($file);
    t_equal(t_schema_version($again), 1, 'повторное открытие версию не меняет');
    t_equal(catalog_meta($again), ['probe' => '1'], 'и данные не трогает');
});

t_case('база без отметки версии', function (): void {
    $file = t_tmpdir() . '/catalog.sqlite';
    $old = catalog_db_open($file);
    $old->exec("INSERT INTO meta (key, value) VALUES ('probe', '1')");
    // Как база, созданная до учёта версий: таблицы и данные есть, отметки нет.
    $old->exec('PRAGMA user_version = 0');
    $old = null;
    $db = catalog_db_open($file);
    t_equal(t_schema_version($db), 1, 'такая база — схема версии 1, отметка ставится');
    t_equal(catalog_meta($db), ['probe' => '1'], 'данные на месте');
});

t_case('база новее кода', function (): void {
    $file = t_tmpdir() . '/catalog.sqlite';
    // База от более нового кода: версия больше известной и таблица, которой этот код не знает.
    $raw = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $raw->exec('CREATE TABLE future (id INTEGER PRIMARY KEY)');
    $raw->exec('PRAGMA user_version = 99');
    $raw = null;
    $e = t_throws(fn () => catalog_db_open($file), RuntimeException::class, 'база новее кода — отказ работать с ней');
    t_true(!($e instanceof CatalogError), 'это не ошибка в данных: сотруднику такой текст не показывают');
    $raw = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_equal(
        $raw->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN),
        ['future'],
        'отказ базу не трогает: таблиц каталога в чужой базе не создано',
    );
    t_equal(t_schema_version($raw), 99, 'версию тоже');
});

t_case('миграции схемы', function (): void {
    $db = t_catalog_db();
    $db->exec("INSERT INTO meta (key, value) VALUES ('probe', '1')");
    // Шаги перечислены не по порядку: применяться должны по номерам — третий опирается на второй.
    $steps = [
        3 => ['INSERT INTO extra (id) VALUES (3)'],
        2 => ["ALTER TABLE meta ADD COLUMN note TEXT NOT NULL DEFAULT 'v2'", 'CREATE TABLE extra (id INTEGER PRIMARY KEY)'],
    ];
    catalog_db_migrate($db, 3, $steps);
    t_equal(t_schema_version($db), 3, 'база переведена на последнюю версию');
    t_equal(
        $db->query('SELECT key, value, note FROM meta')->fetchAll(),
        [['key' => 'probe', 'value' => '1', 'note' => 'v2']],
        'шаги применены, данные целы',
    );
    t_equal(array_map('intval', $db->query('SELECT id FROM extra')->fetchAll(PDO::FETCH_COLUMN)), [3], 'шаги шли по номерам версий');
    catalog_db_migrate($db, 3, $steps);
    t_equal(t_schema_version($db), 3, 'база уже на нужной версии — шаги второй раз не применяются (иначе «колонка уже есть»)');

    $broken = [4 => ['CREATE TABLE half (id INTEGER)', 'ЭТО НЕ SQL']];
    t_throws(fn () => catalog_db_migrate($db, 4, $broken), PDOException::class, 'ошибка в шаге выходит наружу');
    t_equal(t_schema_version($db), 3, 'версия не сдвинулась');
    t_equal(t_has_table($db, 'half'), false, 'и первая команда шага откатилась вместе со всем шагом');

    // Без отметки (0) — схема версии 1: шаги идут со второго, первый не повторяется.
    $legacy = t_catalog_db();
    $legacy->exec('PRAGMA user_version = 0');
    catalog_db_migrate($legacy, 2, [1 => ['CREATE TABLE never (id INTEGER)'], 2 => ['CREATE TABLE once (id INTEGER)']]);
    t_equal([t_has_table($legacy, 'never'), t_has_table($legacy, 'once'), t_schema_version($legacy)], [false, true, 2],
        'база без отметки получает только шаги после первой версии');
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

t_case('откат не заслоняет ошибку', function (): void {
    $db = t_catalog_db();
    // Функция сама завершила транзакцию, а потом упала: откатывать уже нечего.
    $e = t_throws(function () use ($db): void {
        catalog_tx($db, function () use ($db): void {
            $db->exec("INSERT INTO meta (key, value) VALUES ('a', '1')");
            $db->exec('ROLLBACK');
            throw new CatalogError('исходная ошибка');
        });
    }, CatalogError::class, 'наружу выходит исходная ошибка, а не «нет транзакции»');
    t_equal($e?->getMessage(), 'исходная ошибка', 'и с прежним текстом');
    t_equal(catalog_meta($db), [], 'ничего не записано');
    t_equal(catalog_tx($db, fn () => 7), 7, 'после этого база работает: новая транзакция открывается');
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
