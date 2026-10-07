# Каталог на сервере: база, правила, выгрузка — план (этап 2А)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** База каталога (SQLite) на сервере со всеми правилами данных из спецификации, выгрузка `/pay/catalog-export.php`, загрузка выгрузки в базу и консольные скрипты для учётных записей и копий — основа, на которую план 2Б поставит экраны админки.

**Architecture:** Чистый PHP 8.2 без composer, как весь `/pay/`. Библиотека — в `server-pay/catalog/`, папка закрыта от веба своим `.htaccess`; наружу смотрит только `server-pay/catalog-export.php`. Каждое изменение каталога — одна транзакция SQLite (`BEGIN IMMEDIATE`); в её конце `catalog_touch()` пересчитывает версию выгрузки и, если она изменилась, запоминает время изменения (`changedAt`) — по нему админка покажет «ждёт выкладки», а GitHub Actions поймёт, что пора собирать сайт. Сайт и платёжная часть в этом плане не меняются.

**Tech Stack:** PHP 8.2 (`pdo_sqlite`; SQLite ≥ 3.27 для `VACUUM INTO`), самописные проверки `tests/php/run.php`, vitest для общего `slugify` на стороне Node.

Спецификация: `docs/superpowers/specs/2026-10-05-catalog-admin-design.md` — разделы «Админка» (правила букетов), «База каталога», «Выгрузка каталога», «Безопасность», «Журнал, копии, сбои». Проба 2026-10-07: машины GitHub Actions достают `pionperm.ru` (главная, `/api/showcase.json`, `/pay/…`) — схема «GitHub сам забирает выгрузку» рабочая.

## Global Constraints

- На хостинге консольный PHP 8.2 (8.2.33), сайт — PHP 8.3; код должен работать на 8.2. В каждом PHP-файле `declare(strict_types=1);`.
- Без composer и сторонних библиотек.
- Локальный PHP: `/c/php82/php.exe` (в PATH его нет). PHP-проверки: `/c/php82/php.exe tests/php/run.php`. JS-проверки: `npx vitest run <файл>`.
- Работа — в ветке `catalog-server`: `master` — это боевой сайт. В `master` — только после финальной проверки.
- Коммиты — только так (глобальной git-личности на машине нет):
  `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit …`
  и в конце сообщения строка `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- База — вне веб-корня: `/var/www/u3620798/data/pion-catalog/catalog.sqlite`. В проверках папку задаёт переменная окружения `PION_CATALOG_HOME`.
- Лимиты из спецификации: название ≤ 120 знаков, состав ≤ 1000, цена — целое число 100…300 000 ₽, фото ≤ 4, восстановление удалённого — 90 дней, копии базы — 30 дней.
- Тексты ошибок (`CatalogError`) — по-русски, для сотрудника салона, без технических слов.
- Комментарии в коде — по-русски, в духе остального `server-pay/` (объясняют «почему»).
- Проверки каталога: каждый случай — внутри `t_case('…', function (): void { … });`, чтобы база закрывалась после случая (иначе Windows не даст удалить временную папку в конце прогона).

---

## Файлы

| Файл | За что отвечает |
|---|---|
| `server-pay/catalog/.htaccess` | Закрывает папку от веба целиком |
| `server-pay/catalog/db.php` | Папка и открытие базы, схема, транзакции, время по Перми, журнал, `meta` |
| `server-pay/catalog/slug.php` | `catalog_slugify()` — как в JS; адреса, которые раздел занять не может |
| `server-pay/catalog/export.php` | Выгрузка, её версия, `catalog_touch()` |
| `server-pay/catalog/redirects.php` | Переадресации: добавить со схлопыванием цепочек, снять с живых адресов |
| `server-pay/catalog/products.php` | Правила букетов: черновик, правка, публикация, снятие, удаление, восстановление, тёзки |
| `server-pay/catalog/sections.php` | Разделы и порядок плиток |
| `server-pay/catalog/import.php` | Загрузка выгрузки в пустую базу |
| `server-pay/catalog/users.php` | Учётные записи (заводит разработчик) |
| `server-pay/catalog/backup.php` | Ежедневная копия базы |
| `server-pay/catalog/import-cli.php`, `user-cli.php`, `backup-cli.php` | Консольные обёртки для Shell-клиента ISPmanager и расписания |
| `server-pay/catalog-export.php` | Единственная точка снаружи: выгрузка для GitHub Actions |
| `scripts/lib/slugify.mjs` (+ `.test.mjs`) | Общий `slugify` для импортёров (был скопирован в двух скриптах) |
| `tests/php/catalog_fixture.php` | Общее для проверок каталога |
| `tests/php/catalog_*_test.php` | Проверки |
| `tests/php/fixtures/slugs.json` | Названия всего каталога и их slug — сверка PHP с JS |
| `docs/catalog.md` | Как этим пользоваться на сервере |

## Не входит в этот план

Чтобы не принять за пропуск — это другие планы этапа 2:

- **2Б, экраны админки:** вход, сессии, CSRF, блокировка подбора пароля (таблица `login_attempts` уже создаётся здесь); список букетов и карточка; переспрос при изменении цены больше чем вдвое; предложение «Такой букет уже был — вернуть?» (поиск тёзки — здесь, `catalog_find_namesake`); загрузка фото (уменьшение в браузере, пересохранение в WebP через GD, `images/catalog/_deleted/` для фото удалённых и их уборка через 90 дней); экран разделов; журнал; смена пароля; строка статуса выкладки.
- **2В, сайт из выгрузки:** `apply-catalog-export.mjs`, разделы из данных, страницы снятых букетов, букет в нескольких разделах, `pay/catalog-redirect.php` и правило `.htaccess` для переадресаций, «Новинки» на главной.
- **Этап 3:** перенос нынешнего каталога в базу (`make-catalog-export.mjs` + `import-cli.php`), выгрузка в `deploy.yml`, задание расписания для копий базы.

---

### Task 1: База каталога и схема

**Files:**
- Create: `server-pay/catalog/db.php`
- Create: `server-pay/catalog/.htaccess`
- Create: `tests/php/catalog_fixture.php`
- Test: `tests/php/catalog_db_test.php`

**Interfaces:**
- Consumes: `t_equal`, `t_true`, `t_throws`, `t_tmpdir` из `tests/php/run.php`.
- Produces:
  - `const CATALOG_TZ = 'Asia/Yekaterinburg'`
  - `class CatalogError extends RuntimeException` — ошибка для сотрудника; `class CatalogConflict extends RuntimeException` — одновременная правка
  - `catalog_home(): string`, `catalog_db_path(): string`, `catalog_db_open(string $file): PDO`
  - `catalog_iso(DateTimeImmutable $at): string` — `2026-10-05T14:32:10+05:00`
  - `catalog_tx(PDO $db, callable $fn): mixed`
  - `catalog_audit(PDO $db, string $login, DateTimeImmutable $now, string $type, string $id, string $field, ?string $old, ?string $new): void`
  - `catalog_meta(PDO $db): array<string, string>`
  - проверки: `t_case(string $name, callable $fn): void`, `t_now(string $shift = ''): DateTimeImmutable`, `t_catalog_db(?string $file = null): PDO`, `t_catalog_with_sections(?PDO $db = null): PDO`

- [ ] **Step 1: Ветка**

```bash
git switch master && git pull --ff-only && git switch -c catalog-server
```

- [ ] **Step 2: Общее для проверок каталога**

Create `tests/php/catalog_fixture.php`:

```php
<?php
/**
 * Общее для проверок каталога: время, временная база, разделы.
 *
 * Не *_test.php — run.php его сам не запускает, его подключают проверки.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/catalog/db.php';

/**
 * Отдельный случай проверки. Переменные случая живут только в нём: после
 * него база закрывается — иначе Windows не даст удалить временную папку.
 */
function t_case(string $name, callable $fn): void
{
    $fn();
}

/** 5 октября 2026, 14:00 по Перми; $shift — как в DateTimeImmutable::modify ('+91 days'). */
function t_now(string $shift = ''): DateTimeImmutable
{
    $now = new DateTimeImmutable('2026-10-05 14:00:00', new DateTimeZone('Asia/Yekaterinburg'));
    return $shift === '' ? $now : $now->modify($shift);
}

/** Пустая база: новая во временной папке или по пути $file. */
function t_catalog_db(?string $file = null): PDO
{
    return catalog_db_open($file ?? t_tmpdir() . '/catalog.sqlite');
}

/**
 * Разделы «Букеты» и «Розы» с плитками и «Новинки» без плитки в каталоге
 * (скрыт); первая плитка сетки — постоянная «Цветы».
 */
