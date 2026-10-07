<?php
/**
 * База каталога: один SQLite-файл вне веб-корня, рядом с pion-deploy —
 * /var/www/u3620798/data/pion-catalog/catalog.sqlite. Через сайт его не
 * скачать. Админка меняет базу, pay/catalog-export.php отдаёт из неё
 * выгрузку, по которой GitHub Actions собирает сайт.
 *
 * Здесь — открытие базы со схемой и её версией, транзакции, время и журнал.
 * Правила букетов и разделов — в соседних файлах.
 */

declare(strict_types=1);

/** Часовой пояс салона: Пермь, UTC+5, без перехода на летнее время. */
const CATALOG_TZ = 'Asia/Yekaterinburg';

/** Ошибка в данных; текст можно показать сотруднику как есть. */
class CatalogError extends RuntimeException
{
}

/** Букет успел сохранить кто-то другой, пока его редактировали. */
class CatalogConflict extends RuntimeException
{
}

/**
 * Таблицы — по спецификации (раздел «База каталога»). Плитки сетки — и
 * разделов, и постоянных («Цветы», «Создать уникальный букет») — лежат в
 * одной таблице tiles: так порядок всей сетки задаёт одна колонка position.
 * Плитка есть у каждого раздела, видна она или нет — решает sections.visible.
 *
 * Это схема версии 1, и она не правится: любое изменение таблиц — шагом в
 * CATALOG_MIGRATIONS (там же, почему).
 */
const CATALOG_SCHEMA = [
    "CREATE TABLE IF NOT EXISTS meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS sections (
        slug TEXT PRIMARY KEY,
        label TEXT NOT NULL,
        tile_image TEXT NOT NULL DEFAULT '',
        visible INTEGER NOT NULL DEFAULT 1,
        cover_title TEXT NOT NULL DEFAULT '',
        cover_sub TEXT NOT NULL DEFAULT '',
        covers TEXT NOT NULL DEFAULT '[]',
        heading TEXT NOT NULL DEFAULT '',
        heading_sub TEXT NOT NULL DEFAULT '',
        has_not_found INTEGER NOT NULL DEFAULT 1,
        seo_title TEXT,
        seo_description TEXT,
        updated_at TEXT NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS tiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        position INTEGER NOT NULL,
        type TEXT NOT NULL CHECK (type IN ('section', 'link', 'popup')),
        section TEXT UNIQUE REFERENCES sections(slug),
        label TEXT NOT NULL DEFAULT '',
        href TEXT NOT NULL DEFAULT '',
        image TEXT NOT NULL DEFAULT ''
    )",
    "CREATE TABLE IF NOT EXISTS products (
        uid TEXT PRIMARY KEY,
        slug TEXT NOT NULL,
        slug_pinned INTEGER NOT NULL DEFAULT 0,
        title TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        price INTEGER NOT NULL,
        images TEXT NOT NULL DEFAULT '[]',
        main_section TEXT NOT NULL REFERENCES sections(slug),
        status TEXT NOT NULL CHECK (status IN ('draft', 'active', 'hidden', 'deleted')),
        status_before_delete TEXT,
        status_changed_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        updated_by TEXT NOT NULL DEFAULT '',
        published_at TEXT,
        deleted_at TEXT,
        version INTEGER NOT NULL DEFAULT 1
    )",
    "CREATE TABLE IF NOT EXISTS product_sections (
        uid TEXT NOT NULL REFERENCES products(uid) ON DELETE CASCADE,
        section TEXT NOT NULL REFERENCES sections(slug),
        position INTEGER NOT NULL,
        PRIMARY KEY (uid, section)
    )",
    "CREATE TABLE IF NOT EXISTS redirects (
        from_path TEXT PRIMARY KEY,
        to_path TEXT NOT NULL,
        created_at TEXT NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS users (
        login TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        must_change INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at TEXT NOT NULL,
        login TEXT NOT NULL,
        object_type TEXT NOT NULL,
        object_id TEXT NOT NULL,
        field TEXT NOT NULL,
        old_value TEXT,
        new_value TEXT
    )",
    "CREATE INDEX IF NOT EXISTS audit_object ON audit (object_type, object_id)",
    // Попытки входа — для блокировки подбора пароля (вход — план 2Б).
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        login TEXT NOT NULL,
        at INTEGER NOT NULL
    )",
];

/**
 * Версия схемы, которую знает этот код. Она записана в самой базе (PRAGMA
 * user_version) и растёт вместе с каждым шагом в CATALOG_MIGRATIONS.
 */
const CATALOG_SCHEMA_VERSION = 1;

/**
 * Изменения схемы после версии 1: номер версии => SQL-команды, которые
 * переводят базу на неё с предыдущей. Команды идут по порядку, весь шаг — в
 * одной транзакции вместе с отметкой версии: упала команда — не применилось
 * ничего. Пример: 2 => ['ALTER TABLE products ADD COLUMN note TEXT'].
 *
 * Зачем так: CREATE TABLE IF NOT EXISTS готовую таблицу не меняет. Колонка,
 * добавленная только в CATALOG_SCHEMA, появилась бы в свежей базе — и проверки
 * прошли бы, — а в боевой не появилась бы вовсе («no such column»). Поэтому
 * CATALOG_SCHEMA остаётся схемой версии 1, а свежая база проходит те же шаги,
 * что и боевая: проверки видят их все.
 */
