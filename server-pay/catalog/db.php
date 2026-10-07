<?php
/**
 * База каталога: один SQLite-файл вне веб-корня, рядом с pion-deploy —
 * /var/www/u3620798/data/pion-catalog/catalog.sqlite. Через сайт его не
 * скачать. Админка меняет базу, pay/catalog-export.php отдаёт из неё
 * выгрузку, по которой GitHub Actions собирает сайт.
 *
 * Здесь — открытие базы со схемой, транзакции, время и журнал. Правила
 * букетов и разделов — в соседних файлах.
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

/** Открывает базу (создаёт её и папку, если их нет) и приводит схему к текущей. */
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
    foreach (CATALOG_SCHEMA as $sql) {
        $db->exec($sql);
    }
    return $db;
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