function t_catalog_with_sections(?PDO $db = null): PDO
{
    $db ??= t_catalog_db();
    $add = $db->prepare('INSERT INTO sections (slug, label, visible, cover_title, heading, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['bukety', 'Букеты', 1], ['roses', 'Розы', 1], ['novinki', 'Новинки', 0]] as [$slug, $label, $visible]) {
        $add->execute([$slug, $label, $visible, $label, mb_strtoupper($label), '2026-10-01T10:00:00+05:00']);
    }
    $db->exec("INSERT INTO tiles (position, type, label, href, image)
        VALUES (1, 'link', 'Цветы', '/flowers', '/images/site/catalog-tiles/tile-0.webp')");
    $db->exec("INSERT INTO tiles (position, type, section)
        VALUES (2, 'section', 'bukety'), (3, 'section', 'roses'), (4, 'section', 'novinki')");
    return $db;
}
```

- [ ] **Step 3: Проверка (пока падает)**

Create `tests/php/catalog_db_test.php`:

```php
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
```

- [ ] **Step 4: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/db.php'`.

- [ ] **Step 5: Реализация**

Create `server-pay/catalog/db.php`:

```php
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
        $db->exec('ROLLBACK');
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
```

Create `server-pay/catalog/.htaccess`:

```apache
# Библиотека и консольные скрипты каталога — наружу ничего. Выгрузку отдаёт
# pay/catalog-export.php, экраны админки — pay/admin/.
Require all denied
```

- [ ] **Step 6: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `catalog_db_test.php` без строк «ПЛОХО», в конце `не прошло: 0`.

- [ ] **Step 7: Коммит**

```bash
git add server-pay/catalog/db.php server-pay/catalog/.htaccess tests/php/catalog_fixture.php tests/php/catalog_db_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): база каталога — схема, транзакции, журнал

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: slugify в PHP — один в один с JS

**Files:**
- Create: `scripts/lib/slugify.mjs`
- Create: `scripts/lib/slugify.test.mjs`
- Modify: `scripts/import-tilda-csv.mjs` (функция `slugify` → импорт)
- Modify: `scripts/import-seasonal.mjs` (та же функция → импорт)
- Create: `tests/php/fixtures/slugs.json` (собирается командой)
- Create: `server-pay/catalog/slug.php`
- Test: `tests/php/catalog_slug_test.php`

**Interfaces:**
- Produces:
  - JS: `export function slugify(title: string): string` в `scripts/lib/slugify.mjs`
  - PHP: `const CATALOG_TRANSLIT`, `const CATALOG_RESERVED_SLUGS` (list<string>), `catalog_slugify(string $title): string`

`scripts/import-tilda-csv.mjs` и `scripts/import-seasonal.mjs` содержат одинаковые копии `slugify`; по этой функции получены адреса всех нынешних букетов. `scripts/scrape.mjs` содержит другую, старую версию — её не трогать.

- [ ] **Step 1: Общий slugify для Node**

Create `scripts/lib/slugify.mjs`:

```js
/**
 * Slug товара из названия: транслитерация, всё прочее — дефис, не длиннее
 * 60 знаков. По этой функции получены адреса всех нынешних букетов, и так же
 * их строит админка на сервере (server-pay/catalog/slug.php). Что обе дают
 * одно и то же, проверяют tests/php/fixtures/slugs.json и slugify.test.mjs.
 *
 * @param {string} title
 * @returns {string}
 */
export function slugify(title) {
  const map = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
    й: 'i', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
    у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y',
    ь: '', э: 'e', ю: 'yu', я: 'ya',
  };
  return title
    .toLowerCase()
    .split('')
    .map((ch) => map[ch] ?? ch)
    .join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60) || 'tovar';
}
```

In `scripts/import-tilda-csv.mjs` и `scripts/import-seasonal.mjs`: удалить целиком функцию `function slugify(title) { … }` (от строки `function slugify(title) {` до её закрывающей `}`) и сразу после строки `import { parseCsvRecords } from './lib/parseCsv.mjs';` добавить:

```js
import { slugify } from './lib/slugify.mjs';
```

Run: `node --check scripts/import-tilda-csv.mjs && node --check scripts/import-seasonal.mjs`
Expected: без вывода, код 0.

- [ ] **Step 2: Образец названий для сверки**

Run:

```bash
node --input-type=module -e "
import { readdirSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { slugify } from './scripts/lib/slugify.mjs';
const titles = new Set();
for (const f of readdirSync('data/catalog')) {
  for (const p of JSON.parse(readFileSync('data/catalog/' + f, 'utf8'))) titles.add(p.title);
}
for (const t of ['Ёлочка', 'Шёлк и щётка', '«»!!!', 'а'.repeat(80), 'Copy: Букет', 'Роза 60 см (25 шт.)', 'Букет 🌸 Весна']) titles.add(t);
const pairs = [...titles].sort().map((title) => ({ title, slug: slugify(title) }));
mkdirSync('tests/php/fixtures', { recursive: true });
writeFileSync('tests/php/fixtures/slugs.json', JSON.stringify(pairs, null, 1) + '\n');
console.log(pairs.length);
"
```

Expected: число около 497 (490 разных названий каталога и 7 добавленных).

- [ ] **Step 3: Проверки (PHP пока падает)**

Create `scripts/lib/slugify.test.mjs`:

```js
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { slugify } from './slugify.mjs';

describe('slugify', () => {
  it.each([
    ['Букет «Бархатные грани»', 'buket-barhatnye-grani'],
    ['Ёлочка', 'elochka'],
    ['Пион Sara Bernhardt', 'pion-sara-bernhardt'],
    ['Шёлк и щётка', 'shelk-i-schetka'],
    ['«»!!!', 'tovar'],
  ])('%s → %s', (title, slug) => {
    expect(slugify(title)).toBe(slug);
  });

  it('не длиннее 60 знаков', () => {
    expect(slugify('а'.repeat(80))).toBe('a'.repeat(60));
  });

  it('образец для PHP-сверки собран этой же функцией', () => {
    const pairs = JSON.parse(readFileSync(new URL('../../tests/php/fixtures/slugs.json', import.meta.url), 'utf8'));
    expect(pairs.length).toBeGreaterThan(400);
    for (const { title, slug } of pairs) expect(slugify(title)).toBe(slug);
  });
});
```

Create `tests/php/catalog_slug_test.php`:

```php
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
```

- [ ] **Step 4: Убедиться, что падает**

Run: `npx vitest run scripts/lib/slugify.test.mjs`
Expected: PASS (функция перенесена без изменений, образец собран ею же).

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/slug.php'`.

- [ ] **Step 5: Реализация**

Create `server-pay/catalog/slug.php`:

```php
<?php
/**
 * Slug из названия — перенос slugify из scripts/lib/slugify.mjs один в один:
 * по нему получены адреса всех нынешних букетов. Совпадение проверяется на
 * названиях всего каталога (tests/php/fixtures/slugs.json).
 */

declare(strict_types=1);

const CATALOG_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
    'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
    'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
    'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
    'я' => 'ya',
];

/**
 * Адреса, которые раздел занять не может: служебные папки сайта и его
 * страницы (PAGE_SLUGS в src/lib/content.ts).
 */
const CATALOG_RESERVED_SLUGS = [
    'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000', 'blog', 'pay', 'api', 'images', 'md',
    'fonts', '_next', 'tproduct', 'tstore', 'feed',
    'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds', 'stock', 'policy',
    'doza_endorfina', 'flowers', 'indoorflowers',
];

function catalog_slugify(string $title): string
{
    $out = '';
    foreach (mb_str_split(mb_strtolower($title, 'UTF-8'), 1, 'UTF-8') as $ch) {
        $out .= CATALOG_TRANSLIT[$ch] ?? $ch;
    }
    // Как в JS: всё, что не латиница и не цифра, — дефис; дефисы по краям
    // убираются, и только потом обрезка до 60 знаков.
    $slug = substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', $out), '-'), 0, 60);
    return $slug === '' ? 'tovar' : $slug;
}
```

- [ ] **Step 6: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php && npx vitest run scripts/lib/slugify.test.mjs`
Expected: `не прошло: 0`; vitest — все проверки PASS.

- [ ] **Step 7: Коммит**

```bash
git add scripts/lib/slugify.mjs scripts/lib/slugify.test.mjs scripts/import-tilda-csv.mjs scripts/import-seasonal.mjs tests/php/fixtures/slugs.json server-pay/catalog/slug.php tests/php/catalog_slug_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): slugify в PHP — один в один с JS, сверка на всём каталоге

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Выгрузка каталога

**Files:**
- Create: `server-pay/catalog/export.php`
- Create: `server-pay/catalog-export.php`
- Modify: `tests/php/catalog_fixture.php` (помощники `t_put_product`, `t_catalog_scripts`, `t_catalog_cli`)
- Test: `tests/php/catalog_export_test.php`

**Interfaces:**
- Consumes: `catalog_db_open`, `catalog_db_path`, `catalog_iso`, `catalog_meta` (Task 1).
- Produces:
  - `const CATALOG_JSON` — флаги `json_encode` выгрузки
  - `catalog_export_data(PDO $db): array{sections: list<array>, tiles: list<array>, products: list<array>, redirects: list<array{from: string, to: string}>}`
  - `catalog_export_version(array $export): string` — sha256 от `sections`, `tiles`, `products`, `redirects`
  - `catalog_export(PDO $db): array` — `version`, `changedAt`, затем четыре части
  - `catalog_touch(PDO $db, DateTimeImmutable $now): void` — вызывать в конце каждой транзакции, меняющей каталог
  - проверки: `t_put_product(PDO $db, string $uid, string $status, string $main, array $positions, ?string $slug = null): void`, `t_catalog_scripts(): string`, `t_catalog_cli(string $script, string $home, array $args = []): array{0: int, 1: string}`

Формат — спецификация, раздел 3. Порядок, от которого зависит версия: `sections` — по `slug`, `tiles` — в порядке сетки (скрытых разделов нет), `products` — по `uid`, `redirects` — по `from`, букеты внутри раздела — по месту в разделе. Ключи объектов — в порядке, указанном в коде ниже; JS-сторона (план 2В) строит их так же.

- [ ] **Step 1: Помощники для проверок**

Append to `tests/php/catalog_fixture.php`:

```php
/** Букет прямо в базу, мимо правил админки; $positions — раздел => место в нём. */
function t_put_product(PDO $db, string $uid, string $status, string $main, array $positions, ?string $slug = null): void
{
    $slug ??= "buket-$uid";
    $at = '2026-10-01T10:00:00+05:00';
    $db->prepare("INSERT INTO products (uid, slug, slug_pinned, title, description, price, images, main_section,
            status, status_changed_at, created_at, updated_at)
        VALUES (?, ?, 1, ?, 'Розы, эвкалипт', 4400, ?, ?, ?, ?, ?, ?)")
        ->execute([$uid, $slug, "Букет $uid", json_encode(["/images/catalog/$main/$slug.webp"]), $main, $status, $at, $at, $at]);
    $member = $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)');
    foreach ($positions as $section => $position) {
        $member->execute([$uid, $section, $position]);
    }
}

/**
 * Копия server-pay/catalog-export.php и server-pay/catalog/*.php во временной
 * папке: путь без кириллицы — так скрипты надёжно запускаются отдельным
 * процессом и на Windows.
 */
function t_catalog_scripts(): string
{
    $root = t_tmpdir();
    mkdir("$root/catalog");
    $src = dirname(__DIR__, 2) . '/server-pay';
    copy("$src/catalog-export.php", "$root/catalog-export.php");
    foreach (glob("$src/catalog/*.php") ?: [] as $file) {
        copy($file, "$root/catalog/" . basename($file));
    }
    return $root;
}

/**
 * Запускает PHP-скрипт отдельным процессом, как cron или веб-сервер; папку
 * базы получает через PION_CATALOG_HOME.
 *
 * @return array{0: int, 1: string} код выхода и вывод (stdout и stderr вместе)
 */
function t_catalog_cli(string $script, string $home, array $args = []): array
{
    putenv('PION_CATALOG_HOME=' . $home);
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $out, $code);
    } finally {
        putenv('PION_CATALOG_HOME');
    }
    return [$code, implode("\n", $out)];
}
```

- [ ] **Step 2: Проверка (пока падает)**

Create `tests/php/catalog_export_test.php`:

```php
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
```

- [ ] **Step 3: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/export.php'`.

- [ ] **Step 4: Реализация**

Create `server-pay/catalog/export.php`:

```php
<?php
/**
 * Выгрузка каталога для сборки сайта — формат из спецификации, раздел 3.
 * В ней только то, что и так окажется на страницах: без черновиков,
 * удалённых букетов, пользователей и журнала.
 *
 * Версия — sha256 канонического JSON без version и changedAt: одинаковый
 * каталог всегда даёт одинаковую версию, и GitHub Actions по ней понимает,
 * пора ли собирать сайт. changedAt — когда выгрузка менялась последний раз;
 * его ставит catalog_touch() в конце каждого сохранения.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** JSON выгрузки: кириллица и слэши как есть — так же пишет JSON.stringify на стороне сборки. */
const CATALOG_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

function catalog_export_data(PDO $db): array
{
    $members = [];
    $rows = $db->query("SELECT ps.section, ps.uid FROM product_sections ps
        JOIN products p ON p.uid = ps.uid
        WHERE p.status IN ('active', 'hidden')
        ORDER BY ps.section, ps.position, ps.uid");
    foreach ($rows as $row) {
        $members[$row['section']][] = $row['uid'];
    }

    $sections = [];
    foreach ($db->query('SELECT * FROM sections ORDER BY slug') as $s) {
        $sections[] = [
            'slug' => $s['slug'],
            'label' => $s['label'],
            'tileImage' => $s['tile_image'],
            'visible' => (bool)$s['visible'],
            'coverTitle' => $s['cover_title'],
            'coverSub' => $s['cover_sub'],
            'covers' => json_decode($s['covers'], true, 8, JSON_THROW_ON_ERROR),
            'heading' => $s['heading'],
            'headingSub' => $s['heading_sub'],
            'hasNotFound' => (bool)$s['has_not_found'],
            'seoTitle' => $s['seo_title'],
            'seoDescription' => $s['seo_description'],
            // Все букеты раздела в его порядке, включая снятые: вернувшийся
            // в продажу встанет на своё место.
            'products' => $members[$s['slug']] ?? [],
        ];
    }

    $tiles = [];
    $rows = $db->query('SELECT t.*, s.visible FROM tiles t LEFT JOIN sections s ON s.slug = t.section ORDER BY t.position, t.id');
    foreach ($rows as $t) {
        if ($t['type'] !== 'section') {
            $tiles[] = ['type' => $t['type'], 'label' => $t['label'], 'href' => $t['href'], 'image' => $t['image']];
        } elseif ($t['visible']) {
            $tiles[] = ['type' => 'section', 'slug' => $t['section']];
        }
    }

    $products = [];
    foreach ($db->query("SELECT * FROM products WHERE status IN ('active', 'hidden') ORDER BY uid") as $p) {
        $products[] = [
            'uid' => $p['uid'],
            'slug' => $p['slug'],
            'title' => $p['title'],
            'description' => $p['description'],
            'price' => (int)$p['price'],
            'images' => json_decode($p['images'], true, 8, JSON_THROW_ON_ERROR),
            'mainSection' => $p['main_section'],
            'status' => $p['status'],
        ];
    }

    $redirects = [];
    foreach ($db->query('SELECT from_path, to_path FROM redirects ORDER BY from_path') as $r) {
        $redirects[] = ['from' => $r['from_path'], 'to' => $r['to_path']];
    }

    return ['sections' => $sections, 'tiles' => $tiles, 'products' => $products, 'redirects' => $redirects];
}

/** Версия — по четырём частям в постоянном порядке; version и changedAt в неё не входят. */
function catalog_export_version(array $export): string
{
    $canonical = [
        'sections' => $export['sections'],
        'tiles' => $export['tiles'],
        'products' => $export['products'],
        'redirects' => $export['redirects'],
    ];
    return hash('sha256', json_encode($canonical, CATALOG_JSON));
}

function catalog_export(PDO $db): array
{
    $data = catalog_export_data($db);
    return ['version' => catalog_export_version($data), 'changedAt' => catalog_meta($db)['changed_at'] ?? null] + $data;
}

/**
 * Конец сохранения: если выгрузка изменилась — запомнить новую версию и
 * время. Правка черновика выгрузку не меняет, и время не трогается: иначе
 * админка показывала бы «ждёт выкладки», а GitHub не видел бы, что собирать.
 */
function catalog_touch(PDO $db, DateTimeImmutable $now): void
{
    $version = catalog_export_version(catalog_export_data($db));
    if ((catalog_meta($db)['version'] ?? null) === $version) {
        return;
    }
    $set = $db->prepare('INSERT INTO meta (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['version', $version]);
    $set->execute(['changed_at', catalog_iso($now)]);
}
```

Create `server-pay/catalog-export.php`:

```php
<?php
/**
 * Выгрузка каталога для сборки сайта на GitHub Actions: весь каталог одним
 * JSON (формат — спецификация админки, раздел 3). Только чтение.
 *
 * Ключа нет: в выгрузке только то, что и так окажется на страницах сайта.
 * Адрес под /pay/: правила от парсеров в .htaccess его не трогают, robots.txt
 * его уже закрывает.
 */

declare(strict_types=1);

require __DIR__ . '/catalog/db.php';
require __DIR__ . '/catalog/export.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Каталога ещё нет: сборка не должна принять пустоту за «все букеты удалены». */
function catalog_export_unavailable(): never
{
    http_response_code(503);
    echo json_encode(['error' => 'Каталог ещё не создан'], CATALOG_JSON);
    exit;
}

$file = catalog_db_path();
if (!is_file($file)) {
    catalog_export_unavailable();
}
$db = catalog_db_open($file);
if ((int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn() === 0) {
    catalog_export_unavailable();
}
echo json_encode(catalog_export($db), CATALOG_JSON);
```

- [ ] **Step 5: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/catalog/export.php server-pay/catalog-export.php tests/php/catalog_fixture.php tests/php/catalog_export_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): выгрузка каталога, её версия и время изменения

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Букеты — черновик и правка

**Files:**
- Create: `server-pay/catalog/products.php`
- Modify: `tests/php/catalog_fixture.php` (помощники `t_fields`, `t_row`, `t_section_order`)
- Test: `tests/php/catalog_products_test.php`

**Interfaces:**
- Consumes: Task 1 (`catalog_tx`, `catalog_iso`, `catalog_audit`, `CatalogError`, `CatalogConflict`), Task 2 (`catalog_slugify`), Task 3 (`catalog_touch`, `CATALOG_JSON`).
- Produces:
  - поля букета — массив `['title' => string, 'description' => string, 'price' => int, 'images' => list<string>, 'sections' => list<string>, 'mainSection' => ?string]` (`mainSection` null или пусто — по умолчанию)
  - `const CATALOG_NOVINKI = 'novinki'`, `CATALOG_TITLE_MAX = 120`, `CATALOG_DESCRIPTION_MAX = 1000`, `CATALOG_PRICE_MIN = 100`, `CATALOG_PRICE_MAX = 300000`, `CATALOG_IMAGES_MAX = 4`, `CATALOG_IMAGE_PATH` (регулярное выражение пути фото)
  - `catalog_product_fields(PDO $db, array $fields): array` — проверенные поля, `mainSection` уже string
  - `catalog_default_main_section(PDO $db, array $sections): string`
  - `catalog_new_uid(PDO $db): string`
  - `catalog_product_row(PDO $db, string $uid): array` — строка `products`; нет — `CatalogError`
  - `catalog_product_for_change(PDO $db, string $uid, int $version): array` — то же плюс проверка версии (`CatalogConflict`)
  - `catalog_product_sections(PDO $db, string $uid): list<string>` — по алфавиту
  - `catalog_put_first(PDO $db, string $uid, string $section): void`
  - `catalog_create_product(PDO $db, string $login, array $fields, DateTimeImmutable $now): string` — uid черновика
  - `catalog_update_product(PDO $db, string $login, string $uid, int $version, array $fields, DateTimeImmutable $now): void`
  - проверки: `t_fields(array $over = []): array`, `t_row(PDO $db, string $uid): array`, `t_section_order(PDO $db, string $section): list<string>`

- [ ] **Step 1: Помощники для проверок**

Append to `tests/php/catalog_fixture.php`:

```php
/** Поля букета по умолчанию; $over — что поменять. */
function t_fields(array $over = []): array
{
    return $over + [
        'title' => 'Букет «Нежность»',
        'description' => 'Розы, эвкалипт',
        'price' => 4400,
        'images' => [],
        'sections' => ['bukety'],
        'mainSection' => null,
    ];
}

function t_row(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT * FROM products WHERE uid = ?');
    $q->execute([$uid]);
    return $q->fetch();
}

/** Букеты раздела по местам — все, независимо от статуса. */
function t_section_order(PDO $db, string $section): array
{
    $q = $db->prepare('SELECT uid FROM product_sections WHERE section = ? ORDER BY position, uid');
    $q->execute([$section]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
```

- [ ] **Step 2: Проверка (пока падает)**

Create `tests/php/catalog_products_test.php`:

```php
<?php
/**
 * Букеты: черновик и правка — проверка полей, uid и slug, главный раздел по
 * умолчанию, место в разделе, журнал и одновременная правка.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('черновик', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $p = t_row($db, $uid);
    t_true((bool)preg_match('/^[1-9][0-9]{11}$/', $uid), 'uid — 12 цифр, как у букетов из Tilda');
    t_equal([$p['status'], $p['slug'], $p['slug_pinned'], $p['version']], ['draft', 'buket-nezhnost', 0, 1],
        'новый букет — черновик, slug из названия, ещё не закреплён');
    t_equal([$p['title'], $p['price'], $p['main_section'], $p['updated_by']], ['Букет «Нежность»', 4400, 'bukety', 'anna'],
        'поля сохранены');
    t_equal(catalog_export_data($db)['products'], [], 'черновика в выгрузке нет');
    t_equal(t_section_order($db, 'bukety'), [$uid], 'черновик уже стоит в своём разделе');
});

t_case('черновик не трогает время выкладки', function (): void {
    $db = t_catalog_with_sections();
    catalog_touch($db, t_now());
    catalog_create_product($db, 'anna', t_fields(), t_now('+1 hour'));
    t_equal(catalog_meta($db)['changed_at'], '2026-10-05T14:00:00+05:00', 'на сайте черновика нет — и ждать выкладки нечего');
});

t_case('проверка полей', function (): void {
    $db = t_catalog_with_sections();
    $bad = [
        'пустое название' => ['title' => '   '],
        'название длиннее 120 знаков' => ['title' => str_repeat('я', 121)],
        'состав длиннее 1000 знаков' => ['description' => str_repeat('я', 1001)],
        'цена меньше 100' => ['price' => 99],
        'цена больше 300 000' => ['price' => 300001],
        'цена строкой' => ['price' => '4400'],
        'цена с копейками' => ['price' => 4400.5],
        'пять фото' => ['images' => array_fill(0, 5, '/images/catalog/bukety/a.webp')],
        'фото не из каталога' => ['images' => ['/etc/passwd']],
        'ни одного раздела' => ['sections' => []],
        'нет такого раздела' => ['sections' => ['nope']],
        'главный не среди отмеченных' => ['sections' => ['bukety'], 'mainSection' => 'roses'],
    ];
    foreach ($bad as $what => $over) {
        t_throws(fn () => catalog_create_product($db, 'anna', t_fields($over), t_now()), CatalogError::class, "не сохраняется: $what");
    }
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'ничего из этого не записано');
    $uid = catalog_create_product($db, 'anna', t_fields([
        'title' => str_repeat('я', 120),
        'price' => 100,
        'images' => array_fill(0, 4, '/images/catalog/bukety/a.webp'),
    ]), t_now());
    t_equal(t_row($db, $uid)['price'], 100, 'границы допустимы: 120 знаков, 100 ₽, четыре фото');
    $e = t_throws(fn () => catalog_create_product($db, 'anna', t_fields(['price' => 50]), t_now()), CatalogError::class, 'цена 50 ₽');
    t_equal($e?->getMessage(), 'Цена — целое число рублей, от 100 до 300 000.', 'ошибка понятна без разработчика');
});

t_case('главный раздел по умолчанию', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['novinki', 'roses']]), t_now());
    t_equal(t_row($db, $uid)['main_section'], 'roses', 'не «Новинки»: новизна проходит, а адрес должен остаться');
    $only = catalog_create_product($db, 'anna', t_fields(['sections' => ['novinki']]), t_now());
    t_equal(t_row($db, $only)['main_section'], 'novinki', 'отмечены только «Новинки» — главными становятся они');
    $two = catalog_create_product($db, 'anna', t_fields(['sections' => ['roses', 'bukety']]), t_now());
    t_equal(t_row($db, $two)['main_section'], 'bukety', 'первый отмеченный в порядке сетки');
});

t_case('новый — первым в разделе', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    t_equal(t_section_order($db, 'bukety'), [$b, $a], 'добавленный встаёт первым');
});

t_case('правка черновика', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields([
        'title' => 'Букет «Весна»',
        'price' => 4800,
        'sections' => ['bukety', 'roses'],
    ]), t_now('+1 hour'));
    $p = t_row($db, $uid);
    t_equal([$p['slug'], $p['price'], $p['version']], ['buket-vesna', 4800, 2], 'slug черновика следует за названием, версия растёт');
    t_equal(t_section_order($db, 'roses'), [$uid], 'добавлен в отмеченный раздел');
    t_equal(
        $db->query("SELECT field, old_value, new_value FROM audit WHERE field <> 'created' ORDER BY id")->fetchAll(),
        [
            ['field' => 'title', 'old_value' => 'Букет «Нежность»', 'new_value' => 'Букет «Весна»'],
            ['field' => 'price', 'old_value' => '4400', 'new_value' => '4800'],
            ['field' => 'sections', 'old_value' => 'bukety', 'new_value' => 'bukety, roses'],
            ['field' => 'slug', 'old_value' => 'buket-nezhnost', 'new_value' => 'buket-vesna'],
        ],
        'в журнале — каждое изменённое поле: было → стало',
    );
    catalog_update_product($db, 'anna', $uid, 2, t_fields([
        'title' => 'Букет «Весна»',
        'price' => 4800,
        'sections' => ['roses'],
    ]), t_now('+2 hours'));
    t_equal(t_section_order($db, 'bukety'), [], 'сняли галочку — убран из раздела');
    t_equal(t_row($db, $uid)['main_section'], 'roses', 'главный раздел пересчитан по отмеченным');
});

t_case('одновременная правка', function (): void {
    $db = t_catalog_with_sections();
    $db->exec("INSERT INTO users (login, name, password_hash, created_at, updated_at)
        VALUES ('olga', 'Ольга', 'x', '2026-10-01T10:00:00+05:00', '2026-10-01T10:00:00+05:00')");
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'olga', $uid, 1, t_fields(['price' => 5000]), t_now('+5 minutes'));
    $e = t_throws(
        fn () => catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4600]), t_now('+6 minutes')),
        CatalogConflict::class,
        'сохранение поверх чужой правки отклоняется',
    );
    t_true(str_contains((string)$e?->getMessage(), 'Ольга'), 'в сообщении — кто успел сохранить');
    t_equal(t_row($db, $uid)['price'], 5000, 'чужая правка не затёрта');
});

t_case('нет такого букета', function (): void {
    $db = t_catalog_with_sections();
    t_throws(
        fn () => catalog_update_product($db, 'anna', '999999999999', 1, t_fields(), t_now()),
        CatalogError::class,
        'правка несуществующего — понятная ошибка',
    );
});
```

- [ ] **Step 3: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/products.php'`.

- [ ] **Step 4: Реализация**

Create `server-pay/catalog/products.php`:

```php
<?php
/**
 * Букеты: черновик, правка, публикация, снятие с продажи, удаление и
 * восстановление — правила данных из спецификации (раздел «База каталога»).
 *
 * Каждая функция catalog_* — одна транзакция: или всё, или ничего. Она
 * проверяет версию букета (её видел сотрудник, открывая карточку) и в конце
 * вызывает catalog_touch(), чтобы выгрузка узнала об изменении.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/export.php';

/** Раздел «Новинки»: первые три букета в продаже из него — на главной. */
const CATALOG_NOVINKI = 'novinki';
const CATALOG_TITLE_MAX = 120;
const CATALOG_DESCRIPTION_MAX = 1000;
const CATALOG_PRICE_MIN = 100;
const CATALOG_PRICE_MAX = 300000;
const CATALOG_IMAGES_MAX = 4;
/** Фото — только файлы каталога: их кладёт админка (план 2Б) или перенос каталога. */
const CATALOG_IMAGE_PATH = '~^/images/catalog/[a-z0-9_-]+/[A-Za-z0-9._-]+\.webp$~';

/**
 * Проверяет поля букета и приводит их к виду для базы. Ошибка — CatalogError
 * с текстом для сотрудника.
 *
 * @return array{title: string, description: string, price: int, images: list<string>, sections: list<string>, mainSection: string}
 */
function catalog_product_fields(PDO $db, array $fields): array
{
    $title = trim((string)preg_replace('/\s+/u', ' ', (string)($fields['title'] ?? '')));
    if ($title === '') {
        throw new CatalogError('Напишите название букета.');
    }
    if (mb_strlen($title) > CATALOG_TITLE_MAX) {
        throw new CatalogError('Название длиннее 120 знаков — сократите его.');
    }
    $description = trim((string)($fields['description'] ?? ''));
    if (mb_strlen($description) > CATALOG_DESCRIPTION_MAX) {
        throw new CatalogError('Состав длиннее 1000 знаков — сократите его.');
    }
    $price = $fields['price'] ?? null;
    if (!is_int($price) || $price < CATALOG_PRICE_MIN || $price > CATALOG_PRICE_MAX) {
        throw new CatalogError('Цена — целое число рублей, от 100 до 300 000.');
    }
    $images = array_values(array_map('strval', (array)($fields['images'] ?? [])));
    if (count($images) > CATALOG_IMAGES_MAX) {
        throw new CatalogError('Фото — не больше четырёх.');
    }
    foreach ($images as $image) {
        if (!preg_match(CATALOG_IMAGE_PATH, $image)) {
            throw new CatalogError('Фото не из каталога — загрузите его заново.');
        }
    }
    $sections = array_values(array_unique(array_map('strval', (array)($fields['sections'] ?? []))));
    if ($sections === []) {
        throw new CatalogError('Отметьте хотя бы один раздел.');
    }
    $known = $db->query('SELECT slug FROM sections')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sections as $section) {
        if (!in_array($section, $known, true)) {
            throw new CatalogError('Такого раздела нет — обновите страницу.');
        }
    }
    $main = (string)($fields['mainSection'] ?? '');
    if ($main === '') {
        $main = catalog_default_main_section($db, $sections);
    } elseif (!in_array($main, $sections, true)) {
        throw new CatalogError('Главный раздел должен быть среди отмеченных.');
    }
    return [
        'title' => $title,
        'description' => $description,
        'price' => $price,
        'images' => $images,
        'sections' => $sections,
        'mainSection' => $main,
    ];
}

/**
 * Главный раздел по умолчанию — первый отмеченный в порядке сетки, кроме
 * «Новинок»: новизна проходит, а адрес страницы должен остаться.
 */
function catalog_default_main_section(PDO $db, array $sections): string
{
    $order = $db->query('SELECT s.slug FROM sections s LEFT JOIN tiles t ON t.section = s.slug
        ORDER BY t.position IS NULL, t.position, s.slug')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($order as $slug) {
        if ($slug !== CATALOG_NOVINKI && in_array($slug, $sections, true)) {
            return $slug;
        }
    }
    return $sections[0];
}

/**
 * Uid нового букета — 12 случайных цифр, как у перенесённых из Tilda. Не
 * совпадает ни с одним uid в базе, включая удалённые: uid остаётся в истории
 * заказов.
 */
function catalog_new_uid(PDO $db): string
{
    $taken = $db->prepare('SELECT 1 FROM products WHERE uid = ?');
    do {
        $uid = (string)random_int(100000000000, 999999999999);
        $taken->execute([$uid]);
    } while ($taken->fetchColumn() !== false);
    return $uid;
}

function catalog_product_row(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT * FROM products WHERE uid = ?');
    $q->execute([$uid]);
    $row = $q->fetch();
    if ($row === false) {
        throw new CatalogError('Такого букета нет — обновите страницу.');
    }
    return $row;
}

/**
 * Строка букета для изменения. Версия не та, что видел сотрудник, — букет
 * успел сохранить кто-то другой, и чужую правку затирать нельзя.
 */
function catalog_product_for_change(PDO $db, string $uid, int $version): array
{
    $p = catalog_product_row($db, $uid);
    if ((int)$p['version'] !== $version) {
        $q = $db->prepare('SELECT name FROM users WHERE login = ?');
        $q->execute([$p['updated_by']]);
        $who = $q->fetchColumn() ?: ($p['updated_by'] !== '' ? $p['updated_by'] : 'другой сотрудник');
        $at = substr((string)$p['updated_at'], 11, 5);
        throw new CatalogConflict("Этот букет уже изменён ($who, $at) — откройте его заново.");
    }
    return $p;
}

/** Разделы букета — по алфавиту. */
function catalog_product_sections(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT section FROM product_sections WHERE uid = ? ORDER BY section');
    $q->execute([$uid]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** Ставит букет первым в разделе (добавляет в раздел, если его там не было). */
function catalog_put_first(PDO $db, string $uid, string $section): void
{
    $min = $db->prepare('SELECT MIN(position) FROM product_sections WHERE section = ?');
    $min->execute([$section]);
    $first = (int)($min->fetchColumn() ?? 1) - 1;
    $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)
        ON CONFLICT(uid, section) DO UPDATE SET position = excluded.position')
        ->execute([$uid, $section, $first]);
}

/**
 * Новый букет — черновик: на сайте его нет совсем, slug следует за
 * названием, пока букет не опубликуют.
 */
function catalog_create_product(PDO $db, string $login, array $fields, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $fields, $now): string {
        $f = catalog_product_fields($db, $fields);
        $uid = catalog_new_uid($db);
        $at = catalog_iso($now);
        $db->prepare("INSERT INTO products (uid, slug, title, description, price, images, main_section, status,
                status_changed_at, created_at, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)")
            ->execute([
                $uid, catalog_slugify($f['title']), $f['title'], $f['description'], $f['price'],
                json_encode($f['images'], CATALOG_JSON), $f['mainSection'], $at, $at, $at, $login,
            ]);
        foreach ($f['sections'] as $section) {
            catalog_put_first($db, $uid, $section);
        }
        catalog_audit($db, $login, $now, 'product', $uid, 'created', null, $f['title']);
        catalog_touch($db, $now);
        return $uid;
    });
}

/**
 * Правка букета: название, состав, цена, фото, разделы. $version — версия,
 * которую видел сотрудник.
 */
function catalog_update_product(PDO $db, string $login, string $uid, int $version, array $fields, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $fields, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] === 'deleted') {
            throw new CatalogError('Букет удалён — сначала восстановите его.');
        }
        $f = catalog_product_fields($db, $fields);
        // Пока букет не опубликован, адреса никто не знает — slug следует за названием.
        $slug = $p['slug_pinned'] ? $p['slug'] : catalog_slugify($f['title']);

        $before = catalog_product_sections($db, $uid);
        $leave = $db->prepare('DELETE FROM product_sections WHERE uid = ? AND section = ?');
        foreach (array_diff($before, $f['sections']) as $section) {
            $leave->execute([$uid, $section]);
        }
        foreach (array_diff($f['sections'], $before) as $section) {
            catalog_put_first($db, $uid, $section);
        }
        $after = $f['sections'];
        sort($after);

        $images = json_encode($f['images'], CATALOG_JSON);
        $changes = [
            'title' => [$p['title'], $f['title']],
            'description' => [$p['description'], $f['description']],
            'price' => [(string)$p['price'], (string)$f['price']],
            'images' => [$p['images'], $images],
            'sections' => [implode(', ', $before), implode(', ', $after)],
            'mainSection' => [$p['main_section'], $f['mainSection']],
            'slug' => [$p['slug'], $slug],
        ];
        foreach ($changes as $field => [$old, $new]) {
            if ($old !== $new) {
                catalog_audit($db, $login, $now, 'product', $uid, $field, $old, $new);
            }
        }
        $db->prepare('UPDATE products SET slug = ?, title = ?, description = ?, price = ?, images = ?,
                main_section = ?, updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?')
            ->execute([$slug, $f['title'], $f['description'], $f['price'], $images, $f['mainSection'], catalog_iso($now), $login, $uid]);
        catalog_touch($db, $now);
    });
}
```

- [ ] **Step 5: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/catalog/products.php tests/php/catalog_fixture.php tests/php/catalog_products_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): букеты — черновик, правка, проверка полей, одновременная правка

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Публикация, снятие с продажи, тёзки, переадресации

**Files:**
- Create: `server-pay/catalog/redirects.php`
- Modify: `server-pay/catalog/products.php` (подключить `redirects.php`, добавить функции)
- Modify: `tests/php/catalog_fixture.php` (помощник `t_redirects`)
- Test: `tests/php/catalog_redirects_test.php`, `tests/php/catalog_publish_test.php`

**Interfaces:**
- Consumes: Task 4 (`catalog_product_for_change`, `catalog_product_sections`, `catalog_put_first`), Task 3 (`catalog_touch`).
- Produces:
  - `catalog_redirect_add(PDO $db, string $from, string $to, DateTimeImmutable $now): void`
  - `catalog_redirect_clear_live(PDO $db): void`
  - `const CATALOG_RESTORE_DAYS = 90`
  - `catalog_address(string $section, string $slug): string` — `/раздел/slug/`
  - `catalog_address_taken(PDO $db, string $section, string $slug, string $exceptUid = ''): bool`
  - `catalog_free_slug(PDO $db, string $base, string $exceptUid): string`
  - `catalog_publish(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void`
  - `catalog_hide(...)`, `catalog_unhide(...)` — та же сигнатура, что у `catalog_publish`
  - `catalog_title_key(string $title): string`
  - `catalog_find_namesake(PDO $db, string $title, DateTimeImmutable $now): ?array{uid: string, title: string, status: string, since: string}`
  - проверки: `t_redirects(PDO $db): array<string, string>` (откуда => куда)

- [ ] **Step 1: Помощник для проверок**

Append to `tests/php/catalog_fixture.php`:

```php
/** Переадресации: откуда => куда. */
function t_redirects(PDO $db): array
{
    return $db->query('SELECT from_path, to_path FROM redirects ORDER BY from_path')->fetchAll(PDO::FETCH_KEY_PAIR);
}
```

- [ ] **Step 2: Проверки (пока падают)**

Create `tests/php/catalog_redirects_test.php`:

```php
<?php
/**
 * Переадресации: цепочки схлопываются, живые адреса от них свободны.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/redirects.php';

t_case('цепочки', function (): void {
    $db = t_catalog_db();
    catalog_redirect_add($db, '/a/', '/b/', t_now());
    catalog_redirect_add($db, '/b/', '/c/', t_now());
    t_equal(t_redirects($db), ['/a/' => '/c/', '/b/' => '/c/'], 'A → B и B → C превращаются в A → C');
    catalog_redirect_add($db, '/c/', '/a/', t_now());
    t_equal(t_redirects($db), ['/b/' => '/a/', '/c/' => '/a/'], 'петля не остаётся: переадресации на самого себя нет');
    catalog_redirect_add($db, '/x/', '/x/', t_now());
    t_equal(isset(t_redirects($db)['/x/']), false, 'адрес сам на себя не переадресуется');
});

t_case('живой адрес', function (): void {
    $db = t_catalog_with_sections();
    t_put_product($db, '100000000001', 'active', 'roses', ['roses' => 0], 'roza');
    t_put_product($db, '100000000002', 'deleted', 'bukety', ['bukety' => 0], 'pion');
    $db->exec("INSERT INTO redirects (from_path, to_path, created_at) VALUES
        ('/roses/roza/', '/roses/', '2026-10-01T10:00:00+05:00'),
        ('/bukety/pion/', '/bukety/', '2026-10-01T10:00:00+05:00')");
    catalog_redirect_clear_live($db);
    t_equal(t_redirects($db), ['/bukety/pion/' => '/bukety/'], 'с адреса живого букета переадресация снята, с удалённого — нет');
});
```

Create `tests/php/catalog_publish_test.php`:

```php
<?php
/**
 * Публикация, снятие с продажи и возврат, тёзки среди снятых.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('публикация', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now('+1 hour'));
    $p = t_row($db, $uid);
    t_equal(
        [$p['status'], $p['slug'], $p['slug_pinned'], $p['published_at'], $p['version']],
        ['active', 'buket-nezhnost', 1, '2026-10-05T15:00:00+05:00', 2],
        'в продаже, slug закреплён',
    );
    t_equal(array_column(catalog_export_data($db)['products'], 'uid'), [$uid], 'опубликованный — в выгрузке');
    t_equal(catalog_meta($db)['changed_at'], '2026-10-05T15:00:00+05:00', 'публикация ждёт выкладки');
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['title' => 'Букет «Весна»']), t_now('+2 hours'));
    t_equal(t_row($db, $uid)['slug'], 'buket-nezhnost', 'после публикации переименование адрес не меняет');
    t_throws(fn () => catalog_publish($db, 'anna', $uid, 3, t_now()), CatalogError::class, 'опубликовать второй раз нельзя');
});

t_case('тёзки получают -2, -3', function (): void {
    $db = t_catalog_with_sections();
    $slugs = [];
    foreach (['bukety', 'roses', 'bukety'] as $section) {
        $uid = catalog_create_product($db, 'anna', t_fields(['sections' => [$section]]), t_now());
        catalog_publish($db, 'anna', $uid, 1, t_now());
        $slugs[] = t_row($db, $uid)['slug'];
    }
    t_equal($slugs, ['buket-nezhnost', 'buket-nezhnost-2', 'buket-nezhnost-3'], 'закреплённый slug уникален во всём каталоге');
});

t_case('опубликованный — первым в разделе', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    t_equal(t_section_order($db, 'bukety'), [$a, $b], 'опубликованный из черновика встаёт первым');
});

t_case('снять и вернуть', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $b, 1, t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_hide($db, 'anna', $a, 2, t_now('+1 hour'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['slug'], $p['status_changed_at']], ['hidden', 'buket-a', '2026-10-05T15:00:00+05:00'], 'снят, адрес тот же');
    t_equal(catalog_export_data($db)['sections'][0]['products'], [$a, $b], 'в выгрузке снятый остаётся на своём месте');
    catalog_unhide($db, 'anna', $a, 3, t_now('+2 hours'));
    t_equal([t_row($db, $a)['status'], t_section_order($db, 'bukety')], ['active', [$a, $b]], 'вернулся в продажу на прежнее место');
    t_throws(fn () => catalog_unhide($db, 'anna', $a, 4, t_now()), CatalogError::class, 'вернуть можно только снятый');
    $draft = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет В']), t_now());
    t_throws(fn () => catalog_hide($db, 'anna', $draft, 1, t_now()), CatalogError::class, 'черновик снять нельзя — его нет на сайте');
});

t_case('тёзка среди снятых', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    t_equal(catalog_find_namesake($db, 'Букет «Нежность»', t_now()), null, 'черновик тёзкой не считается');
    catalog_publish($db, 'anna', $uid, 1, t_now());
    t_equal(catalog_find_namesake($db, 'Букет «Нежность»', t_now()), null, 'букет в продаже — не повод предлагать возврат');
    catalog_hide($db, 'anna', $uid, 2, t_now('+1 day'));
    t_equal(catalog_find_namesake($db, 'букет  "нежность"', t_now('+2 days')), [
        'uid' => $uid, 'title' => 'Букет «Нежность»', 'status' => 'hidden', 'since' => '2026-10-06T14:00:00+05:00',
    ], 'снятый тёзка находится — кавычки, регистр и пробелы не мешают');
});

t_case('публикация снимает переадресацию с адреса', function (): void {
    $db = t_catalog_with_sections();
    $db->exec("INSERT INTO redirects (from_path, to_path, created_at)
        VALUES ('/bukety/buket-nezhnost/', '/bukety/', '2026-10-01T10:00:00+05:00')");
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    t_equal(t_redirects($db), [], 'адрес снова живой — переадресации с него нет');
});
```

- [ ] **Step 3: Убедиться, что падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/redirects.php'`.

- [ ] **Step 4: Реализация переадресаций**

Create `server-pay/catalog/redirects.php`:

```php
<?php
/**
 * Переадресации с адресов, которых больше нет: удалённый букет — в его
 * раздел, сменивший главный раздел — на новый адрес. Сайт отвечает по ним
 * 301 (правило .htaccess и pay/catalog-redirect.php — план 2В).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function catalog_redirect_add(PDO $db, string $from, string $to, DateTimeImmutable $now): void
{
    if ($from === $to) {
        return;
    }
    // Цепочки схлопываются: всё, что вело на $from, теперь ведёт прямо на $to.
    $db->prepare('UPDATE redirects SET to_path = ? WHERE to_path = ?')->execute([$to, $from]);
    $db->exec('DELETE FROM redirects WHERE from_path = to_path');
    $db->prepare('INSERT INTO redirects (from_path, to_path, created_at) VALUES (?, ?, ?)
        ON CONFLICT(from_path) DO UPDATE SET to_path = excluded.to_path, created_at = excluded.created_at')
        ->execute([$from, $to, catalog_iso($now)]);
}

/** Адрес снова занят живой страницей букета — переадресация с него снимается. */
function catalog_redirect_clear_live(PDO $db): void
{
    $db->exec("DELETE FROM redirects WHERE from_path IN (
        SELECT '/' || main_section || '/' || slug || '/' FROM products WHERE status IN ('active', 'hidden'))");
}
```

- [ ] **Step 5: Реализация публикации, снятия и тёзок**

In `server-pay/catalog/products.php` after `require_once __DIR__ . '/export.php';` add:

```php
require_once __DIR__ . '/redirects.php';
```

After `const CATALOG_IMAGE_PATH = …;` add:

```php
/** Сколько дней удалённый букет можно восстановить. */
const CATALOG_RESTORE_DAYS = 90;
```

Append to `server-pay/catalog/products.php`:

```php
/** Адрес страницы букета. */
function catalog_address(string $section, string $slug): string
{
    return "/$section/$slug/";
}

/** Есть ли по адресу /раздел/slug/ живая страница другого букета — в продаже или снятого. */
function catalog_address_taken(PDO $db, string $section, string $slug, string $exceptUid = ''): bool
{
    $q = $db->prepare("SELECT 1 FROM products
        WHERE main_section = ? AND slug = ? AND status IN ('active', 'hidden') AND uid <> ?");
    $q->execute([$section, $slug, $exceptUid]);
    return $q->fetchColumn() !== false;
}

/**
 * Свободный slug для публикации: закреплённый slug уникален среди живых
 * букетов всего каталога, занятый получает -2, -3 и дальше. Удалённые не в
 * счёт — их адрес может занять новый букет.
 */
function catalog_free_slug(PDO $db, string $base, string $exceptUid): string
{
    $taken = $db->prepare("SELECT 1 FROM products WHERE slug = ? AND status IN ('active', 'hidden') AND uid <> ?");
    for ($n = 1; ; $n++) {
        $slug = $n === 1 ? $base : "$base-$n";
        $taken->execute([$slug, $exceptUid]);
        if ($taken->fetchColumn() === false) {
            return $slug;
        }
    }
}

/**
 * Опубликовать черновик: slug закрепляется и больше не меняется, букет
 * встаёт первым во всех своих разделах.
 */
function catalog_publish(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] !== 'draft') {
            throw new CatalogError('Опубликовать можно только черновик.');
        }
        $slug = catalog_free_slug($db, catalog_slugify($p['title']), $uid);
        $at = catalog_iso($now);
        $db->prepare("UPDATE products SET slug = ?, slug_pinned = 1, status = 'active', status_changed_at = ?,
                published_at = COALESCE(published_at, ?), updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?")
            ->execute([$slug, $at, $at, $at, $login, $uid]);
        foreach (catalog_product_sections($db, $uid) as $section) {
            catalog_put_first($db, $uid, $section);
        }
        catalog_audit($db, $login, $now, 'product', $uid, 'status', 'draft', 'active');
        if ($slug !== $p['slug']) {
            catalog_audit($db, $login, $now, 'product', $uid, 'slug', $p['slug'], $slug);
        }
        catalog_redirect_clear_live($db);
        catalog_touch($db, $now);
    });
}

/**
 * Снять с продажи: страница остаётся с пометкой «Сейчас нет в продаже», в
 * разделах букета не видно, заказать нельзя.
 */
function catalog_hide(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_change_status($db, $login, $uid, $version, 'active', 'hidden', 'Снять с продажи можно только букет в продаже.', $now);
}

/** Вернуть в продажу — на прежнее место, по тому же адресу. */
function catalog_unhide(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_change_status($db, $login, $uid, $version, 'hidden', 'active', 'Вернуть в продажу можно только снятый букет.', $now);
}

function catalog_change_status(
    PDO $db,
    string $login,
    string $uid,
    int $version,
    string $from,
    string $to,
    string $error,
    DateTimeImmutable $now,
): void {
    catalog_tx($db, function () use ($db, $login, $uid, $version, $from, $to, $error, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] !== $from) {
            throw new CatalogError($error);
        }
        $at = catalog_iso($now);
        $db->prepare('UPDATE products SET status = ?, status_changed_at = ?, updated_at = ?, updated_by = ?,
                version = version + 1
            WHERE uid = ?')
            ->execute([$to, $at, $at, $login, $uid]);
        catalog_audit($db, $login, $now, 'product', $uid, 'status', $from, $to);
        catalog_touch($db, $now);
    });
}

/** Ключ сравнения названий — как в dedupeProducts: без кавычек, регистра и лишних пробелов. */
function catalog_title_key(string $title): string
{
    $plain = mb_strtolower((string)preg_replace('/[«»"\'`]/u', '', $title));
    return trim((string)preg_replace('/\s+/u', ' ', $plain));
}

/**
 * Снятый или удалённый (не раньше чем 90 дней назад) букет с тем же
 * названием. Админка предлагает вернуть его вместо нового — так на сайте не
 * появляются две одинаковые страницы.
 *
 * @return array{uid: string, title: string, status: string, since: string}|null
 */
function catalog_find_namesake(PDO $db, string $title, DateTimeImmutable $now): ?array
{
    $key = catalog_title_key($title);
    $oldest = catalog_iso($now->modify('-' . CATALOG_RESTORE_DAYS . ' days'));
    $rows = $db->query("SELECT uid, title, status, status_changed_at FROM products
        WHERE status IN ('hidden', 'deleted') ORDER BY status_changed_at DESC, uid");
    foreach ($rows as $row) {
        if (catalog_title_key($row['title']) !== $key) {
            continue;
        }
        if ($row['status'] === 'deleted' && $row['status_changed_at'] < $oldest) {
            continue;
        }
        return ['uid' => $row['uid'], 'title' => $row['title'], 'status' => $row['status'], 'since' => $row['status_changed_at']];
    }
    return null;
}
```

- [ ] **Step 6: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 7: Коммит**

```bash
git add server-pay/catalog/redirects.php server-pay/catalog/products.php tests/php/catalog_fixture.php tests/php/catalog_redirects_test.php tests/php/catalog_publish_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): публикация, снятие с продажи, тёзки, переадресации

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Удаление, восстановление, смена главного раздела

**Files:**
- Modify: `server-pay/catalog/products.php` (функции `catalog_delete`, `catalog_restore`; в `catalog_update_product` — смена главного раздела у опубликованного)
- Test: `tests/php/catalog_delete_test.php`

**Interfaces:**
- Consumes: Task 5 (`catalog_redirect_add`, `catalog_redirect_clear_live`, `catalog_address`, `catalog_address_taken`, `catalog_free_slug`, `CATALOG_RESTORE_DAYS`).
- Produces:
  - `catalog_delete(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void`
  - `catalog_restore(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void`
  - `catalog_update_product` — та же сигнатура; у опубликованного смена главного раздела ставит переадресацию или отказывает, если адрес в новом разделе занят

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/catalog_delete_test.php`:

```php
<?php
/**
 * Удаление и восстановление, адрес удалённого тёзки, смена главного раздела.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('удалить черновик', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_delete($db, 'anna', $uid, 1, t_now());
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'черновик исчезает совсем — на сайте его не было');
    t_equal(t_section_order($db, 'bukety'), [], 'и из раздела тоже');
});

t_case('удалить и восстановить', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $b, 1, t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_delete($db, 'anna', $a, 2, t_now('+1 day'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['status_before_delete'], $p['deleted_at']], ['deleted', 'active', '2026-10-06T14:00:00+05:00'],
        'удалён, прежний статус запомнен');
    t_equal(t_redirects($db), ['/bukety/buket-a/' => '/bukety/'], 'с адреса — в главный раздел');
    t_equal(array_column(catalog_export_data($db)['products'], 'uid'), [$b], 'удалённого в выгрузке нет');
    t_throws(fn () => catalog_delete($db, 'anna', $a, 3, t_now()), CatalogError::class, 'удалить второй раз нельзя');
    t_throws(
        fn () => catalog_update_product($db, 'anna', $a, 3, t_fields(['title' => 'Букет А']), t_now()),
        CatalogError::class,
        'удалённый не правится',
    );
    t_equal(catalog_find_namesake($db, 'Букет А', t_now('+90 days'))['uid'] ?? null, $a, 'удалённый тёзка предлагается 90 дней');
    t_equal(catalog_find_namesake($db, 'Букет А', t_now('+92 days')), null, 'потом — нет');
    catalog_restore($db, 'anna', $a, 3, t_now('+91 days'));
    $p = t_row($db, $a);
    t_equal([$p['status'], $p['slug'], $p['deleted_at']], ['active', 'buket-a', null], 'восстановлен в продажу по тому же адресу');
    t_equal(t_redirects($db), [], 'переадресация снята');
    t_equal(t_section_order($db, 'bukety'), [$a, $b], 'место в разделе прежнее');
});

t_case('восстановить — только 90 дней', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    catalog_hide($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    t_throws(fn () => catalog_restore($db, 'anna', $uid, 4, t_now('+91 days')), CatalogError::class, 'через 91 день не восстановить');
    catalog_restore($db, 'anna', $uid, 4, t_now('+90 days'));
    t_equal(t_row($db, $uid)['status'], 'hidden', 'через 90 — можно, и букет возвращается снятым, каким был');
});

t_case('адрес удалённого тёзки', function (): void {
    $db = t_catalog_with_sections();
    $old = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $old, 1, t_now());
    catalog_delete($db, 'anna', $old, 2, t_now());
    $new = catalog_create_product($db, 'anna', t_fields(), t_now('+1 hour'));
    catalog_publish($db, 'anna', $new, 1, t_now('+1 hour'));
    t_equal(t_row($db, $new)['slug'], 'buket-nezhnost', 'удалённые не в счёт: адрес достаётся новому букету');
    t_equal(t_redirects($db), [], 'страница по адресу снова живая');
    catalog_restore($db, 'anna', $old, 3, t_now('+2 hours'));
    t_equal(t_row($db, $old)['slug'], 'buket-nezhnost-2', 'восстановленный получает адрес с -2');
});

t_case('смена главного раздела', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['bukety', 'roses']]), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'roses']), t_now());
    t_equal(t_redirects($db), ['/bukety/buket-nezhnost/' => '/roses/buket-nezhnost/'], 'со старого адреса — на новый');
    catalog_update_product($db, 'anna', $uid, 3, t_fields(['sections' => ['bukety', 'roses'], 'mainSection' => 'bukety']), t_now());
    t_equal(t_redirects($db), ['/roses/buket-nezhnost/' => '/bukety/buket-nezhnost/'],
        'вернули обратно — цепочка схлопнулась, с живого адреса переадресации нет');
});

t_case('адрес в новом разделе занят', function (): void {
    $db = t_catalog_with_sections();
    // Одна из трёх старых одноимённых пар: «pion» и в «Розах», и в «Букетах».
    t_put_product($db, '100000000001', 'active', 'roses', ['roses' => 0], 'pion');
    t_put_product($db, '100000000002', 'active', 'bukety', ['bukety' => 0, 'roses' => 1], 'pion');
    t_throws(
        fn () => catalog_update_product($db, 'anna', '100000000002', 1, t_fields([
            'title' => 'Пион', 'sections' => ['bukety', 'roses'], 'mainSection' => 'roses',
        ]), t_now()),
        CatalogError::class,
        'главным не сделать раздел, где такой адрес уже занят',
    );
    t_equal(t_row($db, '100000000002')['main_section'], 'bukety', 'главный раздел не изменился');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Call to undefined function catalog_delete()`.

- [ ] **Step 3: Реализация**

In `server-pay/catalog/products.php` replace the whole function `catalog_update_product` with:

```php
/**
 * Правка букета: название, состав, цена, фото, разделы. $version — версия,
 * которую видел сотрудник. У опубликованного смена главного раздела меняет
 * адрес, а со старого ставит переадресацию.
 */