const CATALOG_MIGRATIONS = [];

/** Папка базы: pion-catalog рядом с pion-deploy, вне веб-корня. */
function catalog_home(): string
{
    $env = getenv('PION_CATALOG_HOME');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    // pay/catalog → pay → pionperm.ru → www → data
    return dirname(__DIR__, 4) . '/pion-catalog';
}

function catalog_db_path(): string
{
    return catalog_home() . '/catalog.sqlite';
}

/**
 * Открывает базу (создаёт её и папку, если их нет). Недостающие таблицы
 * создаёт по CATALOG_SCHEMA — это схема версии 1, — затем переводит базу
 * на CATALOG_SCHEMA_VERSION шагами CATALOG_MIGRATIONS. База новее кода —
 * RuntimeException: что в ней изменилось, этот код не знает.
 */
function catalog_db_open(string $file): PDO
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку базы: $dir");
    }
    $db = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    // Два сотрудника сохраняют одновременно — второй подождёт, а не получит ошибку.
    $db->exec('PRAGMA busy_timeout = 5000');
    // Отказ — раньше любого CREATE: в чужой, более новой схеме старый код ничего не создаёт и не пишет.
    $version = catalog_db_version($db);
    if ($version > CATALOG_SCHEMA_VERSION) {
        throw new RuntimeException("База каталога новее кода: версия схемы $version, а код знает только версию "
            . CATALOG_SCHEMA_VERSION . '. Обновите код на сервере.');
    }
    foreach (CATALOG_SCHEMA as $sql) {
        $db->exec($sql);
    }
    catalog_db_migrate($db);
    return $db;
}

/** Версия схемы, записанная в базе. 0 — отметки нет: база новая или заведена до учёта версий. */
function catalog_db_version(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

/**
 * Переводит базу на версию $target шагами $migrations — по порядку номеров,
 * все в одной транзакции вместе с отметкой версии. Версия 0 — это схема 1:
 * таблицы такой базы только что созданы по CATALOG_SCHEMA, её шаги начинаются
 * со второго. База, которая уже на нужной версии (или новее), не меняется и
 * блокировку записи не берёт: сюда заходит каждое открытие базы.
 *
 * Параметры нужны проверкам: настоящие шаги и версия — константы выше.
 *
 * @param array<int, list<string>> $migrations
 */
function catalog_db_migrate(PDO $db, int $target = CATALOG_SCHEMA_VERSION, array $migrations = CATALOG_MIGRATIONS): void
{
    if (catalog_db_version($db) >= $target) {
        return;
    }
    catalog_tx($db, function () use ($db, $target, $migrations): void {
        // Версию читаем уже под блокировкой: пока ждали её, другой процесс мог сам перевести базу.
        $version = catalog_db_version($db);
        if ($version >= $target) {
            return;
        }
        for ($step = max($version, 1) + 1; $step <= $target; $step++) {
            foreach ($migrations[$step] ?? [] as $sql) {
                $db->exec($sql);
            }
        }
        $db->exec('PRAGMA user_version = ' . $target);
    });
}

/** Время в базе и выгрузке: ISO 8601 по Перми. Строки сравниваются как время — пояс у всех один. */
function catalog_iso(DateTimeImmutable $at): string
{
    return $at->setTimezone(new DateTimeZone(CATALOG_TZ))->format('Y-m-d\TH:i:sP');
}

/**
 * Сохранение — одна транзакция: или целиком, или никак. IMMEDIATE — запись
 * берётся сразу: проверка версии букета и запись идут без чужой правки между
 * ними. Функции catalog_* открывают её сами; внутри неё — только помощники.
 */
function catalog_tx(PDO $db, callable $fn): mixed
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        // SQLite мог откатить транзакцию сам, или функция её уже завершила: тогда
        // ROLLBACK ответит «нет транзакции» и заслонит настоящую ошибку. Поэтому
        // откат — отдельно, а наружу всегда уходит исходное исключение. Через
        // inTransaction() не проверить: после голого BEGIN IMMEDIATE он врёт.
        try {
            $db->exec('ROLLBACK');
        } catch (PDOException) {
            // Откатывать нечего — это и нужно.
        }
        throw $e;
    }
}

/** Строка журнала: кто, когда и что поменял — было → стало. */
function catalog_audit(
    PDO $db,
    string $login,
    DateTimeImmutable $now,
    string $type,
    string $id,
    string $field,
    ?string $old,
    ?string $new,
): void {
    $db->prepare('INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([catalog_iso($now), $login, $type, $id, $field, $old, $new]);
}

/** Служебные значения базы: version и changed_at выгрузки. */
function catalog_meta(PDO $db): array
{
    return $db->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
}