function catalog_update_product(PDO $db, string $login, string $uid, int $version, array $fields, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $fields, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] === 'deleted') {
            throw new CatalogError('Букет удалён — сначала восстановите его.');
        }
        $f = catalog_product_fields($db, $fields);
        // Пока букет не опубликован, адреса никто не знает — slug следует за названием.
        $slug = $p['slug_pinned'] ? $p['slug'] : catalog_slugify($f['title']);

        if ($p['slug_pinned'] && $f['mainSection'] !== $p['main_section']) {
            if (catalog_address_taken($db, $f['mainSection'], $slug, $uid)) {
                throw new CatalogError('В этом разделе уже есть букет с таким же адресом — главным раздел сделать нельзя. '
                    . 'Отметьте его просто как дополнительный.');
            }
            catalog_redirect_add($db, catalog_address($p['main_section'], $slug), catalog_address($f['mainSection'], $slug), $now);
        }

        $before = catalog_product_sections($db, $uid);
        $leave = $db->prepare('DELETE FROM product_sections WHERE uid = ? AND section = ?');
        foreach (array_diff($before, $f['sections']) as $section) {
            $leave->execute([$uid, $section]);
        }
        foreach (array_diff($f['sections'], $before) as $section) {
            catalog_put_first($db, $uid, $section);
        }
        $after = $f['sections'];
        sort($after);

        $images = json_encode($f['images'], CATALOG_JSON);
        $changes = [
            'title' => [$p['title'], $f['title']],
            'description' => [$p['description'], $f['description']],
            'price' => [(string)$p['price'], (string)$f['price']],
            'images' => [$p['images'], $images],
            'sections' => [implode(', ', $before), implode(', ', $after)],
            'mainSection' => [$p['main_section'], $f['mainSection']],
            'slug' => [$p['slug'], $slug],
        ];
        foreach ($changes as $field => [$old, $new]) {
            if ($old !== $new) {
                catalog_audit($db, $login, $now, 'product', $uid, $field, $old, $new);
            }
        }
        $db->prepare('UPDATE products SET slug = ?, title = ?, description = ?, price = ?, images = ?,
                main_section = ?, updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?')
            ->execute([$slug, $f['title'], $f['description'], $f['price'], $images, $f['mainSection'], catalog_iso($now), $login, $uid]);
        catalog_redirect_clear_live($db);
        catalog_touch($db, $now);
    });
}
```

Append to `server-pay/catalog/products.php`:

```php
/**
 * Удалить. Черновик исчезает совсем — на сайте его не было. У опубликованного
 * статус deleted: запись, фото и разделы остаются на 90 дней, с адреса —
 * переадресация в главный раздел.
 */
function catalog_delete(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] === 'deleted') {
            throw new CatalogError('Букет уже удалён.');
        }
        if ($p['status'] === 'draft') {
            $db->prepare('DELETE FROM products WHERE uid = ?')->execute([$uid]);
            catalog_audit($db, $login, $now, 'product', $uid, 'deleted', $p['title'], null);
            return;
        }
        $at = catalog_iso($now);
        $db->prepare("UPDATE products SET status = 'deleted', status_before_delete = status, deleted_at = ?,
                status_changed_at = ?, updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?")
            ->execute([$at, $at, $at, $login, $uid]);
        catalog_redirect_add($db, catalog_address($p['main_section'], $p['slug']), '/' . $p['main_section'] . '/', $now);
        catalog_audit($db, $login, $now, 'product', $uid, 'status', $p['status'], 'deleted');
        catalog_touch($db, $now);
    });
}

/**
 * Восстановить удалённый (не позже 90 дней): прежний статус, адрес, фото и
 * разделы. Если адрес за это время занял другой букет — slug с -2.
 */
function catalog_restore(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] !== 'deleted') {
            throw new CatalogError('Восстановить можно только удалённый букет.');
        }
        if ($p['deleted_at'] < catalog_iso($now->modify('-' . CATALOG_RESTORE_DAYS . ' days'))) {
            throw new CatalogError('Букет удалён больше 90 дней назад — его уже не восстановить.');
        }
        $slug = $p['slug'];
        if (catalog_address_taken($db, $p['main_section'], $slug, $uid)) {
            $slug = catalog_free_slug($db, $slug, $uid);
        }
        $status = $p['status_before_delete'] ?? 'hidden';
        $at = catalog_iso($now);
        $db->prepare('UPDATE products SET status = ?, status_before_delete = NULL, deleted_at = NULL, slug = ?,
                status_changed_at = ?, updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?')
            ->execute([$status, $slug, $at, $at, $login, $uid]);
        catalog_audit($db, $login, $now, 'product', $uid, 'status', 'deleted', $status);
        if ($slug !== $p['slug']) {
            catalog_audit($db, $login, $now, 'product', $uid, 'slug', $p['slug'], $slug);
        }
        catalog_redirect_clear_live($db);
        catalog_touch($db, $now);
    });
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/catalog/products.php tests/php/catalog_delete_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): удаление, восстановление, смена главного раздела

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Разделы и порядок плиток

**Files:**
- Create: `server-pay/catalog/sections.php`
- Test: `tests/php/catalog_sections_test.php`

**Interfaces:**
- Consumes: Task 1, Task 2 (`catalog_slugify`, `CATALOG_RESERVED_SLUGS`), Task 3 (`catalog_touch`, `CATALOG_JSON`).
- Produces:
  - `const CATALOG_SECTION_COLUMNS` — поле карточки (как в выгрузке) => колонка базы: `label`, `tileImage`, `visible`, `coverTitle`, `coverSub`, `covers`, `heading`, `headingSub`, `hasNotFound`, `seoTitle`, `seoDescription`
  - `catalog_section_value(string $field, mixed $value): string|int|null`
  - `catalog_create_section(PDO $db, string $login, string $label, DateTimeImmutable $now): string` — slug
  - `catalog_update_section(PDO $db, string $login, string $slug, array $fields, DateTimeImmutable $now): void` — `$fields`: поле => значение, только меняемые
  - `catalog_reorder_tiles(PDO $db, string $login, array $tileIds, DateTimeImmutable $now): void` — все id плиток в новом порядке

Раздел удалить нельзя — только скрыть (`visible`); функции удаления нет и не будет.

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/catalog_sections_test.php`:

```php
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
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/sections.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/catalog/sections.php`:

```php
<?php
/**
 * Разделы каталога и порядок плиток в сетке. Раздел нельзя удалить — только
 * скрыть: у страницы раздела копятся позиции в поиске, и она остаётся.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/export.php';

const CATALOG_SECTION_LABEL_MAX = 60;
const CATALOG_SECTION_TEXT_MAX = 300;
const CATALOG_COVERS_MAX = 3;
/** Картинки разделов — файлы сайта: плитки и обложки лежат в /images/site/, фото каталога — в /images/catalog/. */
const CATALOG_SITE_IMAGE = '~^/images/[A-Za-z0-9/._-]+\.webp$~';

/** Поля карточки раздела: имя в выгрузке => колонка в базе. */
const CATALOG_SECTION_COLUMNS = [
    'label' => 'label',
    'tileImage' => 'tile_image',
    'visible' => 'visible',
    'coverTitle' => 'cover_title',
    'coverSub' => 'cover_sub',
    'covers' => 'covers',
    'heading' => 'heading',
    'headingSub' => 'heading_sub',
    'hasNotFound' => 'has_not_found',
    'seoTitle' => 'seo_title',
    'seoDescription' => 'seo_description',
];

/** Проверяет значение поля раздела и приводит к виду для базы. */
function catalog_section_value(string $field, mixed $value): string|int|null
{
    if (!isset(CATALOG_SECTION_COLUMNS[$field])) {
        throw new InvalidArgumentException("Нет поля раздела: $field");
    }
    switch ($field) {
        case 'label':
            $label = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
            if ($label === '' || mb_strlen($label) > CATALOG_SECTION_LABEL_MAX) {
                throw new CatalogError('Название раздела — от 1 до 60 знаков.');
            }
            return $label;
        case 'visible':
        case 'hasNotFound':
            return $value ? 1 : 0;
        case 'tileImage':
            $image = trim((string)$value);
            if ($image !== '' && !preg_match(CATALOG_SITE_IMAGE, $image)) {
                throw new CatalogError('Фото плитки не с сайта — загрузите его заново.');
            }
            return $image;
        case 'covers':
            $covers = array_values(array_map('strval', (array)$value));
            if (count($covers) > CATALOG_COVERS_MAX) {
                throw new CatalogError('Фото обложки — не больше трёх.');
            }
            foreach ($covers as $cover) {
                if (!preg_match(CATALOG_SITE_IMAGE, $cover)) {
                    throw new CatalogError('Фото обложки не с сайта — загрузите его заново.');
                }
            }
            return json_encode($covers, CATALOG_JSON);
        case 'seoTitle':
        case 'seoDescription':
            // Пусто — значит, по шаблону: сайт подставит заголовок и описание сам.
            $seo = trim((string)$value);
            if (mb_strlen($seo) > CATALOG_SECTION_TEXT_MAX) {
                throw new CatalogError('Текст длиннее 300 знаков — сократите его.');
            }
            return $seo === '' ? null : $seo;
        default:
            $text = trim((string)$value);
            if (mb_strlen($text) > CATALOG_SECTION_TEXT_MAX) {
                throw new CatalogError('Текст длиннее 300 знаков — сократите его.');
            }
            return $text;
    }
}

/**
 * Новый раздел: slug из названия, уникальный и не совпадающий с адресами
 * сайта (иначе -2, -3); плитка встаёт в конец сетки.
 */
function catalog_create_section(PDO $db, string $login, string $label, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $label, $now): string {
        $label = (string)catalog_section_value('label', $label);
        $taken = $db->query('SELECT slug FROM sections')->fetchAll(PDO::FETCH_COLUMN);
        $base = catalog_slugify($label);
        $slug = $base;
        for ($n = 2; in_array($slug, $taken, true) || in_array($slug, CATALOG_RESERVED_SLUGS, true); $n++) {
            $slug = "$base-$n";
        }
        $db->prepare('INSERT INTO sections (slug, label, cover_title, heading, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$slug, $label, $label, mb_strtoupper($label), catalog_iso($now)]);
        $db->prepare("INSERT INTO tiles (position, type, section)
            VALUES ((SELECT COALESCE(MAX(position), 0) + 1 FROM tiles), 'section', ?)")
            ->execute([$slug]);
        catalog_audit($db, $login, $now, 'section', $slug, 'created', null, $label);
        catalog_touch($db, $now);
        return $slug;
    });
}

/** Карточка раздела: меняются только переданные поля, каждое изменённое — в журнал. */
function catalog_update_section(PDO $db, string $login, string $slug, array $fields, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $slug, $fields, $now): void {
        $q = $db->prepare('SELECT * FROM sections WHERE slug = ?');
        $q->execute([$slug]);
        $row = $q->fetch();
        if ($row === false) {
            throw new CatalogError('Такого раздела нет — обновите страницу.');
        }
        $sets = [];
        $args = [];
        foreach ($fields as $field => $value) {
            $new = catalog_section_value((string)$field, $value);
            $column = CATALOG_SECTION_COLUMNS[$field];
            $old = $row[$column];
            if ($old === $new) {
                continue;
            }
            $sets[] = "$column = ?";
            $args[] = $new;
            catalog_audit($db, $login, $now, 'section', $slug, (string)$field,
                $old === null ? null : (string)$old, $new === null ? null : (string)$new);
        }
        if ($sets === []) {
            return;
        }
        $args[] = catalog_iso($now);
        $args[] = $slug;
        $db->prepare('UPDATE sections SET ' . implode(', ', $sets) . ', updated_at = ? WHERE slug = ?')->execute($args);
        catalog_touch($db, $now);
    });
}

/** Новый порядок сетки: все плитки, и разделов, и постоянные. */
function catalog_reorder_tiles(PDO $db, string $login, array $tileIds, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $tileIds, $now): void {
        $current = array_map('intval', $db->query('SELECT id FROM tiles ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN));
        $wanted = array_map('intval', array_values($tileIds));
        $a = $current;
        $b = $wanted;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new CatalogError('Плитки за это время поменялись — откройте страницу заново.');
        }
        if ($current === $wanted) {
            return;
        }
        $set = $db->prepare('UPDATE tiles SET position = ? WHERE id = ?');
        foreach ($wanted as $i => $id) {
            $set->execute([$i + 1, $id]);
        }
        catalog_audit($db, $login, $now, 'tiles', '', 'order', implode(',', $current), implode(',', $wanted));
        catalog_touch($db, $now);
    });
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/catalog/sections.php tests/php/catalog_sections_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): разделы и порядок плиток

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Загрузка выгрузки в базу

**Files:**
- Create: `server-pay/catalog/import.php`
- Create: `server-pay/catalog/import-cli.php`
- Test: `tests/php/catalog_import_test.php`

**Interfaces:**
- Consumes: Task 3 (`catalog_export`, `catalog_export_version`, `catalog_touch`, `CATALOG_JSON`); в проверке — функции Tasks 4–7.
- Produces:
  - `catalog_import(PDO $db, array $export, DateTimeImmutable $now): array{sections: int, products: int, redirects: int}`
  - `php import-cli.php <выгрузка.json>` — код 0 и строки «Загружено: …», «Версия каталога: …»; ошибка — код 1

Загрузка нужна для переноса каталога (этап 3) и для проверки «туда-обратно»: выгрузка из загруженной базы должна совпасть с исходной до последнего байта версии.

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/catalog_import_test.php`:

```php
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

t_case('import-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $file = t_tmpdir() . '/export.json';
    $source = catalog_export(t_rich_catalog());
    file_put_contents($file, json_encode($source, CATALOG_JSON));
    [$code, $out] = t_catalog_cli("$scripts/catalog/import-cli.php", $home, [$file]);
    t_equal($code, 0, 'загрузка прошла');
    t_true(str_contains($out, 'букетов 2') && str_contains($out, $source['version']), 'печатает, сколько загружено, и версию');
    [$code] = t_catalog_cli("$scripts/catalog/import-cli.php", $home, [$file]);
    t_equal($code, 1, 'второй раз в ту же базу — отказ');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/import.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/catalog/import.php`:

```php
<?php
/**
 * Загрузка выгрузки в пустую базу: перенос нынешнего каталога (этап 3) и
 * проверка «туда-обратно» — выгрузка из загруженной базы даёт ту же версию.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/export.php';

/** @return array{sections: int, products: int, redirects: int} */
function catalog_import(PDO $db, array $export, DateTimeImmutable $now): array
{
    return catalog_tx($db, function () use ($db, $export, $now): array {
        foreach (['sections', 'tiles', 'products', 'redirects'] as $part) {
            if (!is_array($export[$part] ?? null)) {
                throw new CatalogError("В выгрузке нет части $part.");
            }
        }
        if (isset($export['version']) && $export['version'] !== catalog_export_version($export)) {
            throw new CatalogError('Выгрузка повреждена: версия не сходится с содержимым.');
        }
        if ((int)$db->query('SELECT (SELECT COUNT(*) FROM sections) + (SELECT COUNT(*) FROM products)')->fetchColumn() > 0) {
            throw new CatalogError('База не пустая — загружать можно только в пустую.');
        }
        $at = catalog_iso($now);

        $inGrid = [];
        foreach ($export['tiles'] as $tile) {
            if ($tile['type'] === 'section') {
                $inGrid[] = $tile['slug'];
            }
        }
        $addSection = $db->prepare('INSERT INTO sections (slug, label, tile_image, visible, cover_title, cover_sub,
                covers, heading, heading_sub, has_not_found, seo_title, seo_description, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $members = [];
        foreach ($export['sections'] as $s) {
            if ((bool)$s['visible'] !== in_array($s['slug'], $inGrid, true)) {
                throw new CatalogError("Раздел {$s['slug']}: видимость не совпадает с плитками сетки.");
            }
            $addSection->execute([
                $s['slug'], $s['label'], $s['tileImage'], $s['visible'] ? 1 : 0, $s['coverTitle'], $s['coverSub'],
                json_encode($s['covers'], CATALOG_JSON), $s['heading'], $s['headingSub'], $s['hasNotFound'] ? 1 : 0,
                $s['seoTitle'], $s['seoDescription'], $at,
            ]);
            foreach ($s['products'] as $position => $uid) {
                $members[] = [$uid, $s['slug'], $position];
            }
        }

        $addTile = $db->prepare('INSERT INTO tiles (position, type, section, label, href, image) VALUES (?, ?, ?, ?, ?, ?)');
        $position = 0;
        foreach ($export['tiles'] as $t) {
            $position++;
            if ($t['type'] === 'section') {
                $addTile->execute([$position, 'section', $t['slug'], '', '', '']);
            } else {
                $addTile->execute([$position, $t['type'], null, $t['label'], $t['href'], $t['image']]);
            }
        }
        // Скрытых разделов в сетке нет — их плитки встают в конец: покажут раздел — плитка появится там.
        foreach ($export['sections'] as $s) {
            if (!$s['visible']) {
                $addTile->execute([++$position, 'section', $s['slug'], '', '', '']);
            }
        }

        $listedIn = [];
        foreach ($members as [$uid, $section]) {
            $listedIn[$uid][] = $section;
        }
        $addProduct = $db->prepare("INSERT INTO products (uid, slug, slug_pinned, title, description, price, images,
                main_section, status, status_changed_at, created_at, updated_at, updated_by, published_at)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?)");
        foreach ($export['products'] as $p) {
            if (!in_array($p['status'], ['active', 'hidden'], true)) {
                throw new CatalogError("Букет {$p['uid']}: в выгрузке не может быть статуса {$p['status']}.");
            }
            if (!in_array($p['mainSection'], $listedIn[$p['uid']] ?? [], true)) {
                throw new CatalogError("Букет {$p['uid']}: его нет в его главном разделе {$p['mainSection']}.");
            }
            $addProduct->execute([
                $p['uid'], $p['slug'], $p['title'], $p['description'], $p['price'],
                json_encode($p['images'], CATALOG_JSON), $p['mainSection'], $p['status'], $at, $at, $at, $at,
            ]);
        }
        $addMember = $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)');
        foreach ($members as $member) {
            $addMember->execute($member);
        }
        $addRedirect = $db->prepare('INSERT INTO redirects (from_path, to_path, created_at) VALUES (?, ?, ?)');
        foreach ($export['redirects'] as $r) {
            $addRedirect->execute([$r['from'], $r['to'], $at]);
        }

        catalog_audit($db, 'import', $now, 'catalog', '', 'imported', null, count($export['products']) . ' букетов');
        catalog_touch($db, $now);
        return [
            'sections' => count($export['sections']),
            'products' => count($export['products']),
            'redirects' => count($export['redirects']),
        ];
    });
}
```

Create `server-pay/catalog/import-cli.php`:

```php
<?php
/**
 * Загрузка выгрузки каталога в пустую базу на сервере:
 *
 *   php import-cli.php /путь/к/выгрузке.json
 *
 * Печатает, сколько загружено, и версию — она должна совпасть с версией в
 * файле. В непустую базу не загружает.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/import.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, 'Использование: php import-cli.php <выгрузка.json>' . PHP_EOL);
    exit(2);
}
try {
    $export = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $db = catalog_db_open(catalog_db_path());
    $counts = catalog_import($db, $export, new DateTimeImmutable());
    $version = catalog_export($db)['version'];
    printf(
        "Загружено: разделов %d, букетов %d, переадресаций %d.\nВерсия каталога: %s\n",
        $counts['sections'],
        $counts['products'],
        $counts['redirects'],
        $version,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Не загружено: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/catalog/import.php server-pay/catalog/import-cli.php tests/php/catalog_import_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): загрузка выгрузки в пустую базу, проверка туда-обратно

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Учётные записи и копии базы

**Files:**
- Create: `server-pay/catalog/users.php`, `server-pay/catalog/user-cli.php`
- Create: `server-pay/catalog/backup.php`, `server-pay/catalog/backup-cli.php`
- Test: `tests/php/catalog_users_test.php`, `tests/php/catalog_backup_test.php`

**Interfaces:**
- Consumes: Task 1 (`catalog_tx`, `catalog_iso`, `catalog_audit`, `catalog_home`, `catalog_db_path`, `CatalogError`).
- Produces:
  - `const CATALOG_PASSWORD_ALPHABET`, `const CATALOG_PASSWORD_LENGTH = 12`
  - `catalog_new_password(): string`
  - `catalog_user_add(PDO $db, string $login, string $name, DateTimeImmutable $now): string` — начальный пароль
  - `catalog_user_reset(PDO $db, string $login, DateTimeImmutable $now): string` — новый пароль
  - `catalog_users(PDO $db): list<array{login: string, name: string, must_change: int}>`
  - `const CATALOG_BACKUP_DAYS = 30`, `catalog_backup(PDO $db, string $dir, DateTimeImmutable $now): string` — путь сегодняшней копии
  - `php user-cli.php add <логин> "<Имя>" | reset <логин> | list`; `php backup-cli.php`

Пароль печатается только на экран и только один раз; в журнал, в базу (кроме хэша) и в файлы он не попадает.

- [ ] **Step 1: Проверки (пока падают)**

Create `tests/php/catalog_users_test.php`:

```php
<?php
/**
 * Учётные записи админки: заводит разработчик, хранится только хэш пароля.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/users.php';

t_case('учётная запись', function (): void {
    $db = t_catalog_db();
    $password = catalog_user_add($db, 'anna', 'Анна', t_now());
    t_true((bool)preg_match('/^[a-hjkmnp-zA-HJ-NP-Z2-9]{12}$/', $password), 'пароль — 12 знаков без похожих (0/O, 1/l/I)');
    $u = $db->query("SELECT * FROM users WHERE login = 'anna'")->fetch();
    t_equal([$u['name'], $u['must_change']], ['Анна', 1], 'имя сохранено; при первом входе админка попросит сменить пароль');
    t_true(password_verify($password, $u['password_hash']), 'хранится хэш, пароль к нему подходит');
    t_throws(fn () => catalog_user_add($db, 'anna', 'Другая Анна', t_now()), CatalogError::class, 'логин занят');
    t_throws(fn () => catalog_user_add($db, 'Анна', 'Анна', t_now()), CatalogError::class, 'логин — только латиницей');

    $new = catalog_user_reset($db, 'anna', t_now('+1 day'));
    $u = $db->query("SELECT * FROM users WHERE login = 'anna'")->fetch();
    t_true(password_verify($new, $u['password_hash']) && !password_verify($password, $u['password_hash']), 'новый пароль подходит, старый — нет');
    t_equal($u['must_change'], 1, 'после сброса тоже попросит сменить');
    t_throws(fn () => catalog_user_reset($db, 'olga', t_now()), CatalogError::class, 'сброс несуществующей записи');

    $log = implode(' ', $db->query("SELECT COALESCE(old_value, '') || ' ' || COALESCE(new_value, '') FROM audit")->fetchAll(PDO::FETCH_COLUMN));
    t_true(!str_contains($log, $password) && !str_contains($log, $new), 'пароли в журнал не попадают');
    t_equal(catalog_users($db), [['login' => 'anna', 'name' => 'Анна', 'must_change' => 1]], 'список учётных записей');
});

t_case('user-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['add', 'olga', 'Olga']);
    t_equal($code, 0, 'учётная запись создана');
    t_true((bool)preg_match('/[a-hjkmnp-zA-HJ-NP-Z2-9]{12}/', $out), 'пароль напечатан');
    [$code, $out] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['list']);
    t_true($code === 0 && str_contains($out, 'olga'), 'список показывает логин');
    [$code] = t_catalog_cli("$scripts/catalog/user-cli.php", $home, ['add', 'olga', 'Olga']);
    t_equal($code, 1, 'повторное создание — ошибка');
});
```

Create `tests/php/catalog_backup_test.php`:

```php
<?php
/**
 * Копии базы: одна в день, хранятся 30 дней.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/backup.php';

/** Сколько разделов в файле базы. */
function t_sections_in(string $file): int
{
    $copy = new PDO('sqlite:' . $file);
    return (int)$copy->query('SELECT COUNT(*) FROM sections')->fetchColumn();
}

t_case('копия базы', function (): void {
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $dir = "$home/backups";
    mkdir($dir);
    file_put_contents("$dir/catalog-2026-09-04.sqlite", 'старше 30 дней');
    file_put_contents("$dir/catalog-2026-09-05.sqlite", 'ровно 30 дней');
    file_put_contents("$dir/notes.txt", 'чужой файл');

    $file = catalog_backup($db, $dir, t_now());
    t_equal($file, "$dir/catalog-2026-10-05.sqlite", 'копия на сегодня');
    t_equal(t_sections_in($file), 3, 'в копии те же данные');

    $db->exec('DELETE FROM tiles');
    $db->exec('DELETE FROM sections');
    catalog_backup($db, $dir, t_now('+3 hours'));
    t_equal(t_sections_in($file), 3, 'второй запуск в тот же день копию не перезаписывает');

    $left = array_map('basename', glob("$dir/*") ?: []);
    sort($left);
    t_equal($left, ['catalog-2026-09-05.sqlite', 'catalog-2026-10-05.sqlite', 'notes.txt'], 'копии старше 30 дней удалены, остальное не тронуто');
});

t_case('backup-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/backup-cli.php", $home);
    t_equal([$code, str_contains($out, 'ещё нет')], [0, true], 'базы нет — спокойно выходит');
    t_catalog_db("$home/catalog.sqlite");
    [$code] = t_catalog_cli("$scripts/catalog/backup-cli.php", $home);
    t_equal([$code, count(glob("$home/backups/catalog-*.sqlite") ?: [])], [0, 1], 'база есть — копия сделана');
});
```

- [ ] **Step 2: Убедиться, что падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/backup.php'`.

- [ ] **Step 3: Реализация учётных записей**

Create `server-pay/catalog/users.php`:

```php
<?php
/**
 * Учётные записи админки. Заводит их разработчик (user-cli.php): скрипт сам
 * придумывает начальный пароль и показывает его один раз. В базе — только
 * хэш (password_hash); при первом входе админка просит сменить пароль.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Без похожих знаков (0/O, 1/l/I) — пароль диктуют и переписывают с экрана. */
const CATALOG_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const CATALOG_PASSWORD_LENGTH = 12;

function catalog_new_password(): string
{
    $password = '';
    for ($i = 0; $i < CATALOG_PASSWORD_LENGTH; $i++) {
        $password .= CATALOG_PASSWORD_ALPHABET[random_int(0, strlen(CATALOG_PASSWORD_ALPHABET) - 1)];
    }
    return $password;
}

/** Новая учётная запись; возвращает начальный пароль. */
function catalog_user_add(PDO $db, string $login, string $name, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $name, $now): string {
        if (!preg_match('/^[a-z0-9._-]{2,32}$/', $login)) {
            throw new CatalogError('Логин — строчная латиница, цифры, точка, дефис, подчёркивание; от 2 до 32 знаков.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new CatalogError('Имя — от 1 до 60 знаков.');
        }
        $q = $db->prepare('SELECT 1 FROM users WHERE login = ?');
        $q->execute([$login]);
        if ($q->fetchColumn() !== false) {
            throw new CatalogError("Логин $login уже занят.");
        }
        $password = catalog_new_password();
        $at = catalog_iso($now);
        $db->prepare('INSERT INTO users (login, name, password_hash, must_change, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)')
            ->execute([$login, $name, password_hash($password, PASSWORD_DEFAULT), $at, $at]);
        catalog_audit($db, 'console', $now, 'user', $login, 'created', null, $name);
        return $password;
    });
}

/** Новый пароль вместо забытого; при входе админка попросит его сменить. */
function catalog_user_reset(PDO $db, string $login, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $now): string {
        $password = catalog_new_password();
        $q = $db->prepare('UPDATE users SET password_hash = ?, must_change = 1, updated_at = ? WHERE login = ?');
        $q->execute([password_hash($password, PASSWORD_DEFAULT), catalog_iso($now), $login]);
        if ($q->rowCount() === 0) {
            throw new CatalogError("Учётной записи $login нет.");
        }
        catalog_audit($db, 'console', $now, 'user', $login, 'password', null, 'сброшен');
        return $password;
    });
}

/** @return list<array{login: string, name: string, must_change: int}> */
function catalog_users(PDO $db): array
{
    return $db->query('SELECT login, name, must_change FROM users ORDER BY login')->fetchAll();
}
```

Create `server-pay/catalog/user-cli.php`:

```php
<?php
/**
 * Учётные записи админки — их заводит разработчик в Shell-клиенте ISPmanager:
 *
 *   php user-cli.php add <логин> "<Имя>"   новая запись, пароль печатается один раз
 *   php user-cli.php reset <логин>          новый пароль, печатается один раз
 *   php user-cli.php list                   кто есть
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/users.php';

[$command, $login, $name] = array_pad(array_slice($argv, 1), 3, '');
try {
    $db = catalog_db_open(catalog_db_path());
    switch ($command) {
        case 'add':
            $password = catalog_user_add($db, $login, $name, new DateTimeImmutable());
            echo "Учётная запись $login создана. Пароль (показывается один раз): $password", PHP_EOL;
            break;
        case 'reset':
            $password = catalog_user_reset($db, $login, new DateTimeImmutable());
            echo "Новый пароль для $login (показывается один раз): $password", PHP_EOL;
            break;
        case 'list':
            foreach (catalog_users($db) as $user) {
                echo $user['login'], ' — ', $user['name'], $user['must_change'] ? ' (начальный пароль ещё не сменён)' : '', PHP_EOL;
            }
            break;
        default:
            fwrite(STDERR, 'Использование: php user-cli.php add <логин> "<Имя>" | reset <логин> | list' . PHP_EOL);
            exit(2);
    }
} catch (CatalogError $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
```

- [ ] **Step 4: Реализация копий**

Create `server-pay/catalog/backup.php`:

```php
<?php
/**
 * Копия базы раз в сутки: VACUUM INTO даёт целую копию, даже если в эту
 * секунду кто-то сохраняет букет. Копии старше 30 дней удаляются.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CATALOG_BACKUP_DAYS = 30;

/** Копия на сегодня (если её ещё нет) и уборка старых; возвращает путь сегодняшней. */
function catalog_backup(PDO $db, string $dir, DateTimeImmutable $now): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку копий: $dir");
    }
    $today = $now->setTimezone(new DateTimeZone(CATALOG_TZ));
    $file = $dir . '/catalog-' . $today->format('Y-m-d') . '.sqlite';
    if (!is_file($file)) {
        $db->prepare('VACUUM INTO ?')->execute([$file]);
    }
    $oldest = $today->modify('-' . CATALOG_BACKUP_DAYS . ' days')->format('Y-m-d');
    foreach (glob($dir . '/catalog-*.sqlite') ?: [] as $old) {
        if (preg_match('/catalog-(\d{4}-\d{2}-\d{2})\.sqlite$/', $old, $m) && $m[1] < $oldest) {
            unlink($old);
        }
    }
    return $file;
}
```

Create `server-pay/catalog/backup-cli.php`:

```php
<?php
/**
 * Ежедневная копия базы каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/backup-cli.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/backup.php';

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — копировать нечего.', PHP_EOL;
    exit(0);
}
try {
    echo 'Копия: ', catalog_backup(catalog_db_open($file), catalog_home() . '/backups', new DateTimeImmutable()), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Копия не сделана: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

- [ ] **Step 5: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/catalog/users.php server-pay/catalog/user-cli.php server-pay/catalog/backup.php server-pay/catalog/backup-cli.php tests/php/catalog_users_test.php tests/php/catalog_backup_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): учётные записи и ежедневные копии базы

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Документация и проверка ветки

**Files:**
- Create: `docs/catalog.md`

**Interfaces:**
- Consumes: всё из Tasks 1–9.
- Produces: инструкция для разработчика; ветка `catalog-server` на GitHub с зелёной проверкой CI.

- [ ] **Step 1: Инструкция**

Create `docs/catalog.md`:

````markdown
# Каталог на сервере

База каталога, правила данных и выгрузка — этап 2А админки
(`docs/superpowers/plans/2026-10-07-catalog-server.md`). Экранов админки пока
нет (этап 2Б), сайт по-прежнему собирается из файлов репозитория
(переключение — этап 3).

## Где что лежит

- База: `/var/www/u3620798/data/pion-catalog/catalog.sqlite` — вне веб-корня,
  через сайт её не скачать.
- Копии: `/var/www/u3620798/data/pion-catalog/backups/catalog-ГГГГ-ММ-ДД.sqlite`,
  хранятся 30 дней.
- Код: `pay/catalog/` (исходники — `server-pay/catalog/`). Папка закрыта от
  веба своим `.htaccess`; наружу смотрит только `pay/catalog-export.php`.

## Команды

В Shell-клиенте ISPmanager:

```bash
cd /var/www/u3620798/data/www/pionperm.ru/pay/catalog
php user-cli.php add anna "Анна"      # новая учётная запись; пароль печатается один раз
php user-cli.php reset anna           # новый пароль; печатается один раз
php user-cli.php list                 # кто есть
php import-cli.php /путь/к/выгрузке.json   # загрузка каталога — только в пустую базу
php backup-cli.php                    # копия базы на сегодня
```

Пароль после `add` и `reset` нужно сразу передать сотруднику и закрыть экран
(`clear`): он нигде не сохраняется, кроме хэша в базе.

## Выгрузка

`https://pionperm.ru/pay/catalog-export.php` — весь каталог одним JSON
(формат — спецификация админки, раздел 3). Пока каталог не загружен,
отвечает 503 и `{"error":"Каталог ещё не создан"}`: сборка не должна принять
пустоту за «все букеты удалены».

## Расписание

Копия раз в сутки — задание добавить на этапе 3, когда в базе появится
настоящий каталог:

```
15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/backup-cli.php
```
````

- [ ] **Step 2: Все проверки**

Run: `npm test && /c/php82/php.exe tests/php/run.php && find server-pay -name '*.php' -print0 | xargs -0 -n1 /c/php82/php.exe -l | grep -v '^No syntax errors'`
Expected: vitest — все PASS; PHP — `не прошло: 0`; `php -l` — пустой вывод от grep (ошибок синтаксиса нет).

- [ ] **Step 3: Коммит и отправка ветки**

```bash
git add docs/catalog.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "docs: каталог на сервере — где лежит, команды, выгрузка

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin catalog-server
```

- [ ] **Step 4: CI на ветке зелёный**

Run (через минуту-две после отправки; `gh` на машине нет, репозиторий публичный):

```bash
curl -s "https://api.github.com/repos/kidw3st/pion/actions/runs?branch=catalog-server&per_page=3" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{for(const r of JSON.parse(s).workflow_runs||[])console.log(r.head_sha.slice(0,7),r.name,r.status,r.conclusion)})"
```

Expected: запуск «Проверка сборки» для последнего коммита ветки — `completed success`. Пока `in_progress` — повторить позже.

---

## После слияния в master

Слияние — после финальной проверки (superpowers:finishing-a-development-branch). Автовыкладка увезёт `/pay/` на сервер за 5–20 минут. Проверить снаружи, по одному запросу на адрес (хостинг банит IP за частые обращения):

```bash
curl -s -w ' %{http_code}\n' https://pionperm.ru/pay/catalog-export.php
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/pay/catalog/db.php
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/pay/catalog/user-cli.php
```

Expected: `{"error":"Каталог ещё не создан"} 503`, затем `403` и `403`.
