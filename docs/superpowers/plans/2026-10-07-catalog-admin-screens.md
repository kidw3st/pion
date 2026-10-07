# Экраны админки каталога — план (этап 2Б)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сотрудники салона входят в `https://pionperm.ru/pay/admin/` с телефона и сами ведут каталог: букеты (список, поиск, карточка, фото, статусы), разделы и порядок плиток, журнал изменений, свой пароль; вверху каждого экрана — на сайте ли сохранённое.

**Architecture:** Чистый PHP поверх базы этапа 2А (`server-pay/catalog/`). Страницы рисуются на сервере; страница — функция «запрос → ответ» над обычными массивами, поэтому каждая проверяется без веб-сервера, а настоящие `$_POST`, куки и заголовки трогают только `admin_request_from_globals()` и `admin_emit()`. Сессии — случайный токен в куке, в базе только его sha256 (таблица `sessions`, схема базы v2). JavaScript — один файл для уменьшения и порядка фото; без него работает всё, кроме загрузки фото. Изменения на сайт пока не попадают: сборка из выгрузки — этап 3, строка статуса это честно говорит.

**Tech Stack:** PHP 8.2/8.3 (`pdo_sqlite`, `sqlite3`, GD с WebP), SQLite 3.26 на сервере, самописные проверки `tests/php/run.php`, встроенный веб-сервер PHP для сквозной проверки, ванильный JS (canvas).

Спецификация: `docs/superpowers/specs/2026-10-05-catalog-admin-design.md` — разделы «Админка», «Безопасность», «Журнал, копии, сбои». База и правила — этап 2А (`docs/superpowers/plans/2026-10-07-catalog-server.md`, `docs/catalog.md`). Остатки проверок 2А, которые закрывает этот план, — `docs/known-follow-ups.md`, раздел «Каталог на сервере».

**Факты сервера (проверено 2026-10-07 в Shell-клиенте):** AlmaLinux 8.10; `/usr/bin/php` 8.2.33 и PHP сайта 8.3.31 — оба с **SQLite 3.26.0**, `gd`, `pdo_sqlite`, `sqlite3`; `open_basedir` нет; всё работает от пользователя `u3620798`. Локальный PHP и CI работают на свежем SQLite (3.4x–3.5x) — поэтому Task 1 заводит проверку, не пускающую в код SQL новее 3.26.

## Global Constraints

- PHP: консоль на сервере 8.2, сайт 8.3 — код совместим с 8.2. В каждом PHP-файле `declare(strict_types=1);`. Без composer.
- **SQLite на сервере 3.26.0**: никакого SQL новее (`VACUUM INTO`, `RETURNING`, `DROP COLUMN`, `IIF`, `NULLS FIRST/LAST`, `->>`, …). Это проверяет `tests/php/catalog_sqlite326_test.php`.
- Локальный PHP: `/c/php82/php.exe` (в PATH нет). Проверки: `/c/php82/php.exe tests/php/run.php` (в конце — `не прошло: 0`, без предупреждений PHP).
- Работа — в ветке `catalog-admin` (master — боевой сайт); в master — после финальной проверки.
- Коммиты — только так (глобальной git-личности нет), строка соавторства — ровно эта:
  `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit …` и в конце сообщения `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Адрес админки — `https://pionperm.ru/pay/admin/` (`ADMIN_BASE = '/pay/admin/'`). Ссылок на неё на сайте нет.
- Безопасность (спецификация, дословно): «Пароли хранятся только как хэш (password_hash). 5 неверных попыток за 10 минут — вход заблокирован на 10 минут, отдельно по IP и по логину. Сессия: cookie HttpOnly, Secure, SameSite=Strict; новый идентификатор при входе; выход после 12 часов без действий. Каждая форма с CSRF-токеном. Изменения — только POST. Тексты экранируются при выводе. Запросы к SQLite — только с параметрами. Фото пересохраняются через GD: от исходного файла остаётся только картинка.»
- Все ответы админки — с `X-Robots-Tag: noindex, nofollow` и `Cache-Control: no-store`.
- Телефон прежде всего: одна колонка, кнопки не ниже 44 px. Цвета сайта: акцент `#b08d7a`, мягкий фон `#f6efe9`, шапка `#d2b8a4`, текст `#2a2928`, рамки `#d6d6d6`.
- Никаких `style="…"` и встроенных `<script>`: политика безопасности (CSP) пускает только свои файлы `assets/admin.css` и `assets/photo.js`.
- Фото (спецификация): «Браузер уменьшает снимок до 2000 px по длинной стороне и отправляет JPEG, каждый снимок отдельным запросом. Сервер открывает файл через GD (не открылся как изображение — отказ), уменьшает до 900 px по ширине и сохраняет WebP качества 82. Имя файла — `<slug>-<uid>-<8 знаков хэша>.webp` в папке `images/catalog/<главный раздел на момент загрузки>/`.» Обложки разделов — до 1600 px (как нынешние).
- Цена (спецификация): «Пустая, меньше 100 ₽ или больше 300 000 ₽ — не сохраняется. Если цена изменилась больше чем вдвое, админка переспрашивает: «Цена была 4 400 ₽, станет 44 000 ₽. Всё верно?».»
- Тексты — по-русски, для сотрудников салона, без технических слов. Комментарии в коде — по-русски, объясняют «почему».
- Проверки: каждый случай — внутри `t_case('…', function (): void { … });`.

## Файлы

| Файл | За что отвечает |
|---|---|
| `server-pay/catalog/backup.php` (правка) | Копия базы через `SQLite3::backup()` — без `VACUUM INTO` |
| `server-pay/catalog/db.php` (правка) | Схема v2: таблица `sessions` |
| `server-pay/catalog/photo-files.php` | Корзина фото: `images/catalog/_deleted/` |
| `server-pay/catalog/maintenance.php`, `maintenance-cli.php` | Ежедневно: копия базы и уборка фото без ссылок |
| `server-pay/admin/lib/.htaccess` | Закрывает библиотеку админки от веба |
| `server-pay/admin/lib/http.php` | Запрос и ответ как массивы, заголовки безопасности, переходы |
| `server-pay/admin/lib/view.php` | Каркас страницы, `h()`, деньги и даты, сообщения |
| `server-pay/admin/lib/auth.php` | Вход, подбор пароля, сессии, CSRF, смена пароля |
| `server-pay/admin/lib/app.php` | Окружение и пропуск на страницы (`admin_handle`, `admin_run`) |
| `server-pay/admin/lib/status.php` | Строка статуса выкладки и отметки «на сайте / ждёт выкладки» |
| `server-pay/admin/lib/queries.php` | Чтение для экранов: разделы по сетке, букеты для списка |
| `server-pay/admin/lib/forms.php` | Поля букета из формы, цена, «больше чем вдвое» |
| `server-pay/admin/lib/photos.php` | GD → WebP, запись фото, виджет фото |
| `server-pay/admin/lib/pages-auth.php` | Экраны: вход, выход, смена пароля |
| `server-pay/admin/lib/pages-products.php` | Экраны: список букетов, карточка, действия со статусом |
| `server-pay/admin/lib/pages-photos.php` | Приём фото (`photo.php`, JSON) |
| `server-pay/admin/lib/pages-sections.php` | Экраны: разделы и порядок плиток, карточка раздела |
| `server-pay/admin/lib/pages-log.php` | Журнал и история в карточке |
| `server-pay/admin/*.php` | Точки входа: `index`, `product`, `photo`, `sections`, `section`, `log`, `login`, `logout`, `password` |
| `server-pay/admin/assets/admin.css`, `photo.js` | Оформление; уменьшение и порядок фото |
| `tests/php/admin_fixture.php` | Общее для проверок админки |
| `tests/php/admin_*_test.php`, `catalog_sqlite326_test.php`, `catalog_maintenance_test.php` | Проверки |
| `docs/catalog.md` (правка) | Как пользоваться админкой и обслуживанием |

## Не входит в этот план

- **Этап 3:** выгрузка в сборке сайта (`deploy.yml`); запись выложенной версии каталога в `pion-deploy/state.json` — поля `current.catalogVersion` и `current.catalogChangedAt` (их читает `status.php`, контракт описан там); сторож «выкладка задерживается»; перенос каталога в базу; учётные записи сотрудников и задание расписания для `maintenance-cli.php`.
- **2В:** страницы сайта из выгрузки (разделы из данных, снятые букеты, переадресации, «Новинки»).
- Добавление сотрудников из админки (спецификация: «Не входит в объём»).

---

### Task 1: Копии базы на SQLite 3.26 и защита от SQL новее 3.26

**Files:**
- Modify: `server-pay/catalog/backup.php` (вся функция `catalog_backup` — по-новому, подпись меняется)
- Modify: `server-pay/catalog/backup-cli.php` (вызов)
- Modify: `tests/php/catalog_backup_test.php` (вызовы — с путём к базе, новый случай)
- Create: `tests/php/catalog_sqlite326_test.php`

**Interfaces:**
- Produces: `catalog_backup(string $dbFile, string $dir, DateTimeImmutable $now): string` — путь сегодняшней копии (раньше первым параметром был `PDO`).

Почему: `VACUUM INTO` появился в SQLite 3.27, а на сервере 3.26.0 — копии там не делались бы вовсе, а проверки (локально 3.53) этого не видят. `SQLite3::backup()` — штатное онлайн-копирование SQLite, работает на любой версии; расширение `sqlite3` на сервере есть.

- [ ] **Step 1: Ветка**

```bash
git switch master && git pull --ff-only && git switch -c catalog-admin
```

- [ ] **Step 2: Проверки (пока падают)**

Replace the whole file `tests/php/catalog_backup_test.php` with:

```php
<?php
/**
 * Копии базы: одна в день, хранятся 30 дней. Делаются через SQLite3::backup —
 * на сервере SQLite 3.26, а копирование командой VACUUM в файл там ещё нет.
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

    $file = catalog_backup("$home/catalog.sqlite", $dir, t_now());
    t_equal($file, "$dir/catalog-2026-10-05.sqlite", 'копия на сегодня');
    t_equal(t_sections_in($file), 3, 'в копии те же данные');

    $db->exec('DELETE FROM tiles');
    $db->exec('DELETE FROM sections');
    catalog_backup("$home/catalog.sqlite", $dir, t_now('+3 hours'));
    t_equal(t_sections_in($file), 3, 'второй запуск в тот же день копию не перезаписывает');

    $left = array_map('basename', glob("$dir/*") ?: []);
    sort($left);
    t_equal($left, ['catalog-2026-09-05.sqlite', 'catalog-2026-10-05.sqlite', 'notes.txt'], 'копии старше 30 дней удалены, остальное не тронуто');
});

t_case('копия во время записи', function (): void {
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    // Кто-то в эту секунду сохраняет букет: копия всё равно целая — с тем, что уже сохранено.
    $db->exec('BEGIN IMMEDIATE');
    $db->exec("INSERT INTO meta (key, value) VALUES ('uncommitted', '1')");
    $file = catalog_backup("$home/catalog.sqlite", "$home/backups", t_now());
    $db->exec('ROLLBACK');
    t_equal(t_sections_in($file), 3, 'копия открывается и содержит сохранённое');
    $copy = new PDO('sqlite:' . $file);
    t_equal((int)$copy->query("SELECT COUNT(*) FROM meta WHERE key = 'uncommitted'")->fetchColumn(), 0, 'несохранённого в копии нет');
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

t_case('копия после прерывания', function (): void {
    $home = t_tmpdir();
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $dir = "$home/backups";
    mkdir($dir);
    $file = "$dir/catalog-2026-10-05.sqlite";
    $part = "$file.part";
    // Остаток прерванного копирования: на сегодняшнюю копию он не похож и не мешает.
    file_put_contents($part, 'прерванная копия');

    t_equal(catalog_backup("$home/catalog.sqlite", $dir, t_now()), $file, 'возвращено финальное имя');
    t_true(is_file($file) && !is_file($part), 'финальный файл создан, остаток убран');
    t_equal(t_sections_in($file), 3, 'финальный файл — настоящая база с данными');
});
```

Create `tests/php/catalog_sqlite326_test.php`:

```php
<?php
/**
 * На сервере SQLite 3.26 (AlmaLinux 8), а локально и в CI — свежий. Запрос с
 * конструкцией новее 3.26 прошёл бы здесь все проверки и упал бы только на
 * сервере. Эта проверка ищет такие конструкции в коде /pay/ — без
 * комментариев: в них о новых конструкциях писать можно.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';

/** Код файла без комментариев. */
function t_code_without_comments(string $path): string
{
    $code = '';
    foreach (token_get_all((string)file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $code .= $token[1];
            }
        } else {
            $code .= $token;
        }
    }
    return $code;
}

t_case('SQL не новее SQLite 3.26', function (): void {
    $newer = [
        'VACUUM INTO (3.27)' => '/\bVACUUM\s+INTO\b/i',
        'RETURNING (3.35)' => '/\bRETURNING\b/i',
        'DROP COLUMN (3.35)' => '/\bDROP\s+COLUMN\b/i',
        'MATERIALIZED (3.35)' => '/\bMATERIALIZED\b/i',
        'IIF (3.32)' => '/\bIIF\s*\(/i',
        'NULLS FIRST/LAST (3.30)' => '/\bNULLS\s+(FIRST|LAST)\b/i',
        'FILTER (WHERE …) (3.30)' => '/\bFILTER\s*\(\s*WHERE\b/i',
        'GENERATED ALWAYS (3.31)' => '/\bGENERATED\s+ALWAYS\b/i',
        'UNIXEPOCH (3.38)' => '/\bUNIXEPOCH\s*\(/i',
        'JSON ->> (3.38)' => '/->>/',
        'STRICT-таблицы (3.37)' => '/\)\s*STRICT\b/i',
    ];
    $root = dirname(__DIR__, 2) . '/server-pay';
    $found = [];
    $checked = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $checked++;
        $code = t_code_without_comments($file->getPathname());
        foreach ($newer as $what => $pattern) {
            if (preg_match($pattern, $code)) {
                $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1) . ': ' . $what;
            }
        }
    }
    t_true($checked > 10, 'файлы /pay/ найдены');
    t_equal($found, [], 'в коде /pay/ нет SQL новее SQLite 3.26');
});
```

- [ ] **Step 3: Убедиться, что падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: в `catalog_backup_test.php` — фатальная ошибка `TypeError … catalog_backup(): Argument #1 ($db) must be of type PDO, string given`.
Если временно убрать `catalog_backup_test.php` из запуска (не нужно — просто прочитать код), `catalog_sqlite326_test.php` показал бы `catalog/backup.php: VACUUM INTO (3.27)`.

- [ ] **Step 4: Реализация**

Replace the whole file `server-pay/catalog/backup.php` with:

```php
<?php
/**
 * Копия базы раз в сутки. На сервере SQLite 3.26, где копирования командой
 * VACUUM в файл ещё нет, поэтому копия делается штатным онлайн-копированием
 * SQLite (SQLite3::backup): она целая, даже если в эту секунду кто-то
 * сохраняет букет. Копия пишется во временный файл и получает своё имя только
 * целой. Копии старше 30 дней удаляются.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CATALOG_BACKUP_DAYS = 30;

/** Копия на сегодня (если её ещё нет) и уборка старых; возвращает путь сегодняшней. */
function catalog_backup(string $dbFile, string $dir, DateTimeImmutable $now): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку копий: $dir");
    }
    $today = $now->setTimezone(new DateTimeZone(CATALOG_TZ));
    $file = $dir . '/catalog-' . $today->format('Y-m-d') . '.sqlite';
    if (!is_file($file)) {
        $part = $file . '.part';
        if (is_file($part)) {
            unlink($part);
        }
        $source = null;
        $target = null;
        try {
            $source = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $source->busyTimeout(5000);
            $target = new SQLite3($part);
            if (!$source->backup($target)) {
                throw new RuntimeException('Копирование базы не удалось: ' . $source->lastErrorMsg());
            }
        } catch (Throwable $e) {
            $target?->close();
            $source?->close();
            if (is_file($part)) {
                unlink($part);
            }
            throw $e;
        }
        $target->close();
        $source->close();
        if (!rename($part, $file)) {
            if (is_file($part)) {
                unlink($part);
            }
            throw new RuntimeException("Не переименовать копию базы: $part");
        }
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

In `server-pay/catalog/backup-cli.php` replace the line

```php
    echo 'Копия: ', catalog_backup(catalog_db_open($file), catalog_home() . '/backups', new DateTimeImmutable()), PHP_EOL;
```

with

```php
    echo 'Копия: ', catalog_backup($file, catalog_home() . '/backups', new DateTimeImmutable()), PHP_EOL;
```

- [ ] **Step 5: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`, без предупреждений PHP.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/catalog/backup.php server-pay/catalog/backup-cli.php tests/php/catalog_backup_test.php tests/php/catalog_sqlite326_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "fix(catalog): копии базы через SQLite3::backup — на сервере SQLite 3.26

VACUUM INTO появился в 3.27, на сервере 3.26.0: копии там не делались бы.
Проверка catalog_sqlite326_test.php не пускает в код /pay/ SQL новее 3.26.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Таблица сессий — схема базы v2

**Files:**
- Modify: `server-pay/catalog/db.php` (`CATALOG_SCHEMA_VERSION`, `CATALOG_MIGRATIONS`)
- Modify: `tests/php/catalog_db_test.php`

**Interfaces:**
- Consumes: механизм миграций этапа 2А (`catalog_db_migrate`, `CATALOG_MIGRATIONS`: номер версии => список SQL).
- Produces: таблица `sessions (token_hash TEXT PRIMARY KEY, login TEXT NOT NULL REFERENCES users(login) ON DELETE CASCADE, csrf TEXT NOT NULL, created_at INTEGER NOT NULL, seen_at INTEGER NOT NULL)` и индекс `sessions_login`; `CATALOG_SCHEMA_VERSION = 2`.

- [ ] **Step 1: Проверки (пока падают)**

In `tests/php/catalog_db_test.php`:

1. In the case `'схема'` replace

```php
        ['audit', 'login_attempts', 'meta', 'product_sections', 'products', 'redirects', 'sections', 'tiles', 'users'],
```

with

```php
        ['audit', 'login_attempts', 'meta', 'product_sections', 'products', 'redirects', 'sections', 'sessions', 'tiles', 'users'],
```

2. In the case `'версия схемы'` replace the two assertions

```php
    t_equal(t_schema_version($db), 1, 'новая база помечена версией схемы 1');
```
```php
    t_equal(t_schema_version($again), 1, 'повторное открытие версию не меняет');
```

with

```php
    t_equal(t_schema_version($db), 2, 'новая база помечена версией схемы 2');
```
```php
    t_equal(t_schema_version($again), 2, 'повторное открытие версию не меняет');
```

3. In the case `'база без отметки версии'` replace

```php
    t_equal(t_schema_version($db), 1, 'такая база — схема версии 1, отметка ставится');
```

with

```php
    t_equal(t_schema_version($db), 2, 'такая база — схема версии 1: отметка ставится, следующие шаги проходят');
    t_true(t_has_table($db, 'sessions'), 'шаг 2 применён: таблица сессий есть');
```

4. In the case `'миграции схемы'` right after the line `$db = t_catalog_db();` add

```php
    // Как база версии 1: шаги 2 и 3 ниже — проверочные, а не настоящие.
    $db->exec('PRAGMA user_version = 1');
```

5. Append a new case at the end of the file:

```php
t_case('сессии — схема 2', function (): void {
    $file = t_tmpdir() . '/catalog.sqlite';
    // База версии 1, как на сервере после этапа 2А: таблиц сессий ещё нет.
    $v1 = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (CATALOG_SCHEMA as $sql) {
        $v1->exec($sql);
    }
    $v1->exec('PRAGMA user_version = 1');
    $v1->exec("INSERT INTO users (login, name, password_hash, created_at, updated_at)
        VALUES ('anna', 'Анна', 'x', '2026-10-01T10:00:00+05:00', '2026-10-01T10:00:00+05:00')");
    $v1 = null;

    $db = catalog_db_open($file);
    t_equal(t_schema_version($db), 2, 'база переведена на версию 2');
    t_true(t_has_table($db, 'sessions'), 'таблица сессий создана');
    t_equal($db->query('SELECT login FROM users')->fetchAll(PDO::FETCH_COLUMN), ['anna'], 'учётные записи на месте');
    $db->exec("INSERT INTO sessions (token_hash, login, csrf, created_at, seen_at) VALUES ('h', 'anna', 'c', 1, 1)");
    $db->exec("DELETE FROM users WHERE login = 'anna'");
    t_equal((int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 0, 'удалили учётную запись — её сессии ушли вместе с ней');
});
```

- [ ] **Step 2: Убедиться, что падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: строки «ПЛОХО» в `catalog_db_test.php` (нет таблицы `sessions`, версия 1 вместо 2).

- [ ] **Step 3: Реализация**

In `server-pay/catalog/db.php` replace

```php
const CATALOG_SCHEMA_VERSION = 1;
```

with

```php
const CATALOG_SCHEMA_VERSION = 2;
```

and replace

```php
const CATALOG_MIGRATIONS = [];
```

with

```php
const CATALOG_MIGRATIONS = [
    // Сессии админки (этап 2Б). В куке — случайный токен, здесь — только его
    // sha256: копия базы не даёт войти. Удалили учётную запись — её сессии
    // уходят вместе с ней.
    2 => [
        "CREATE TABLE sessions (
            token_hash TEXT PRIMARY KEY,
            login TEXT NOT NULL REFERENCES users(login) ON DELETE CASCADE,
            csrf TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            seen_at INTEGER NOT NULL
        )",
        'CREATE INDEX sessions_login ON sessions (login)',
    ],
];
```

In the docblock above `CATALOG_MIGRATIONS` replace the sentence `Пример: 2 => ['ALTER TABLE products ADD COLUMN note TEXT'].` with `Следующий шаг — 3 => [...], и CATALOG_SCHEMA_VERSION = 3.` (the rest of the docblock stays).

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/catalog/db.php tests/php/catalog_db_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): схема базы v2 — таблица сессий админки

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Каркас админки — запрос, ответ, разметка

**Files:**
- Create: `server-pay/admin/lib/http.php`
- Create: `server-pay/admin/lib/view.php`
- Create: `server-pay/admin/lib/.htaccess`
- Create: `server-pay/admin/assets/admin.css`
- Test: `tests/php/admin_view_test.php`

**Interfaces:**
- Produces (`http.php`):
  - `const ADMIN_BASE = '/pay/admin/'`, `const ADMIN_SECURITY_HEADERS` (имя => значение)
  - запрос — `array{method: string, path: string, query: array, post: array, cookies: array, files: array, ip: string}`; ответ — `array{status: int, headers: array<string,string>, cookies: list<string>, body: string}`
  - `admin_request_from_globals(): array`
  - `admin_request(string $method = 'GET', array $query = [], array $post = [], array $cookies = [], array $files = [], string $ip = '127.0.0.1'): array`
  - `admin_response(int $status, string $body, array $headers = [], array $cookies = []): array`, `admin_html(string $html, int $status = 200): array`, `admin_json(array $data, int $status = 200): array`, `admin_redirect(string $page, array $query = []): array` (303, `Location: /pay/admin/<page>[?query]`), `admin_emit(array $response): void`
  - `admin_str(array $from, string $key): string` — не строка → `''`; `admin_list(array $from, string $key): list<string>` — не список строк → только строки из него или `[]`
- Produces (`view.php`):
  - `const ADMIN_MONTHS`, `const ADMIN_STATUS_LABELS` (`draft` → «Черновик», `active` → «В продаже», `hidden` → «Снят с продажи», `deleted` → «Удалён»), `const ADMIN_NOTICES` (ключ => текст)
  - `h(?string $text): string`, `admin_rub(int $amount): string` («4 400 ₽» с неразрывными пробелами), `admin_day(string $iso): string` («12 марта»), `admin_date(string $iso): string` («12 марта, 14:32»), `admin_asset(string $file): string`
  - `admin_csrf_field(array $user): string`, `admin_notice(array $req): string`, `admin_error(string $message): string`
  - `admin_layout(string $title, string $content, ?array $user = null, string $status = '', string $notice = ''): string` — `$user` — сессия (`name`, `csrf`), null — без меню
  - `admin_not_found(array $ctx, string $what = 'Такого букета нет', string $back = ''): array` — 404

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_view_test.php`:

```php
<?php
/**
 * Каркас админки: заголовки безопасности, переходы, экранирование, деньги и
 * даты по-русски, страница с меню.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/view.php';

t_case('ответы', function (): void {
    $page = admin_html('<p>x</p>');
    t_equal($page['status'], 200, 'страница — 200');
    foreach (ADMIN_SECURITY_HEADERS as $name => $value) {
        t_equal($page['headers'][$name] ?? null, $value, "заголовок $name у каждой страницы");
    }
    t_equal($page['headers']['X-Robots-Tag'], 'noindex, nofollow', 'админку не индексируют');
    t_equal($page['headers']['Cache-Control'], 'no-store', 'и не кэшируют');
    $go = admin_redirect('product.php', ['uid' => '553645466981', 'notice' => 'saved']);
    t_equal([$go['status'], $go['headers']['Location']], [303, '/pay/admin/product.php?uid=553645466981&notice=saved'], 'переход после POST — 303 на страницу админки');
    t_equal(admin_redirect('')['headers']['Location'], '/pay/admin/', 'переход в начало админки');
    t_equal(admin_redirect('')['headers']['X-Robots-Tag'], 'noindex, nofollow', 'у перехода — те же заголовки');
    $json = admin_json(['ok' => true, 'path' => '/images/catalog/a.webp']);
    t_equal([$json['headers']['Content-Type'], $json['body']], ['application/json; charset=utf-8', '{"ok":true,"path":"/images/catalog/a.webp"}'], 'JSON без экранирования слэшей');
});

t_case('поля формы', function (): void {
    $post = ['title' => 'Розы', 'price' => ['1'], 'sections' => ['bukety', ['x'], 'roses'], 'images' => 'не список'];
    t_equal(admin_str($post, 'title'), 'Розы', 'строка — как есть');
    t_equal(admin_str($post, 'price'), '', 'массив вместо строки (подделанный запрос) — пусто');
    t_equal(admin_str($post, 'nope'), '', 'нет поля — пусто');
    t_equal(admin_list($post, 'sections'), ['bukety', 'roses'], 'из списка — только строки');
    t_equal(admin_list($post, 'images'), [], 'строка вместо списка — пустой список');
});

t_case('текст, деньги, даты', function (): void {
    t_equal(h('<b>"x"&\'</b>'), '&lt;b&gt;&quot;x&quot;&amp;&#039;&lt;/b&gt;', 'h() экранирует всё опасное');
    t_equal(admin_rub(4400), "4\u{00A0}400\u{00A0}₽", 'цена с неразрывными пробелами');
    t_equal(admin_rub(300000), "300\u{00A0}000\u{00A0}₽", 'шестизначная цена');
    t_equal(admin_day('2026-03-12T09:05:00Z'), '12 марта', 'день по Перми');
    t_equal(admin_date('2026-03-12T21:05:00Z'), '13 марта, 02:05', 'дата и время по Перми — с переходом через полночь');
});

t_case('сообщения', function (): void {
    t_equal(admin_notice(admin_request(query: ['notice' => 'saved'])), 'Сохранено.', 'сообщение по ключу');
    t_equal(admin_notice(admin_request(query: ['notice' => '<script>'])), '', 'произвольный текст из адресной строки не показывается');
    t_equal(admin_error(''), '', 'нет ошибки — нет блока');
    t_equal(admin_error('Цена <неверна>'), '<p class="error">Цена &lt;неверна&gt;</p>', 'ошибка экранирована');
});

t_case('страница', function (): void {
    $user = ['name' => 'Анна <script>', 'csrf' => 'abc123'];
    $html = admin_layout('Букеты', '<p>содержимое</p>', $user, '<p class="status">статус</p>', 'Сохранено.');
    t_true(str_contains($html, '<meta name="robots" content="noindex, nofollow">'), 'запрет индексации и в самой странице');
    t_true(str_contains($html, 'Анна &lt;script&gt;'), 'имя в меню экранировано');
    t_true(str_contains($html, '<input type="hidden" name="csrf" value="abc123">'), 'выход — POST с CSRF-токеном');
    t_true(str_contains($html, 'href="/pay/admin/sections.php"') && str_contains($html, 'href="/pay/admin/log.php"'), 'меню: разделы и журнал');
    t_true(str_contains($html, '/pay/admin/assets/admin.css?v='), 'стили с меткой версии: после выкладки браузер берёт новые');
    t_true(str_contains($html, '<p class="notice">Сохранено.</p>') && str_contains($html, 'статус'), 'сообщение и строка статуса');
    t_true(!str_contains($html, 'style="') && !preg_match('/<script>/', $html), 'ни встроенных стилей, ни встроенных скриптов (CSP)');
    t_true(!str_contains(admin_layout('Вход', '<p>x</p>'), '<nav'), 'без входа — без меню');
    $ctx = ['user' => $user, 'status' => ''];
    $missing = admin_not_found($ctx, 'Такого раздела нет', 'sections.php');
    t_true($missing['status'] === 404 && str_contains($missing['body'], 'Такого раздела нет') && str_contains($missing['body'], 'href="/pay/admin/sections.php"'), '404 — понятная страница со ссылкой назад');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/view.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/http.php`:

```php
<?php
/**
 * Запрос и ответ админки — обычные массивы. Страница — функция «запрос →
 * ответ»: её можно проверить без веб-сервера, а настоящие $_POST, куки и
 * заголовки трогают только admin_request_from_globals() и admin_emit().
 *
 * Запрос: method, path, query, post, cookies, files, ip.
 * Ответ: status, headers (имя => значение), cookies (строки Set-Cookie), body.
 */

declare(strict_types=1);

/** Адрес админки на сайте. */
const ADMIN_BASE = '/pay/admin/';

/**
 * Заголовки каждого ответа: админку не индексируют и не кэшируют, в чужой
 * сайт её не встроить, и в ней работают только свои стили и скрипты (blob:
 * и data: — превью фото до отправки).
 */
const ADMIN_SECURITY_HEADERS = [
    'X-Robots-Tag' => 'noindex, nofollow',
    'Cache-Control' => 'no-store',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'same-origin',
    'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
];

function admin_request_from_globals(): array
{
    return [
        'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'path' => (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        'query' => $_GET,
        'post' => $_POST,
        'cookies' => $_COOKIE,
        'files' => $_FILES,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
}

/** Запрос для проверок: всё, чего не передали, — пусто. */
function admin_request(
    string $method = 'GET',
    array $query = [],
    array $post = [],
    array $cookies = [],
    array $files = [],
    string $ip = '127.0.0.1',
): array {
    return [
        'method' => $method, 'path' => ADMIN_BASE, 'query' => $query, 'post' => $post,
        'cookies' => $cookies, 'files' => $files, 'ip' => $ip,
    ];
}

function admin_response(int $status, string $body, array $headers = [], array $cookies = []): array
{
    return ['status' => $status, 'headers' => $headers + ADMIN_SECURITY_HEADERS, 'cookies' => $cookies, 'body' => $body];
}

function admin_html(string $html, int $status = 200): array
{
    return admin_response($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
}

function admin_json(array $data, int $status = 200): array
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return admin_response($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
}

/** Переход на страницу админки после POST: 303 — браузер придёт GET-запросом, повторная отправка формы не случится. */
function admin_redirect(string $page, array $query = []): array
{
    $url = ADMIN_BASE . $page . ($query === [] ? '' : '?' . http_build_query($query));
    return admin_response(303, '', ['Location' => $url]);
}

function admin_emit(array $response): void
{
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("$name: $value");
    }
    foreach ($response['cookies'] as $cookie) {
        header('Set-Cookie: ' . $cookie, false);
    }
    echo $response['body'];
}

/** Строковое поле формы. Не строка (массив из подделанного запроса) — пусто. */
function admin_str(array $from, string $key): string
{
    $value = $from[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Список строк из формы (галочки разделов, фото). Не список — пусто; не строки в нём пропускаются. */
function admin_list(array $from, string $key): array
{
    $value = $from[$key] ?? [];
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}
```

Create `server-pay/admin/lib/view.php`:

```php
<?php
/**
 * Разметка админки: каркас страницы, экранирование, деньги и даты по-русски.
 * Страницы собирают HTML строками — шаблонизатор ради десятка экранов не
 * нужен. Всё, что пришло из базы или от пользователя, проходит через h().
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';

const ADMIN_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

const ADMIN_STATUS_LABELS = ['draft' => 'Черновик', 'active' => 'В продаже', 'hidden' => 'Снят с продажи', 'deleted' => 'Удалён'];

/** Сообщения после перехода. В адресе — только ключ: текст из адресной строки на страницу не попадает. */
const ADMIN_NOTICES = [
    'password' => 'Пароль сменён.',
    'saved' => 'Сохранено.',
    'created' => 'Черновик создан. Добавьте фото и опубликуйте, когда букет будет готов.',
    'published' => 'Букет опубликован.',
    'hidden' => 'Букет снят с продажи.',
    'unhidden' => 'Букет снова в продаже.',
    'deleted' => 'Букет удалён. Восстановить его можно в течение 90 дней.',
    'restored' => 'Букет восстановлен.',
    'removed' => 'Черновик удалён.',
    'section-created' => 'Раздел создан. Заполните его; пока не готов — снимите галочку «Показывать в каталоге».',
];

/** Текст для HTML: кавычки и угловые скобки — сущности. */
function h(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 4400 → «4 400 ₽» (неразрывные пробелы: цена не разрывается на две строки). */
function admin_rub(int $amount): string
{
    return number_format($amount, 0, '', "\u{00A0}") . "\u{00A0}₽";
}

function admin_perm(string $iso): DateTimeImmutable
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Asia/Yekaterinburg'));
}

/** 2026-03-12T14:32:00+05:00 → «12 марта» (по Перми). */
function admin_day(string $iso): string
{
    $at = admin_perm($iso);
    return $at->format('j') . ' ' . ADMIN_MONTHS[(int)$at->format('n') - 1];
}

/** → «12 марта, 14:32» (по Перми). */
function admin_date(string $iso): string
{
    return admin_day($iso) . ', ' . admin_perm($iso)->format('H:i');
}

/** Адрес файла оформления с меткой версии: после выкладки браузер берёт новый, а не из кэша. */
function admin_asset(string $file): string
{
    return ADMIN_BASE . 'assets/' . $file . '?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/' . $file);
}

/** Скрытое поле с CSRF-токеном сессии — в каждой форме с POST. */
function admin_csrf_field(array $user): string
{
    return '<input type="hidden" name="csrf" value="' . h($user['csrf']) . '">';
}

function admin_notice(array $req): string
{
    return ADMIN_NOTICES[admin_str($req['query'], 'notice')] ?? '';
}

function admin_error(string $message): string
{
    return $message === '' ? '' : '<p class="error">' . h($message) . '</p>';
}

/**
 * Полная страница: шапка с меню, строка статуса выкладки, сообщение.
 * $user — сессия (имя для меню и CSRF для выхода), null — без меню (вход).
 */
function admin_layout(string $title, string $content, ?array $user = null, string $status = '', string $notice = ''): string
{
    $nav = '';
    if ($user !== null) {
        $nav = '<nav class="nav">'
            . '<a href="' . ADMIN_BASE . '">Букеты</a>'
            . '<a href="' . ADMIN_BASE . 'sections.php">Разделы</a>'
            . '<a href="' . ADMIN_BASE . 'log.php">Журнал</a>'
            . '<a href="' . ADMIN_BASE . 'password.php">Пароль</a>'
            . '<form method="post" action="' . ADMIN_BASE . 'logout.php">' . admin_csrf_field($user)
            . '<button class="link" type="submit">Выйти (' . h($user['name']) . ')</button></form>'
            . '</nav>';
    }
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . ' — админка «Пиона»</title>'
        . '<link rel="stylesheet" href="' . h(admin_asset('admin.css')) . '">'
        . '</head><body>'
        . '<header class="top"><a class="brand" href="' . ADMIN_BASE . '">Пион · админка</a>' . $nav . '</header>'
        . $status
        . '<main>' . ($notice !== '' ? '<p class="notice">' . h($notice) . '</p>' : '') . $content . '</main>'
        . '<script src="' . h(admin_asset('photo.js')) . '" defer></script>'
        . '</body></html>';
}

/** Страница 404: понятный текст и ссылка назад вместо ошибки. */
function admin_not_found(array $ctx, string $what = 'Такого букета нет', string $back = ''): array
{
    $html = '<h1>' . h($what) . '</h1><p>Возможно, его уже удалили или адрес неполный.</p>'
        . '<p><a href="' . ADMIN_BASE . h($back) . '">Назад к списку</a></p>';
    return admin_html(admin_layout($what, $html, $ctx['user'], $ctx['status']), 404);
}
```

Create `server-pay/admin/lib/.htaccess`:

```apache
# Библиотека админки — наружу ничего. Страницы — файлы *.php уровнем выше.
Require all denied
```

Create `server-pay/admin/assets/admin.css`:

```css
/* Админка «Пиона»: телефон прежде всего — одна колонка, крупные кнопки.
   Цвета — как на сайте. Встроенных стилей нет: их запрещает CSP. */
:root {
  --accent: #b08d7a;
  --accent-soft: #f6efe9;
  --header: #d2b8a4;
  --text: #2a2928;
  --muted: #6b6461;
  --border: #d6d6d6;
  --danger: #a33a2a;
  --ok: #2f6b3a;
  --warn: #fff4d6;
}

* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--text); background: #fff; }
a { color: inherit; }
main { max-width: 760px; margin: 0 auto; padding: 16px; }
h1 { font-size: 22px; margin: 8px 0 16px; }
h2 { font-size: 18px; margin: 28px 0 8px; }

.top { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 10px 16px; background: var(--header); }
.brand { font-weight: 700; text-decoration: none; }
.nav { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; }
.nav a { padding: 6px 0; text-decoration: none; }
.nav form { margin: 0; }

.status { margin: 0; padding: 8px 16px; font-size: 14px; background: var(--accent-soft); }
.status-pending { background: var(--warn); }
.status-late { background: #fde2dc; color: var(--danger); }
.status-offline { background: #eee; color: var(--muted); }
.notice { padding: 10px 12px; border-radius: 8px; background: #e6f2e8; color: var(--ok); }
.error { padding: 10px 12px; border-radius: 8px; background: #fde2dc; color: var(--danger); }
.ask { padding: 12px; border-radius: 8px; background: var(--warn); }
.hint { color: var(--muted); font-size: 14px; }

.form label { display: block; margin: 0 0 14px; font-weight: 600; }
.form input:not([type]), .form input[type=text], .form input[type=password], .form input[type=search],
.form textarea, .form select {
  display: block; width: 100%; margin-top: 4px; padding: 10px 12px;
  font: inherit; border: 1px solid var(--border); border-radius: 8px; background: #fff;
}
.form textarea { min-height: 110px; resize: vertical; }
fieldset { margin: 0 0 14px; padding: 0; border: 0; }
legend { padding: 0; font-weight: 600; }
.check { display: flex; align-items: center; gap: 8px; font-weight: 400; }

.btn, .btn-quiet, .btn-danger {
  display: inline-block; min-height: 44px; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--accent);
  font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer;
}
.btn { background: var(--accent); color: #fff; }
.btn-quiet { background: #fff; color: var(--text); }
.btn-danger { background: #fff; color: var(--danger); border-color: var(--danger); }
.btn-small { min-width: 36px; min-height: 36px; margin: 4px 4px 0 0; padding: 4px 8px; font: inherit; font-size: 14px; border: 1px solid var(--border); border-radius: 6px; background: #fff; cursor: pointer; }
.buttons { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0; }
.buttons form { margin: 0; }
button.link { padding: 6px 0; border: 0; background: none; font: inherit; text-decoration: underline; cursor: pointer; }

.filters { display: grid; gap: 8px; margin-bottom: 8px; }
.items { list-style: none; margin: 0; padding: 0; }
.items li { border-bottom: 1px solid var(--border); }
.items a { display: flex; gap: 12px; align-items: center; padding: 10px 0; text-decoration: none; }
.items img, .thumb { flex: none; width: 56px; height: 56px; border-radius: 6px; object-fit: cover; background: var(--accent-soft); }
.item-title { font-weight: 600; }
.item-meta { font-size: 14px; color: var(--muted); }

.badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 13px; font-weight: 400; background: #eee; }
.badge-active { background: #e6f2e8; color: var(--ok); }
.badge-hidden { background: var(--warn); }
.badge-draft { background: #e8eaf4; }
.badge-deleted { background: #fde2dc; color: var(--danger); }

.photos ul { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 10px; padding: 0; list-style: none; }
.photos li { width: 120px; }
.photos li img { display: block; width: 120px; height: 120px; border-radius: 6px; object-fit: cover; }
.photos li:first-child img { outline: 3px solid var(--accent); }
.upload { display: inline-block; padding: 10px 14px; border: 1px dashed var(--accent); border-radius: 8px; font-weight: 600; cursor: pointer; }
.upload input { display: block; margin-top: 6px; font-size: 14px; }
.photos-full .upload { display: none; }

.sections-pick { margin: 0 0 14px; padding: 0; list-style: none; }
.sections-pick li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.address { word-break: break-all; }
details { margin: 16px 0; }
summary { padding: 8px 0; font-weight: 600; cursor: pointer; }

.tiles { padding-left: 24px; }
.tiles li { padding: 8px 0; border-bottom: 1px solid var(--border); }
.tile-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.tile-row form { display: inline; margin: 0; }

.log { margin: 0; padding: 0; list-style: none; }
.log li { padding: 10px 0; border-bottom: 1px solid var(--border); }
.log-head { font-size: 14px; color: var(--muted); }
.on-site { color: var(--ok); }
.waiting { color: #8a6d00; }

@media (min-width: 720px) {
  .filters { grid-template-columns: 2fr 1fr 1fr auto; align-items: end; }
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/http.php server-pay/admin/lib/view.php server-pay/admin/lib/.htaccess server-pay/admin/assets/admin.css tests/php/admin_view_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): каркас админки — запрос и ответ, заголовки, разметка, оформление

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Вход, сессии, CSRF, смена пароля

**Files:**
- Create: `server-pay/admin/lib/auth.php`
- Create: `server-pay/admin/lib/app.php`
- Create: `server-pay/admin/lib/pages-auth.php`
- Create: `server-pay/admin/login.php`, `server-pay/admin/logout.php`, `server-pay/admin/password.php`
- Create: `tests/php/admin_fixture.php`
- Test: `tests/php/admin_auth_test.php`

**Interfaces:**
- Consumes: Task 3 (`http.php`, `view.php`); этап 2А (`catalog_db_open`, `catalog_db_path`, `catalog_tx`, `catalog_iso`, `catalog_audit`); Task 2 (`sessions`).
- Produces (`auth.php`):
  - `const ADMIN_COOKIE = 'pion_admin'`, `ADMIN_IDLE_SECONDS = 43200`, `ADMIN_LOGIN_LIMIT = 5`, `ADMIN_LOGIN_WINDOW = 600`, `ADMIN_PASSWORD_MIN = 8`, `ADMIN_DUMMY_HASH`
  - `admin_login_key(string $login): string`
  - `admin_login_blocked(PDO $db, string $ip, string $login, int $now): bool`
  - `admin_login(PDO $db, string $login, string $password, string $ip, int $now): array{ok: bool, error?: string, token?: string}`
  - `admin_session(PDO $db, ?string $token, int $now): ?array{login: string, name: string, must_change: int, csrf: string}`
  - `admin_logout(PDO $db, ?string $token): void`, `admin_session_cookie(string $token): string`, `admin_clear_cookie(): string`
  - `admin_csrf_ok(array $session, array $req): bool`
  - `admin_change_password(PDO $db, array $session, string $current, string $new, string $repeat, ?string $keepToken, DateTimeImmutable $now): ?string` — null: сменён, иначе текст ошибки
- Produces (`app.php`):
  - окружение страниц `$ctx`: `array{db: ?PDO, now: DateTimeImmutable, webroot: string, deployHome: string}`; `admin_handle` добавляет `token` (string), `user` (?array — сессия), `status` (string — строка статуса, пока `''`)
  - `const ADMIN_PUBLIC_PAGES = ['login']`
  - `admin_context(): array` (переменные окружения `PION_WEBROOT`, `PION_DEPLOY_HOME`, `PION_CATALOG_HOME` — для проверок)
  - `admin_handle(array $req, array $ctx, string $name, callable $page): array`, `admin_run(string $name, callable $page): void`
- Produces (`pages-auth.php`): `admin_page_login`, `admin_page_logout`, `admin_page_password` — все `(array $req, array $ctx): array`
- Produces (`tests/php/admin_fixture.php`): `t_admin_ctx(?PDO $db = null): array` (база с разделами и сотрудницей `anna` / паролем `секрет-анны`, пароль уже сменён), `t_admin_login(array $ctx): array` (куки), `t_admin_call(array $ctx, string $name, callable $page, string $method = 'GET', array $query = [], array $post = [], ?array $cookies = null, array $files = []): array` (входит как anna и сам подставляет CSRF в POST)

- [ ] **Step 1: Общее для проверок админки**

Create `tests/php/admin_fixture.php`:

```php
<?php
/**
 * Общее для проверок админки: база с разделами и сотрудницей, вход, запросы.
 * Не *_test.php — run.php его сам не запускает.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/users.php';
require_once __DIR__ . '/../../server-pay/admin/lib/app.php';

/**
 * Окружение страниц: база с разделами и сотрудницей anna (пароль
 * «секрет-анны», начальный уже сменён), временный корень сайта и папка выкладки.
 */
function t_admin_ctx(?PDO $db = null): array
{
    $db ??= t_catalog_with_sections();
    $db->prepare("INSERT OR IGNORE INTO users (login, name, password_hash, must_change, created_at, updated_at)
        VALUES ('anna', 'Анна', ?, 0, '2026-10-01T10:00:00+05:00', '2026-10-01T10:00:00+05:00')")
        // Стоимость 4 — только ради скорости проверок.
        ->execute([password_hash('секрет-анны', PASSWORD_BCRYPT, ['cost' => 4])]);
    return ['db' => $db, 'now' => t_now(), 'webroot' => t_tmpdir(), 'deployHome' => t_tmpdir()];
}

/** Вход anna — куки для следующих запросов. */
function t_admin_login(array $ctx): array
{
    $result = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $ctx['now']->getTimestamp());
    return [ADMIN_COOKIE => $result['token']];
}

/** Запрос к странице от имени anna; в POST сам подставляет CSRF-токен сессии. */
function t_admin_call(
    array $ctx,
    string $name,
    callable $page,
    string $method = 'GET',
    array $query = [],
    array $post = [],
    ?array $cookies = null,
    array $files = [],
): array {
    $cookies ??= t_admin_login($ctx);
    if ($method === 'POST' && !array_key_exists('csrf', $post)) {
        $session = admin_session($ctx['db'], $cookies[ADMIN_COOKIE] ?? null, $ctx['now']->getTimestamp());
        $post['csrf'] = $session['csrf'] ?? '';
    }
    return admin_handle(admin_request($method, $query, $post, $cookies, $files), $ctx, $name, $page);
}
```

- [ ] **Step 2: Проверка (пока падает)**

Create `tests/php/admin_auth_test.php`:

```php
<?php
/**
 * Вход, подбор пароля, сессии, CSRF, первая смена пароля, выход и пропуск на страницы.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';

/** Страница-заглушка: показывает, что до неё дошли, и кто вошёл. */
function t_admin_probe(array $req, array $ctx): array
{
    return admin_html('страница: ' . ($ctx['user']['login'] ?? 'никто'));
}

t_case('вход', function (): void {
    $ctx = t_admin_ctx();
    $form = admin_handle(admin_request(), $ctx, 'login', 'admin_page_login');
    t_true($form['status'] === 200 && str_contains($form['body'], 'name="password"'), 'страница входа открывается без входа');
    t_equal($form['headers']['X-Robots-Tag'], 'noindex, nofollow', 'и закрыта от поиска');

    $wrong = admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true($wrong['status'] === 200 && str_contains($wrong['body'], 'Неверный логин или пароль.') && $wrong['cookies'] === [], 'неверный пароль — отказ без куки');
    $nobody = admin_handle(admin_request('POST', post: ['login' => 'olga', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true(str_contains($nobody['body'], 'Неверный логин или пароль.'), 'несуществующий логин — тот же ответ: не узнать, кто есть');

    $ok = admin_handle(admin_request('POST', post: ['login' => ' Anna ', 'password' => 'секрет-анны']), $ctx, 'login', 'admin_page_login');
    t_equal([$ok['status'], $ok['headers']['Location']], [303, '/pay/admin/'], 'верный пароль — в админку (логин без учёта регистра и пробелов)');
    $cookie = $ok['cookies'][0] ?? '';
    t_true(str_starts_with($cookie, 'pion_admin=') && str_ends_with($cookie, '; Path=/pay/admin/; HttpOnly; Secure; SameSite=Strict'),
        'кука сессии: только для админки, только https, не видна скриптам, не уходит с чужих сайтов');
    $token = substr(explode(';', $cookie)[0], strlen('pion_admin='));
    t_equal($ctx['db']->query('SELECT token_hash FROM sessions')->fetchAll(PDO::FETCH_COLUMN), [hash('sha256', $token)], 'в базе — только хэш токена');
    $again = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $ctx['now']->getTimestamp());
    t_true($again['token'] !== $token, 'каждый вход — новый идентификатор сессии');
});

t_case('подбор пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, 'anna', 'не тот', '10.0.0.1', $now);
    }
    $blocked = admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 60);
    t_equal($blocked['ok'], false, 'пять неверных попыток — логин закрыт, даже с другого адреса и с верным паролем');
    t_true(str_contains($blocked['error'], '10 минут'), 'сотрудник видит, сколько ждать');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 601)['ok'], true, 'через 10 минут вход снова открыт');
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, "x$i", 'не тот', '10.0.0.9', $now + 700);
    }
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.9', $now + 700)['ok'], false, 'пять неверных попыток с одного адреса — адрес закрыт для всех логинов');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.3', $now + 700)['ok'], true, 'с другого адреса этот логин входит');
});

t_case('сессия: 12 часов без действий', function (): void {
    $ctx = t_admin_ctx();
    $now = $ctx['now']->getTimestamp();
    $token = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $now)['token'];
    t_equal(admin_session($ctx['db'], $token, $now + 3600)['login'] ?? null, 'anna', 'через час — сессия жива');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + ADMIN_IDLE_SECONDS - 1)['login'] ?? null, 'anna', 'считается от последнего действия, а не от входа');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + 2 * ADMIN_IDLE_SECONDS), null, 'больше 12 часов без действий — выход');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 0, 'просроченная сессия удалена');
    t_equal(admin_session($ctx['db'], 'не токен', $now), null, 'мусор вместо токена — не вошли');
});

t_case('пропуск на страницы', function (): void {
    $ctx = t_admin_ctx();
    $anon = admin_handle(admin_request(), $ctx, 'products', 't_admin_probe');
    t_equal([$anon['status'], $anon['headers']['Location']], [303, '/pay/admin/login.php'], 'без входа — на страницу входа');
    $cookies = t_admin_login($ctx);
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: anna', 'после входа страница открывается');
    $bad = admin_handle(admin_request('POST', post: ['csrf' => 'чужой'], cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_true($bad['status'] === 400 && !str_contains($bad['body'], 'страница:'), 'POST без верного CSRF-токена до страницы не доходит');
    $session = admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp());
    t_equal(admin_handle(admin_request('POST', post: ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'],
        'страница: anna', 'с верным токеном — доходит');
    $noDb = $ctx;
    $noDb['db'] = null;
    t_equal(admin_handle(admin_request(), $noDb, 'login', 'admin_page_login')['status'], 503, 'базы нет — админка честно говорит, что не подключена');
});

t_case('первый вход и смена пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $initial = catalog_user_add($db, 'olga', 'Ольга', t_now());
    $cookies = [ADMIN_COOKIE => admin_login($db, 'olga', $initial, '127.0.0.1', $now)['token']];
    $page = admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_equal([$page['status'], $page['headers']['Location']], [303, '/pay/admin/password.php'], 'с выданным паролем — сначала смена пароля');
    $form = admin_handle(admin_request(cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($form['body'], 'первый вход'), 'страница смены пароля объясняет, зачем');

    $phone = admin_login($db, 'olga', $initial, '127.0.0.2', $now)['token'];
    $session = admin_session($db, $cookies[ADMIN_COOKIE], $now);
    $try = fn (array $fields): array => admin_handle(
        admin_request('POST', post: $fields + ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($try(['current' => 'не тот', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'])['body'], 'Текущий пароль введён неверно'), 'неверный текущий пароль');
    t_true(str_contains($try(['current' => $initial, 'new' => 'коротко', 'repeat' => 'коротко'])['body'], 'не короче 8'), 'слишком короткий');
    t_true(str_contains($try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'другой-пароль'])['body'], 'не совпадают'), 'повтор не совпал');
    $done = $try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'новый-пароль']);
    t_equal([$done['status'], $done['headers']['Location']], [303, '/pay/admin/?notice=password'], 'пароль сменён');
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: olga', 'дальше — обычная работа');
    t_equal(admin_session($db, $phone, $now), null, 'вход с другого устройства закрыт: пароль могли сменить из-за утечки');
    t_true(admin_login($db, 'olga', 'новый-пароль', '127.0.0.3', $now)['ok'], 'новый пароль подходит');
    $log = implode(' ', $db->query("SELECT COALESCE(new_value, '') FROM audit WHERE object_type = 'user'")->fetchAll(PDO::FETCH_COLUMN));
    t_true(!str_contains($log, 'новый-пароль'), 'пароль в журнал не попадает');
});

t_case('выход', function (): void {
    $ctx = t_admin_ctx();
    $cookies = t_admin_login($ctx);
    $out = t_admin_call($ctx, 'logout', 'admin_page_logout', 'POST', cookies: $cookies);
    t_equal([$out['status'], $out['headers']['Location']], [303, '/pay/admin/login.php'], 'выход — на страницу входа');
    t_true(str_contains($out['cookies'][0] ?? '', 'Max-Age=0'), 'кука стирается');
    t_equal(admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp()), null, 'сессия удалена');
});
```

- [ ] **Step 3: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/app.php'`.

- [ ] **Step 4: Реализация**

Create `server-pay/admin/lib/auth.php`:

```php
<?php
/**
 * Вход в админку. Пароли — только хэш; учётные записи заводит разработчик
 * (catalog/user-cli.php). Сессия — случайный токен в куке, в базе — его
 * sha256. Подбор пароля: 5 неверных попыток за 10 минут — вход закрыт на 10
 * минут, отдельно для адреса и для логина.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../catalog/db.php';
require_once __DIR__ . '/http.php';

const ADMIN_COOKIE = 'pion_admin';
/** Выход после 12 часов без действий. */
const ADMIN_IDLE_SECONDS = 43200;
const ADMIN_LOGIN_LIMIT = 5;
const ADMIN_LOGIN_WINDOW = 600;
const ADMIN_PASSWORD_MIN = 8;
/** Хэш, с которым сверяется пароль несуществующего логина: по времени ответа не узнать, есть ли такой сотрудник. */
const ADMIN_DUMMY_HASH = '$2y$10$ef.4cizNGuT6y4ghb/RglOGG0W4blm0loaBE.4b5hvYtCjRM88MZi';

/** Логин без учёта регистра и пробелов: «Anna» и «anna» — одна запись и один счётчик попыток. */
function admin_login_key(string $login): string
{
    return mb_strtolower(trim($login));
}

function admin_login_blocked(PDO $db, string $ip, string $login, int $now): bool
{
    $since = $now - ADMIN_LOGIN_WINDOW;
    $q = $db->prepare('SELECT
        (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?),
        (SELECT COUNT(*) FROM login_attempts WHERE login = ? AND at > ?)');
    $q->execute([$ip, $since, $login, $since]);
    [$byIp, $byLogin] = $q->fetch(PDO::FETCH_NUM);
    return (int)$byIp >= ADMIN_LOGIN_LIMIT || (int)$byLogin >= ADMIN_LOGIN_LIMIT;
}

/**
 * Попытка входа. Удача — новая сессия: при каждом входе новый токен.
 *
 * @return array{ok: bool, error?: string, token?: string}
 */
function admin_login(PDO $db, string $login, string $password, string $ip, int $now): array
{
    $login = admin_login_key($login);
    $db->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([$now - 86400]);
    if (admin_login_blocked($db, $ip, $login, $now)) {
        return ['ok' => false, 'error' => 'Слишком много неудачных попыток. Подождите 10 минут и попробуйте снова.'];
    }
    $q = $db->prepare('SELECT password_hash FROM users WHERE login = ?');
    $q->execute([$login]);
    $hash = $q->fetchColumn();
    $ok = password_verify($password, is_string($hash) ? $hash : ADMIN_DUMMY_HASH) && is_string($hash);
    if (!$ok) {
        $db->prepare('INSERT INTO login_attempts (ip, login, at) VALUES (?, ?, ?)')->execute([$ip, $login, $now]);
        return ['ok' => false, 'error' => 'Неверный логин или пароль.'];
    }
    $db->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, login, csrf, created_at, seen_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $login, bin2hex(random_bytes(16)), $now, $now]);
    return ['ok' => true, 'token' => $token];
}

/**
 * Сессия по токену из куки: жива — продлевается, 12 часов без действий —
 * удаляется. null — не вошли.
 *
 * @return array{login: string, name: string, must_change: int, csrf: string}|null
 */
function admin_session(PDO $db, ?string $token, int $now): ?array
{
    if ($token === null || $token === '' || !ctype_xdigit($token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $q = $db->prepare('SELECT s.login, s.csrf, s.seen_at, u.name, u.must_change
        FROM sessions s JOIN users u ON u.login = s.login WHERE s.token_hash = ?');
    $q->execute([$hash]);
    $row = $q->fetch();
    if ($row === false) {
        return null;
    }
    if ((int)$row['seen_at'] < $now - ADMIN_IDLE_SECONDS) {
        $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$hash]);
        return null;
    }
    // Продлеваем не чаще раза в минуту — не писать в базу на каждый клик.
    if ((int)$row['seen_at'] < $now - 60) {
        $db->prepare('UPDATE sessions SET seen_at = ? WHERE token_hash = ?')->execute([$now, $hash]);
    }
    return ['login' => $row['login'], 'name' => $row['name'], 'must_change' => (int)$row['must_change'], 'csrf' => $row['csrf']];
}

function admin_logout(PDO $db, ?string $token): void
{
    if (is_string($token) && $token !== '') {
        $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
}

/** Кука сессии: только для админки, только по https, не видна скриптам, не уходит с чужих сайтов. */
function admin_session_cookie(string $token): string
{
    return ADMIN_COOKIE . '=' . $token . '; Path=' . ADMIN_BASE . '; HttpOnly; Secure; SameSite=Strict';
}

function admin_clear_cookie(): string
{
    return ADMIN_COOKIE . '=; Path=' . ADMIN_BASE . '; Max-Age=0; HttpOnly; Secure; SameSite=Strict';
}

function admin_csrf_ok(array $session, array $req): bool
{
    return hash_equals($session['csrf'], admin_str($req['post'], 'csrf'));
}

/**
 * Смена пароля: null — сменён, иначе текст ошибки. Другие сессии этого
 * сотрудника закрываются: пароль могли сменить из-за утечки.
 */
function admin_change_password(
    PDO $db,
    array $session,
    string $current,
    string $new,
    string $repeat,
    ?string $keepToken,
    DateTimeImmutable $now,
): ?string {
    $q = $db->prepare('SELECT password_hash FROM users WHERE login = ?');
    $q->execute([$session['login']]);
    if (!password_verify($current, (string)$q->fetchColumn())) {
        return 'Текущий пароль введён неверно.';
    }
    if (mb_strlen($new) < ADMIN_PASSWORD_MIN) {
        return 'Новый пароль — не короче 8 знаков.';
    }
    if ($new !== $repeat) {
        return 'Новый пароль и повтор не совпадают.';
    }
    if ($new === $current) {
        return 'Новый пароль совпадает с текущим — придумайте другой.';
    }
    catalog_tx($db, function () use ($db, $session, $new, $keepToken, $now): void {
        $db->prepare('UPDATE users SET password_hash = ?, must_change = 0, updated_at = ? WHERE login = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), catalog_iso($now), $session['login']]);
        $db->prepare('DELETE FROM sessions WHERE login = ? AND token_hash <> ?')
            ->execute([$session['login'], hash('sha256', (string)$keepToken)]);
        catalog_audit($db, $session['login'], $now, 'user', $session['login'], 'password', null, 'сменён');
    });
    return null;
}
```

Create `server-pay/admin/lib/app.php`:

```php
<?php
/**
 * Каждая страница админки проходит одни и те же ворота: есть ли база, вошёл
 * ли сотрудник, верен ли CSRF-токен у POST, сменён ли выданный пароль — и
 * только потом сама страница.
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/auth.php';

/** Страницы, куда пускают без входа. */
const ADMIN_PUBLIC_PAGES = ['login'];

/**
 * Окружение страниц: база (null — её ещё нет), время, корень сайта (там
 * images/catalog) и папка выкладки (для строки статуса).
 */
function admin_context(): array
{
    $webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 3), '/');
    $file = catalog_db_path();
    return [
        'db' => is_file($file) ? catalog_db_open($file) : null,
        'now' => new DateTimeImmutable(),
        'webroot' => $webroot,
        'deployHome' => rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/'),
    ];
}

/** @param callable(array, array): array $page */
function admin_handle(array $req, array $ctx, string $name, callable $page): array
{
    $ctx['user'] = null;
    $ctx['status'] = '';
    if ($ctx['db'] === null) {
        return admin_html(admin_layout('Админка', '<h1>Админка ещё не подключена</h1>'
            . '<p>Базы каталога на сервере пока нет. Обратитесь к разработчику.</p>'), 503);
    }
    $ctx['token'] = admin_str($req['cookies'], ADMIN_COOKIE);
    $user = admin_session($ctx['db'], $ctx['token'], $ctx['now']->getTimestamp());
    if (!in_array($name, ADMIN_PUBLIC_PAGES, true)) {
        if ($user === null) {
            return admin_redirect('login.php');
        }
        if ($req['method'] === 'POST' && !admin_csrf_ok($user, $req)) {
            return admin_html(admin_layout('Форма устарела', '<h1>Форма устарела</h1>'
                . '<p>Вернитесь назад, обновите страницу и повторите.</p>', $user), 400);
        }
        if ($user['must_change'] && !in_array($name, ['password', 'logout'], true)) {
            return admin_redirect('password.php');
        }
    }
    $ctx['user'] = $user;
    return $page($req, $ctx);
}

/** Запуск страницы из её файла (pay/admin/<страница>.php). */
function admin_run(string $name, callable $page): void
{
    try {
        admin_emit(admin_handle(admin_request_from_globals(), admin_context(), $name, $page));
    } catch (Throwable $e) {
        error_log('admin: ' . $e->getMessage());
        admin_emit(admin_html(admin_layout('Ошибка', '<h1>Что-то пошло не так</h1>'
            . '<p>Попробуйте ещё раз. Если повторится — сообщите разработчику, что и когда вы делали.</p>'), 500));
    }
}
```

Create `server-pay/admin/lib/pages-auth.php`:

```php
<?php
/**
 * Экраны входа, выхода и смены пароля.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function admin_page_login(array $req, array $ctx): array
{
    if ($ctx['user'] !== null) {
        return admin_redirect('');
    }
    $error = '';
    if ($req['method'] === 'POST') {
        $result = admin_login(
            $ctx['db'],
            admin_str($req['post'], 'login'),
            admin_str($req['post'], 'password'),
            $req['ip'],
            $ctx['now']->getTimestamp(),
        );
        if ($result['ok']) {
            $response = admin_redirect('');
            $response['cookies'][] = admin_session_cookie($result['token']);
            return $response;
        }
        $error = $result['error'];
    }
    $html = '<h1>Вход</h1>' . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'login.php" class="form">'
        . '<label>Логин<input name="login" autocomplete="username" autocapitalize="none" required value="'
        . h(admin_str($req['post'], 'login')) . '"></label>'
        . '<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<p class="buttons"><button class="btn" type="submit">Войти</button></p></form>';
    return admin_html(admin_layout('Вход', $html));
}

function admin_page_logout(array $req, array $ctx): array
{
    if ($req['method'] !== 'POST') {
        return admin_redirect('');
    }
    admin_logout($ctx['db'], $ctx['token']);
    $response = admin_redirect('login.php');
    $response['cookies'][] = admin_clear_cookie();
    return $response;
}

function admin_page_password(array $req, array $ctx): array
{
    $user = $ctx['user'];
    $error = '';
    if ($req['method'] === 'POST') {
        $error = admin_change_password(
            $ctx['db'],
            $user,
            admin_str($req['post'], 'current'),
            admin_str($req['post'], 'new'),
            admin_str($req['post'], 'repeat'),
            $ctx['token'],
            $ctx['now'],
        ) ?? '';
        if ($error === '') {
            return admin_redirect('', ['notice' => 'password']);
        }
    }
    $intro = $user['must_change']
        ? '<p>Это первый вход: придумайте свой пароль вместо выданного — не короче 8 знаков.</p>'
        : '';
    $html = '<h1>Смена пароля</h1>' . $intro . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'password.php" class="form">' . admin_csrf_field($user)
        . '<label>Текущий пароль<input name="current" type="password" autocomplete="current-password" required></label>'
        . '<label>Новый пароль<input name="new" type="password" autocomplete="new-password" minlength="8" required></label>'
        . '<label>Повторите новый пароль<input name="repeat" type="password" autocomplete="new-password" minlength="8" required></label>'
        . '<p class="buttons"><button class="btn" type="submit">Сменить пароль</button></p></form>';
    return admin_html(admin_layout('Смена пароля', $html, $user, $ctx['status']));
}
```

Create `server-pay/admin/login.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-auth.php';

admin_run('login', 'admin_page_login');
```

Create `server-pay/admin/logout.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-auth.php';

admin_run('logout', 'admin_page_logout');
```

Create `server-pay/admin/password.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-auth.php';

admin_run('password', 'admin_page_password');
```

- [ ] **Step 5: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/admin tests/php/admin_fixture.php tests/php/admin_auth_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): вход, сессии, защита от подбора, CSRF, смена пароля

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Строка статуса выкладки

**Files:**
- Create: `server-pay/admin/lib/status.php`
- Modify: `server-pay/admin/lib/app.php` (подключить `status.php`; `admin_handle` считает строку статуса)
- Test: `tests/php/admin_status_test.php`

**Interfaces:**
- Consumes: `catalog_meta()` (2А); `pion-deploy/state.json` (этап 1).
- Produces:
  - `const ADMIN_DEPLOY_LATE_MINUTES = 90`
  - `admin_deployed_catalog(string $deployHome): ?array{version: string, changedAt: string}` — из `state.json`: `current.catalogVersion`, `current.catalogChangedAt`; полей нет — `null`
  - `admin_deploy_status(array $meta, ?array $deployed, DateTimeImmutable $now): array{kind: 'synced'|'pending'|'late'|'offline', text: string}`
  - `admin_status_line(array $status): string` — `<p class="status status-<kind>">…</p>`
  - `admin_change_on_site(string $at, ?array $deployed, bool $inSync = false): ?bool` — `true` на сайте, `false` ждёт выкладки, `null` неизвестно; версии совпали (`$inSync`) — на сайте всё (уточнено при выполнении: черновики и смена пароля на сайт не попадают и не должны висеть как «ждёт выкладки»)
  - `admin_handle` кладёт в `$ctx` для вошедшего сотрудника: `status` — готовую строку, `deployed` — что выложено (`?array`), `inSync` — версии совпали (bool); без входа — `''`, `null`, `false`

Статус сравнивает **версию** каталога в базе с выложенной, а не время: правка V1→V2→V1 оставляет новое время при том же содержимом (замечание из 2А). Контракт для этапа 3: выкладка пишет в `state.json` → `current.catalogVersion` (версия выгрузки, по которой собран сайт) и `current.catalogChangedAt` (её `changedAt` строкой, как в выгрузке).

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_status_test.php`:

```php
<?php
/**
 * Строка статуса: на сайте ли то, что сохранили, и отметки у изменений.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';

t_case('статус по версии', function (): void {
    $deployed = ['version' => 'v1', 'changedAt' => '2026-10-05T13:00:00+05:00'];
    t_equal(admin_deploy_status([], null, t_now())['kind'], 'offline', 'сборка о каталоге не знает — сайт собирается из прежнего');
    t_true(str_contains(admin_deploy_status([], null, t_now())['text'], 'из прежнего каталога'), 'и строка так и говорит');
    t_equal(admin_deploy_status(['version' => 'v1', 'changed_at' => '2026-10-05T13:00:00+05:00'], $deployed, t_now()),
        ['kind' => 'synced', 'text' => 'Все изменения на сайте.'], 'версии совпали — всё на сайте');
    t_equal(admin_deploy_status([], $deployed, t_now())['kind'], 'synced', 'ещё ничего не сохраняли — ждать нечего');
    t_equal(admin_deploy_status(['version' => 'v2', 'changed_at' => '2026-10-05T14:00:00+05:00'], $deployed, t_now('+10 minutes')),
        ['kind' => 'pending', 'text' => 'Ждёт выкладки с 14:00 — обычно до получаса.'], 'новая версия — ждёт выкладки');
    t_equal(admin_deploy_status(['version' => 'v2', 'changed_at' => '2026-10-05T14:00:00+05:00'], $deployed, t_now('+91 minutes'))['kind'],
        'late', 'больше 90 минут — выкладка задерживается');
});

t_case('что выложено', function (): void {
    $home = t_tmpdir();
    t_equal(admin_deployed_catalog($home), null, 'нет state.json — неизвестно');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'commit' => 'def']]));
    t_equal(admin_deployed_catalog($home), null, 'сборка этапа 1 о каталоге не знает');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'catalogVersion' => 'v7', 'catalogChangedAt' => '2026-10-05T13:00:00+05:00']]));
    t_equal(admin_deployed_catalog($home), ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00'], 'версия и время выложенного каталога');
});

t_case('отметки у изменений', function (): void {
    $deployed = ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00'];
    t_equal(admin_change_on_site('2026-10-05T12:59:00+05:00', $deployed), true, 'раньше выложенного — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:00:00+05:00', $deployed), true, 'то же время — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', $deployed), false, 'позже — ждёт выкладки');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', null), null, 'неизвестно — без отметки');
});

t_case('строка на страницах', function (): void {
    $ctx = t_admin_ctx();
    $seen = '';
    $page = function (array $req, array $c) use (&$seen): array {
        $seen = $c['status'];
        return admin_html('ok');
    };
    t_admin_call($ctx, 'products', $page);
    t_equal($seen, '<p class="status status-offline">Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.</p>',
        'страницы получают готовую строку статуса');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Call to undefined function admin_deploy_status()`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/status.php`:

```php
<?php
/**
 * Строка статуса вверху каждого экрана: на сайте ли то, что сохранили.
 * Сравнивается версия каталога в базе (catalog_meta) с версией, по которой
 * собрана выложенная сборка: её пишет выкладка в pion-deploy/state.json —
 * current.catalogVersion и current.catalogChangedAt (этап 3). Пока этих полей
 * нет, сайт собирается из прежнего каталога, и строка честно это говорит.
 * Сравнение — по версии, а не по времени: правка туда и обратно оставляет
 * новое время при том же содержимом.
 */

declare(strict_types=1);

require_once __DIR__ . '/view.php';

/** Через сколько минут ожидания выкладка считается задержавшейся. */
const ADMIN_DEPLOY_LATE_MINUTES = 90;

/** @return array{version: string, changedAt: string}|null */
function admin_deployed_catalog(string $deployHome): ?array
{
    $state = json_decode((string)@file_get_contents($deployHome . '/state.json'), true);
    $current = is_array($state) ? ($state['current'] ?? null) : null;
    if (!is_array($current) || !is_string($current['catalogVersion'] ?? null)) {
        return null;
    }
    return ['version' => $current['catalogVersion'], 'changedAt' => (string)($current['catalogChangedAt'] ?? '')];
}

/**
 * @param array<string, string> $meta catalog_meta(): version и changed_at последнего изменения выгрузки
 * @return array{kind: string, text: string}
 */
function admin_deploy_status(array $meta, ?array $deployed, DateTimeImmutable $now): array
{
    if ($deployed === null) {
        return ['kind' => 'offline', 'text' => 'Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.'];
    }
    $version = $meta['version'] ?? null;
    if ($version === null || $version === $deployed['version']) {
        return ['kind' => 'synced', 'text' => 'Все изменения на сайте.'];
    }
    $since = admin_perm($meta['changed_at']);
    if ($now->getTimestamp() - $since->getTimestamp() > ADMIN_DEPLOY_LATE_MINUTES * 60) {
        return ['kind' => 'late', 'text' => 'Выкладка задерживается — разработчик уведомлён.'];
    }
    return ['kind' => 'pending', 'text' => 'Ждёт выкладки с ' . $since->format('H:i') . ' — обычно до получаса.'];
}

function admin_status_line(array $status): string
{
    return '<p class="status status-' . h($status['kind']) . '">' . h($status['text']) . '</p>';
}

/** Отметка у изменения в журнале и карточке: true — на сайте, false — ждёт выкладки, null — неизвестно. */
function admin_change_on_site(string $at, ?array $deployed): ?bool
{
    if ($deployed === null || $deployed['changedAt'] === '') {
        return null;
    }
    // Время в базе и в выгрузке — одной формы и одного пояса (catalog_iso), строки сравниваются как время.
    return $at <= $deployed['changedAt'];
}
```

In `server-pay/admin/lib/app.php` after `require_once __DIR__ . '/auth.php';` add:

```php
require_once __DIR__ . '/status.php';
```

and in `admin_handle` replace the line

```php
    $ctx['user'] = $user;
    return $page($req, $ctx);
```

with

```php
    $ctx['user'] = $user;
    if ($user !== null) {
        $deployed = admin_deployed_catalog($ctx['deployHome']);
        $ctx['status'] = admin_status_line(admin_deploy_status(catalog_meta($ctx['db']), $deployed, $ctx['now']));
    }
    return $page($req, $ctx);
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/status.php server-pay/admin/lib/app.php tests/php/admin_status_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): строка статуса выкладки — по версии каталога

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Список букетов

**Files:**
- Create: `server-pay/admin/lib/queries.php`
- Create: `server-pay/admin/lib/pages-products.php`
- Create: `server-pay/admin/index.php`
- Test: `tests/php/admin_products_test.php`

**Interfaces:**
- Consumes: `admin_handle` и `$ctx` (Task 4–5), `view.php`.
- Produces:
  - `admin_sections(PDO $db): list<array{slug: string, label: string, visible: int}>` — в порядке сетки
  - `admin_products(PDO $db): list<array>` — строки `products` (`uid`, `slug`, `title`, `price`, `main_section`, `status`, `updated_at`) с `images` (list) и `sections` (list), последние изменённые — сверху
  - `admin_page_products(array $req, array $ctx): array` — фильтры `q`, `section`, `status` (`''` — все, кроме удалённых)

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_products_test.php`:

```php
<?php
/**
 * Список букетов: поиск, фильтры по разделу и статусу, экранирование.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

/** Названия букетов на странице списка, по алфавиту (порядок в списке зависит от времени правки). */
function t_list_titles(string $html): array
{
    preg_match_all('~<span class="item-title">(.*?)</span>~u', $html, $m);
    $titles = array_map(fn (string $t): string => html_entity_decode($t, ENT_QUOTES, 'UTF-8'), $m[1]);
    sort($titles);
    return $titles;
}

t_case('список и фильтры', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А', 'images' => ['/images/catalog/bukety/a.webp']]), t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б', 'sections' => ['roses']]), t_now());
    $rose = catalog_create_product($db, 'anna', t_fields(['title' => 'Роза Эквадор', 'sections' => ['roses']]), t_now());
    catalog_publish($db, 'anna', $rose, 1, t_now());
    catalog_hide($db, 'anna', $rose, 2, t_now());
    $gone = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет В']), t_now());
    catalog_publish($db, 'anna', $gone, 1, t_now());
    catalog_delete($db, 'anna', $gone, 2, t_now());

    $titles = fn (array $query): array => t_list_titles(t_admin_call($ctx, 'products', 'admin_page_products', query: $query)['body']);
    t_equal($titles([]), ['Букет А', 'Букет Б', 'Роза Эквадор'], 'по умолчанию — все, кроме удалённых');
    t_equal($titles(['status' => 'deleted']), ['Букет В'], 'удалённые — отдельным фильтром');
    t_equal($titles(['status' => 'draft']), ['Букет Б'], 'черновики');
    t_equal($titles(['q' => 'роза']), ['Роза Эквадор'], 'поиск без учёта регистра — и по-русски');
    t_equal($titles(['section' => 'roses']), ['Букет Б', 'Роза Эквадор'], 'фильтр по разделу');

    $page = t_admin_call($ctx, 'products', 'admin_page_products')['body'];
    t_true(str_contains($page, 'src="/images/catalog/bukety/a.webp"') && str_contains($page, 'loading="lazy"'), 'превью — первое фото, грузится по мере прокрутки');
    t_true(str_contains($page, 'href="/pay/admin/product.php?new=1"'), 'кнопка «Добавить букет»');
    t_true(str_contains($page, 'href="/pay/admin/product.php?uid=' . $a . '"'), 'букет открывается в карточке');
    t_true(str_contains($page, 'Найдено: 3'), 'сколько найдено');
});

t_case('экранирование и сообщения', function (): void {
    $ctx = t_admin_ctx();
    catalog_create_product($ctx['db'], 'anna', t_fields(['title' => '<b>жирный</b>']), t_now());
    $page = t_admin_call($ctx, 'products', 'admin_page_products')['body'];
    t_true(str_contains($page, '&lt;b&gt;жирный&lt;/b&gt;') && !str_contains($page, '<b>жирный</b>'), 'название экранировано');
    $search = t_admin_call($ctx, 'products', 'admin_page_products', query: ['q' => '"><script>'])['body'];
    t_true(!str_contains($search, '"><script>') && str_contains($search, 'value="&quot;&gt;&lt;script&gt;"'), 'строка поиска экранирована');
    t_true(str_contains(t_admin_call($ctx, 'products', 'admin_page_products', query: ['notice' => 'saved'])['body'], '<p class="notice">Сохранено.</p>'), 'сообщение после действия');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/pages-products.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/queries.php`:

```php
<?php
/**
 * Чтение каталога для экранов админки. Только SELECT — правила изменений
 * живут в catalog/products.php и catalog/sections.php.
 */

declare(strict_types=1);

/** Разделы в порядке сетки каталога; у каждого раздела есть плитка, у скрытого visible = 0. */
function admin_sections(PDO $db): array
{
    return $db->query('SELECT s.slug, s.label, s.visible FROM sections s LEFT JOIN tiles t ON t.section = s.slug
        ORDER BY t.position IS NULL, t.position, s.slug')->fetchAll();
}

/** Все букеты для списка: с фото и разделами, последние изменённые — сверху. */
function admin_products(PDO $db): array
{
    $members = [];
    foreach ($db->query('SELECT uid, section FROM product_sections ORDER BY section') as $m) {
        $members[$m['uid']][] = $m['section'];
    }
    $rows = $db->query('SELECT uid, slug, title, price, images, main_section, status, updated_at FROM products
        ORDER BY updated_at DESC, uid')->fetchAll();
    foreach ($rows as &$row) {
        $row['images'] = json_decode($row['images'], true) ?: [];
        $row['sections'] = $members[$row['uid']] ?? [];
    }
    return $rows;
}
```

Create `server-pay/admin/lib/pages-products.php`:

```php
<?php
/**
 * Букеты: список с поиском и фильтрами, карточка, действия со статусом.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../../catalog/products.php';

/** Фильтр «Статус»: значение => подпись. Пустое значение — все, кроме удалённых. */
const ADMIN_STATUS_FILTER = ['' => 'Все, кроме удалённых', 'active' => 'В продаже', 'hidden' => 'Сняты с продажи', 'draft' => 'Черновики', 'deleted' => 'Удалённые'];

function admin_page_products(array $req, array $ctx): array
{
    $db = $ctx['db'];
    $q = trim(admin_str($req['query'], 'q'));
    $section = admin_str($req['query'], 'section');
    $status = admin_str($req['query'], 'status');
    $sections = admin_sections($db);
    $labels = array_column($sections, 'label', 'slug');
    $needle = mb_strtolower($q);
    // Поиск — в PHP: LIKE и lower() в SQLite не понимают регистр кириллицы, а букетов — сотни.
    $items = array_filter(admin_products($db), function (array $p) use ($needle, $section, $status): bool {
        if ($status === '' ? $p['status'] === 'deleted' : $p['status'] !== $status) {
            return false;
        }
        if ($section !== '' && !in_array($section, $p['sections'], true)) {
            return false;
        }
        return $needle === '' || str_contains(mb_strtolower($p['title']), $needle);
    });

    $sectionOptions = '<option value="">Все разделы</option>';
    foreach ($sections as $s) {
        $sectionOptions .= '<option value="' . h($s['slug']) . '"' . ($s['slug'] === $section ? ' selected' : '') . '>' . h($s['label']) . '</option>';
    }
    $statusOptions = '';
    foreach (ADMIN_STATUS_FILTER as $value => $label) {
        $statusOptions .= '<option value="' . h($value) . '"' . ($value === $status ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $list = '';
    foreach ($items as $p) {
        $thumb = $p['images'] !== []
            ? '<img src="' . h($p['images'][0]) . '" alt="" loading="lazy">'
            : '<span class="thumb"></span>';
        $where = implode(', ', array_map(fn (string $s): string => $labels[$s] ?? $s, $p['sections']));
        $list .= '<li><a href="' . ADMIN_BASE . 'product.php?uid=' . h($p['uid']) . '">' . $thumb . '<span>'
            . '<span class="item-title">' . h($p['title']) . '</span><br><span class="item-meta">'
            . admin_rub((int)$p['price']) . ' · <span class="badge badge-' . h($p['status']) . '">'
            . h(ADMIN_STATUS_LABELS[$p['status']] ?? $p['status']) . '</span> · ' . h($where) . '</span></span></a></li>';
    }
    $html = '<h1>Букеты</h1>'
        . '<p class="buttons"><a class="btn" href="' . ADMIN_BASE . 'product.php?new=1">Добавить букет</a></p>'
        . '<form method="get" action="' . ADMIN_BASE . '" class="form filters">'
        . '<label>Поиск<input type="search" name="q" value="' . h($q) . '" placeholder="Название букета"></label>'
        . '<label>Раздел<select name="section">' . $sectionOptions . '</select></label>'
        . '<label>Статус<select name="status">' . $statusOptions . '</select></label>'
        . '<p class="buttons"><button class="btn-quiet" type="submit">Показать</button></p></form>'
        . '<p class="hint">Найдено: ' . count($items) . '</p>'
        . ($list !== '' ? '<ul class="items">' . $list . '</ul>' : '<p>Ничего не нашлось.</p>');
    return admin_html(admin_layout('Букеты', $html, $ctx['user'], $ctx['status'], admin_notice($req)));
}
```

Create `server-pay/admin/index.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-products.php';

admin_run('products', 'admin_page_products');
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/queries.php server-pay/admin/lib/pages-products.php server-pay/admin/index.php tests/php/admin_products_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): список букетов — поиск, фильтры по разделу и статусу

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Карточка букета — создание и правка

**Files:**
- Create: `server-pay/admin/lib/forms.php`
- Modify: `server-pay/admin/lib/pages-products.php` (подключить `forms.php`, добавить функции)
- Create: `server-pay/admin/product.php`
- Test: `tests/php/admin_card_test.php`

**Interfaces:**
- Consumes: `catalog_create_product`, `catalog_update_product`, `catalog_product_row`, `catalog_product_sections`, `catalog_find_namesake` (2А), `CatalogError`, `CatalogConflict`.
- Produces (`forms.php`):
  - `admin_parse_price(string $raw): int` — «4 400», «4400 ₽» → 4400; иначе `CatalogError('Цена — целое число рублей, например 4400.')`
  - `admin_product_fields(array $post): array` — поля для `catalog_*_product` (`title`, `description`, `price` int, `images`, `sections`, `mainSection`)
  - `admin_price_jump(int $old, int $new): bool`
- Produces (`pages-products.php`):
  - `admin_page_product(array $req, array $ctx): array` — GET `?new=1` / `?uid=`, POST `action` = `create` | `save` | `publish` | прочие (Task 8)
  - `admin_find_product(PDO $db, string $uid): ?array`
  - `admin_sections_picker(array $sections, array $checked, string $main): string`
  - `admin_new_form(array $ctx, array $post, string $error, ?array $twin): string`
  - `admin_product_form(array $ctx, array $p, ?array $post, string $error, string $ask): string` — `$post` null: значения из базы
  - `admin_photo_list(array $images): string`
  - `admin_product_create(array $req, array $ctx): array`, `admin_product_save(array $req, array $ctx, string $action): array`

Создание — в два шага: короткая форма (название, цена, состав, разделы) создаёт черновик, фото добавляются уже в его карточке. Так у каждого фото с первой секунды есть uid и slug для имени файла, а брошенная форма не оставляет черновиков.

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_card_test.php`:

```php
<?php
/**
 * Карточка букета: создание черновика, тёзка среди снятых, правка, вопрос о
 * цене, одновременная правка.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

function t_card(array $ctx, string $method = 'GET', array $query = [], array $post = []): array
{
    return t_admin_call($ctx, 'product', 'admin_page_product', $method, $query, $post);
}

t_case('цена из формы', function (): void {
    t_equal(admin_parse_price('4 400'), 4400, 'с пробелом');
    t_equal(admin_parse_price("4\u{00A0}400\u{00A0}₽"), 4400, 'как её пишет админка');
    t_equal(admin_parse_price(' 4400 руб. '), 4400, 'с «руб.»');
    foreach (['', 'дорого', '4400.50', '-100', '12345678'] as $bad) {
        t_throws(fn () => admin_parse_price($bad), CatalogError::class, "не цена: «$bad»");
    }
    t_equal([admin_price_jump(4400, 44000), admin_price_jump(4400, 8800), admin_price_jump(4400, 2199), admin_price_jump(4400, 2200)],
        [true, false, true, false], 'больше чем вдвое — в обе стороны; ровно вдвое — без вопроса');
});

t_case('новый букет', function (): void {
    $ctx = t_admin_ctx();
    $form = t_card($ctx, query: ['new' => '1'])['body'];
    t_true(str_contains($form, 'name="action" value="create"') && str_contains($form, 'name="sections[]" value="bukety"'), 'форма нового букета с разделами');
    t_true(str_contains($form, '(скрыт)'), 'скрытые разделы подписаны');
    $r = t_card($ctx, 'POST', post: ['action' => 'create', 'title' => 'Букет «Нежность»', 'price' => '4 400 ₽', 'description' => 'Розы', 'sections' => ['bukety']]);
    t_equal($r['status'], 303, 'черновик создан');
    parse_str((string)parse_url($r['headers']['Location'], PHP_URL_QUERY), $q);
    t_equal($q['notice'] ?? '', 'created', 'с сообщением');
    $p = t_row($ctx['db'], $q['uid']);
    t_equal([$p['status'], $p['price'], $p['title']], ['draft', 4400, 'Букет «Нежность»'], 'цена «4 400 ₽» понята как 4400');
});

t_case('ошибки в форме нового букета', function (): void {
    $ctx = t_admin_ctx();
    $r = t_card($ctx, 'POST', post: ['action' => 'create', 'title' => 'Букет <Тест>', 'price' => 'дорого', 'sections' => ['bukety']]);
    t_equal($r['status'], 422, 'не сохранено');
    t_true(str_contains($r['body'], 'Цена — целое число рублей'), 'объяснение для сотрудника');
    t_true(str_contains($r['body'], 'value="Букет &lt;Тест&gt;"'), 'введённое название не потерялось и экранировано');
    t_true(str_contains($r['body'], 'name="sections[]" value="bukety" checked'), 'и отмеченный раздел тоже');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'в базе ничего');
});

t_case('тёзка среди снятых', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $old = catalog_create_product($db, 'anna', t_fields(), t_now('-10 days'));
    catalog_publish($db, 'anna', $old, 1, t_now('-10 days'));
    catalog_hide($db, 'anna', $old, 2, t_now('-5 days'));
    $post = ['action' => 'create', 'title' => 'Букет «Нежность»', 'price' => '4400', 'sections' => ['bukety']];
    $ask = t_card($ctx, 'POST', post: $post);
    t_true(str_contains($ask['body'], 'Такой букет уже был — снят с продажи 30 сентября. Вернуть его?'), 'админка предлагает вернуть прежний');
    t_true(str_contains($ask['body'], 'href="/pay/admin/product.php?uid=' . $old . '"'), 'со ссылкой на него');
    t_true(str_contains($ask['body'], 'name="confirm_new" value="1"'), 'повторное «Создать» — если это другой букет');
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 1, 'новый пока не создан');
    t_equal(t_card($ctx, 'POST', post: $post + ['confirm_new' => '1'])['status'], 303, 'это другой букет — создаётся');
});

t_case('карточка и сохранение', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(['images' => ['/images/catalog/bukety/a.webp']]), t_now());
    $card = t_card($ctx, query: ['uid' => $uid])['body'];
    t_true(str_contains($card, 'value="Букет «Нежность»"') && str_contains($card, 'Адрес появится после публикации'), 'карточка черновика');
    t_true(str_contains($card, 'name="images[]" value="/images/catalog/bukety/a.webp"'), 'фото в форме — сохранение их не потеряет');
    t_true(str_contains($card, 'value="save"') && str_contains($card, 'value="publish"'), 'у черновика — «Сохранить черновик» и «Опубликовать»');
    $post = [
        'action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Весна»', 'price' => '4800', 'description' => 'Розы',
        'sections' => ['bukety', 'roses'], 'mainSection' => 'roses', 'images' => ['/images/catalog/bukety/a.webp'],
    ];
    $r = t_card($ctx, 'POST', post: $post);
    t_equal([$r['status'], $r['headers']['Location']], [303, '/pay/admin/product.php?uid=' . $uid . '&notice=saved'], 'сохранено');
    $p = t_row($db, $uid);
    t_equal([$p['title'], $p['price'], $p['main_section'], $p['images'], $p['version']],
        ['Букет «Весна»', 4800, 'roses', '["/images/catalog/bukety/a.webp"]', 2], 'всё записано');
});

t_case('цена изменилась больше чем вдвое', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $post = ['action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Нежность»', 'price' => '44000', 'sections' => ['bukety']];
    $ask = t_card($ctx, 'POST', post: $post);
    t_true(str_contains($ask['body'], "Цена была 4\u{00A0}400\u{00A0}₽, станет 44\u{00A0}000\u{00A0}₽. Всё верно?"), 'админка переспрашивает');
    t_true(str_contains($ask['body'], 'name="confirm_price" value="1"'), 'повторное «Сохранить» подтвердит цену');
    t_equal(t_row($ctx['db'], $uid)['price'], 4400, 'пока не подтвердили — цена прежняя');
    t_equal(t_card($ctx, 'POST', post: $post + ['confirm_price' => '1'])['status'], 303, 'подтвердили — сохранено');
    t_equal(t_row($ctx['db'], $uid)['price'], 44000, 'новая цена');
});

t_case('одновременная правка', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_update_product($ctx['db'], 'anna', $uid, 1, t_fields(['price' => 4500]), t_now());
    $r = t_card($ctx, 'POST', post: ['action' => 'save', 'uid' => $uid, 'version' => '1', 'title' => 'Мой вариант', 'price' => '4600', 'sections' => ['bukety']]);
    t_true($r['status'] === 409 && str_contains($r['body'], 'уже изменён'), 'чужую правку не затёрли — объяснили');
    t_true(str_contains($r['body'], 'value="Мой вариант"'), 'свой текст виден — его можно скопировать');
    t_equal(t_row($ctx['db'], $uid)['price'], 4500, 'в базе — правка коллеги');
});

t_case('нет такого букета', function (): void {
    $ctx = t_admin_ctx();
    t_equal(t_card($ctx, query: ['uid' => '999'])['status'], 404, 'понятная страница вместо ошибки');
    t_equal(t_card($ctx, 'POST', post: ['action' => 'save', 'uid' => '999'])['status'], 404, 'и при сохранении');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Call to undefined function admin_parse_price()`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/forms.php`:

```php
<?php
/**
 * Поля букета из формы — в виде, который ждут правила каталога
 * (catalog/products.php): цена — целое число, разделы и фото — списки строк.
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../../catalog/db.php';

/** Цена из формы: «4 400», «4 400 ₽», «4400 руб.» → 4400. Не целое число — ошибка для сотрудника. */
function admin_parse_price(string $raw): int
{
    $digits = (string)preg_replace('/[\s\x{00A0}₽]|руб\.?/u', '', $raw);
    if ($digits === '' || !ctype_digit($digits) || strlen($digits) > 7) {
        throw new CatalogError('Цена — целое число рублей, например 4400.');
    }
    return (int)$digits;
}

function admin_product_fields(array $post): array
{
    return [
        'title' => admin_str($post, 'title'),
        'description' => admin_str($post, 'description'),
        'price' => admin_parse_price(admin_str($post, 'price')),
        'images' => admin_list($post, 'images'),
        'sections' => admin_list($post, 'sections'),
        'mainSection' => admin_str($post, 'mainSection'),
    ];
}

/** Цена изменилась больше чем вдвое (в любую сторону) — переспросить: лишний ноль стоит дорого. */
function admin_price_jump(int $old, int $new): bool
{
    return $old > 0 && ($new > $old * 2 || $new * 2 < $old);
}
```

In `server-pay/admin/lib/pages-products.php` after `require_once __DIR__ . '/../../catalog/products.php';` add:

```php
require_once __DIR__ . '/forms.php';
```

Append to `server-pay/admin/lib/pages-products.php`:

```php
function admin_page_product(array $req, array $ctx): array
{
    if ($req['method'] === 'POST') {
        $action = admin_str($req['post'], 'action');
        if ($action === 'create') {
            return admin_product_create($req, $ctx);
        }
        if ($action === 'save' || $action === 'publish') {
            return admin_product_save($req, $ctx, $action);
        }
        return admin_html(admin_layout('Ошибка', admin_error('Неизвестное действие — обновите страницу.'), $ctx['user'], $ctx['status']), 400);
    }
    if (admin_str($req['query'], 'new') !== '') {
        return admin_html(admin_layout('Новый букет', admin_new_form($ctx, [], '', null), $ctx['user'], $ctx['status']));
    }
    $p = admin_find_product($ctx['db'], admin_str($req['query'], 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    return admin_html(admin_layout($p['title'], admin_product_form($ctx, $p, null, '', ''), $ctx['user'], $ctx['status'], admin_notice($req)));
}

function admin_find_product(PDO $db, string $uid): ?array
{
    try {
        return catalog_product_row($db, $uid);
    } catch (CatalogError) {
        return null;
    }
}

/** Галочки разделов и выбор главного: по главному строится адрес страницы. */
function admin_sections_picker(array $sections, array $checked, string $main): string
{
    $rows = '';
    foreach ($sections as $s) {
        $slug = $s['slug'];
        $rows .= '<li><label class="check"><input type="checkbox" name="sections[]" value="' . h($slug) . '"'
            . (in_array($slug, $checked, true) ? ' checked' : '') . '> ' . h($s['label'])
            . ($s['visible'] ? '' : ' <span class="hint">(скрыт)</span>') . '</label>'
            . '<label class="check"><input type="radio" name="mainSection" value="' . h($slug) . '"'
            . ($slug === $main ? ' checked' : '') . '> главный</label></li>';
    }
    return '<fieldset class="form"><legend>Разделы</legend>'
        . '<p class="hint">Можно несколько. Главный — по нему строится адрес страницы; если его не выбрать, '
        . 'главным станет первый отмеченный (кроме «Новинок»).</p>'
        . '<ul class="sections-pick">' . $rows . '</ul></fieldset>';
}

/** Первый шаг нового букета. $twin — снятый или удалённый тёзка: его предлагают вернуть. */
function admin_new_form(array $ctx, array $post, string $error, ?array $twin): string
{
    $twinBox = '';
    if ($twin !== null) {
        $what = $twin['status'] === 'hidden' ? 'снят с продажи' : 'удалён';
        $twinBox = '<div class="ask"><p>Такой букет уже был — ' . $what . ' ' . h(admin_day($twin['since'])) . '. Вернуть его?</p>'
            . '<p class="buttons"><a class="btn" href="' . ADMIN_BASE . 'product.php?uid=' . h($twin['uid']) . '">Открыть прежний букет</a></p>'
            . '<p class="hint">Если это другой букет — нажмите «Создать черновик» ещё раз.</p></div>';
    }
    return '<h1>Новый букет</h1>' . $twinBox . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="create">'
        . ($twin !== null ? '<input type="hidden" name="confirm_new" value="1">' : '')
        . '<label>Название<input name="title" maxlength="120" required value="' . h(admin_str($post, 'title')) . '"></label>'
        . '<label>Цена, ₽<input name="price" inputmode="numeric" required value="' . h(admin_str($post, 'price')) . '"></label>'
        . '<label>Состав<textarea name="description" maxlength="1000">' . h(admin_str($post, 'description')) . '</textarea></label>'
        . admin_sections_picker(admin_sections($ctx['db']), admin_list($post, 'sections'), admin_str($post, 'mainSection'))
        . '<p class="hint">Фото добавите на следующем шаге. Черновика на сайте нет, пока вы его не опубликуете.</p>'
        . '<p class="buttons"><button class="btn" type="submit">Создать черновик</button></p></form>';
}

/** Фото букета в форме: превью и скрытые поля, чтобы сохранение их не потеряло. */
function admin_photo_list(array $images): string
{
    $items = '';
    foreach ($images as $path) {
        $items .= '<li><img src="' . h($path) . '" alt=""><input type="hidden" name="images[]" value="' . h($path) . '"></li>';
    }
    return '<div class="photos"><ul>' . $items . '</ul></div>';
}

/**
 * Карточка букета. $post — что прислала форма (показать снова после ошибки
 * или вопроса о цене), null — значения из базы. $ask — вопрос перед
 * сохранением (цена изменилась больше чем вдвое).
 */
function admin_product_form(array $ctx, array $p, ?array $post, string $error, string $ask): string
{
    $db = $ctx['db'];
    $images = $post !== null ? admin_list($post, 'images') : (json_decode($p['images'], true) ?: []);
    $checked = $post !== null ? admin_list($post, 'sections') : catalog_product_sections($db, $p['uid']);
    $main = $post !== null ? admin_str($post, 'mainSection') : $p['main_section'];
    $value = fn (string $field): string => $post !== null ? admin_str($post, $field) : (string)$p[$field];
    $address = 'https://pionperm.ru/' . $p['main_section'] . '/' . $p['slug'] . '/';
    $html = '<h1>' . h($p['title']) . ' <span class="badge badge-' . h($p['status']) . '">' . h(ADMIN_STATUS_LABELS[$p['status']]) . '</span></h1>'
        . ($p['status'] === 'draft'
            ? '<p class="hint">Адрес появится после публикации: <span class="address">' . h($address) . '</span></p>'
            : '<p class="hint">Адрес страницы: <span class="address">' . h($address) . '</span></p>')
        . admin_error($error)
        . ($ask !== '' ? '<div class="ask"><p>' . h($ask) . '</p>'
            . '<p class="hint">Если всё верно — нажмите «Сохранить» ещё раз. Если нет — исправьте цену.</p></div>' : '');
    if ($p['status'] === 'deleted') {
        return $html . '<p>Букет удалён ' . h(admin_date((string)$p['deleted_at']))
            . '. Его нет на сайте; со старого адреса — переадресация в раздел.</p>' . admin_photo_list($images);
    }
    $buttons = $p['status'] === 'draft'
        ? '<button class="btn-quiet" type="submit" name="action" value="save">Сохранить черновик</button>'
            . '<button class="btn" type="submit" name="action" value="publish">Опубликовать</button>'
        : '<button class="btn" type="submit" name="action" value="save">Сохранить</button>';
    return $html . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
        . '<input type="hidden" name="version" value="' . h($post !== null ? admin_str($post, 'version') : (string)$p['version']) . '">'
        . ($ask !== '' ? '<input type="hidden" name="confirm_price" value="1">' : '')
        . '<label>Название<input name="title" maxlength="120" required value="' . h($value('title')) . '"></label>'
        . '<label>Цена, ₽<input name="price" inputmode="numeric" required value="' . h($value('price')) . '"></label>'
        . '<label>Состав<textarea name="description" maxlength="1000">' . h($value('description')) . '</textarea></label>'
        . '<fieldset class="form"><legend>Фото</legend><p class="hint">До четырёх. Первое — главное.</p>' . admin_photo_list($images) . '</fieldset>'
        . admin_sections_picker(admin_sections($db), $checked, $main)
        . '<p class="buttons">' . $buttons . '</p></form>';
}

function admin_product_create(array $req, array $ctx): array
{
    $post = $req['post'];
    $show = fn (string $error, ?array $twin, int $status): array => admin_html(
        admin_layout('Новый букет', admin_new_form($ctx, $post, $error, $twin), $ctx['user'], $ctx['status']), $status);
    try {
        $fields = admin_product_fields($post);
        if (admin_str($post, 'confirm_new') === '') {
            $twin = catalog_find_namesake($ctx['db'], $fields['title'], $ctx['now']);
            if ($twin !== null) {
                return $show('', $twin, 200);
            }
        }
        $uid = catalog_create_product($ctx['db'], $ctx['user']['login'], $fields, $ctx['now']);
        return admin_redirect('product.php', ['uid' => $uid, 'notice' => 'created']);
    } catch (CatalogError $e) {
        return $show($e->getMessage(), null, 422);
    }
}

function admin_product_save(array $req, array $ctx, string $action): array
{
    $post = $req['post'];
    $p = admin_find_product($ctx['db'], admin_str($post, 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    $show = fn (string $error, string $ask, int $status): array => admin_html(
        admin_layout($p['title'], admin_product_form($ctx, $p, $post, $error, $ask), $ctx['user'], $ctx['status']), $status);
    try {
        $fields = admin_product_fields($post);
        if (admin_str($post, 'confirm_price') === '' && admin_price_jump((int)$p['price'], $fields['price'])) {
            return $show('', 'Цена была ' . admin_rub((int)$p['price']) . ', станет ' . admin_rub($fields['price']) . '. Всё верно?', 200);
        }
        $version = (int)admin_str($post, 'version');
        catalog_update_product($ctx['db'], $ctx['user']['login'], $p['uid'], $version, $fields, $ctx['now']);
        return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'saved']);
    } catch (CatalogConflict $e) {
        return $show($e->getMessage(), '', 409);
    } catch (CatalogError $e) {
        return $show($e->getMessage(), '', 422);
    }
}
```

Create `server-pay/admin/product.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-products.php';

admin_run('product', 'admin_page_product');
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/forms.php server-pay/admin/lib/pages-products.php server-pay/admin/product.php tests/php/admin_card_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): карточка букета — создание черновика, правка, вопрос о цене, тёзки

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Статусы из карточки — опубликовать, снять, вернуть, удалить, восстановить

**Files:**
- Modify: `server-pay/admin/lib/pages-products.php`
- Test: `tests/php/admin_actions_test.php`

**Interfaces:**
- Consumes: `catalog_publish`, `catalog_hide`, `catalog_unhide`, `catalog_delete`, `catalog_restore`, `CATALOG_RESTORE_DAYS` (2А); функции Task 7.
- Produces:
  - `const ADMIN_ACTION_NOTICES = ['hide' => 'hidden', 'unhide' => 'unhidden', 'delete' => 'deleted', 'restore' => 'restored']`
  - `admin_product_action(array $req, array $ctx, string $action): array`
  - `admin_confirm_page(array $ctx, array $p, string $action, int $version): string`
  - `admin_product_extras(array $ctx, array $p): string` — блок «Действия» под карточкой (Task 11 добавит в него историю)
  - `admin_product_save`: `action=publish` — сохранить и опубликовать

Кнопки по статусу (спецификация): черновик — «Сохранить черновик», «Опубликовать», «Удалить»; в продаже — «Сохранить», «Снять с продажи», «Удалить»; снят — «Сохранить», «Вернуть в продажу», «Удалить»; удалён — «Восстановить» (90 дней). Удаление и снятие — с подтверждением. Действия со статусом — отдельные формы: правки в карточке они не сохраняют (об этом подсказка).

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_actions_test.php`:

```php
<?php
/**
 * Статусы из карточки: опубликовать, снять (с подтверждением), вернуть,
 * удалить (с подтверждением), восстановить; кнопки по статусу.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

/** Действие из карточки: POST с uid и версией. */
function t_action(array $ctx, string $uid, string $action, int $version, array $extra = []): array
{
    return t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: ['action' => $action, 'uid' => $uid, 'version' => (string)$version] + $extra);
}

function t_card_body(array $ctx, string $uid): string
{
    return t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
}

t_case('опубликовать из карточки', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $r = t_action($ctx, $uid, 'publish', 1, ['title' => 'Букет «Весна»', 'price' => '4400', 'sections' => ['bukety']]);
    t_equal($r['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=published', 'опубликован');
    $p = t_row($ctx['db'], $uid);
    t_equal([$p['status'], $p['title'], $p['slug']], ['active', 'Букет «Весна»', 'buket-vesna'], 'правки сохранены, адрес закреплён по новому названию');
});

t_case('снять с продажи и вернуть', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $ask = t_action($ctx, $uid, 'hide', 2);
    t_true($ask['status'] === 200 && str_contains($ask['body'], 'с продажи?') && str_contains($ask['body'], 'name="confirm" value="1"'), 'сначала — вопрос');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'без подтверждения ничего не изменилось');
    t_equal(t_action($ctx, $uid, 'hide', 2, ['confirm' => '1'])['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=hidden', 'подтвердили — снят');
    t_equal(t_row($ctx['db'], $uid)['status'], 'hidden', 'в базе — снят');
    t_action($ctx, $uid, 'unhide', 3);
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'вернули в продажу без лишних вопросов');
});

t_case('удалить и восстановить', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $ask = t_action($ctx, $uid, 'delete', 2);
    t_true(str_contains($ask['body'], 'Восстановить букет можно в течение 90 дней'), 'вопрос объясняет последствия');
    t_equal(t_action($ctx, $uid, 'delete', 2, ['confirm' => '1'])['headers']['Location'], '/pay/admin/product.php?uid=' . $uid . '&notice=deleted', 'удалён');
    $card = t_card_body($ctx, $uid);
    t_true(str_contains($card, 'value="restore"') && !str_contains($card, 'value="save"'), 'у удалённого — только «Восстановить»');
    t_action($ctx, $uid, 'restore', 3);
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'восстановлен в продажу');
});

t_case('удалить черновик', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    t_true(str_contains(t_action($ctx, $uid, 'delete', 1)['body'], 'на сайте его не было'), 'вопрос про черновик');
    t_equal(t_action($ctx, $uid, 'delete', 1, ['confirm' => '1'])['headers']['Location'], '/pay/admin/?notice=removed', 'после удаления — к списку');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'черновика больше нет');
});

t_case('кнопки по статусу', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $draft = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $body = t_card_body($ctx, $draft);
    t_true(str_contains($body, 'value="publish"') && str_contains($body, 'value="delete"') && !str_contains($body, 'value="hide"'), 'черновик: опубликовать, удалить');
    $active = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    catalog_publish($db, 'anna', $active, 1, t_now());
    $body = t_card_body($ctx, $active);
    t_true(str_contains($body, 'value="hide"') && !str_contains($body, 'value="publish"'), 'в продаже: снять с продажи');
    t_true(str_contains($body, 'Несохранённые правки'), 'подсказка: действия не сохраняют правки в карточке');
    catalog_hide($db, 'anna', $active, 2, t_now());
    t_true(str_contains(t_card_body($ctx, $active), 'value="unhide"'), 'снят: вернуть в продажу');
});

t_case('устаревшая версия', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    $r = t_action($ctx, $uid, 'hide', 1, ['confirm' => '1']);
    t_true($r['status'] === 409 && str_contains($r['body'], 'уже изменён'), 'карточку открыли до чужой правки — действие не выполнено, объяснено');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'статус не изменился');
});

t_case('удалённый больше 90 дней', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now('-100 days'));
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now('-100 days'));
    catalog_delete($ctx['db'], 'anna', $uid, 2, t_now('-95 days'));
    $body = t_card_body($ctx, $uid);
    t_true(str_contains($body, 'восстановить его уже нельзя') && !str_contains($body, 'value="restore"'), 'кнопки нет — объяснение есть');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: строки «ПЛОХО» в `admin_actions_test.php` (действия отвечают 400 «Неизвестное действие», нет кнопок).

- [ ] **Step 3: Реализация**

In `server-pay/admin/lib/pages-products.php`:

1. Replace in `admin_page_product` the lines

```php
        return admin_html(admin_layout('Ошибка', admin_error('Неизвестное действие — обновите страницу.'), $ctx['user'], $ctx['status']), 400);
```

with

```php
        return admin_product_action($req, $ctx, $action);
```

and the line

```php
    return admin_html(admin_layout($p['title'], admin_product_form($ctx, $p, null, '', ''), $ctx['user'], $ctx['status'], admin_notice($req)));
```

with

```php
    return admin_html(admin_layout($p['title'], admin_product_form($ctx, $p, null, '', '') . admin_product_extras($ctx, $p),
        $ctx['user'], $ctx['status'], admin_notice($req)));
```

2. In `admin_product_save` replace

```php
        catalog_update_product($ctx['db'], $ctx['user']['login'], $p['uid'], $version, $fields, $ctx['now']);
        return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'saved']);
```

with

```php
        catalog_update_product($ctx['db'], $ctx['user']['login'], $p['uid'], $version, $fields, $ctx['now']);
        if ($action === 'publish') {
            // «Опубликовать» из карточки черновика: сначала правки, потом публикация — версия уже на один больше.
            catalog_publish($ctx['db'], $ctx['user']['login'], $p['uid'], $version + 1, $ctx['now']);
            return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'published']);
        }
        return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'saved']);
```

3. Append:

```php
const ADMIN_ACTION_NOTICES = ['hide' => 'hidden', 'unhide' => 'unhidden', 'delete' => 'deleted', 'restore' => 'restored'];

/** Снять, вернуть, удалить, восстановить. Снять и удалить — только после подтверждения. */
function admin_product_action(array $req, array $ctx, string $action): array
{
    $post = $req['post'];
    $p = admin_find_product($ctx['db'], admin_str($post, 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    if (!isset(ADMIN_ACTION_NOTICES[$action])) {
        return admin_html(admin_layout('Ошибка', admin_error('Неизвестное действие — обновите страницу.'), $ctx['user'], $ctx['status']), 400);
    }
    $version = (int)admin_str($post, 'version');
    if (in_array($action, ['hide', 'delete'], true) && admin_str($post, 'confirm') !== '1') {
        return admin_html(admin_layout($p['title'], admin_confirm_page($ctx, $p, $action, $version), $ctx['user'], $ctx['status']));
    }
    $db = $ctx['db'];
    $login = $ctx['user']['login'];
    try {
        match ($action) {
            'hide' => catalog_hide($db, $login, $p['uid'], $version, $ctx['now']),
            'unhide' => catalog_unhide($db, $login, $p['uid'], $version, $ctx['now']),
            'delete' => catalog_delete($db, $login, $p['uid'], $version, $ctx['now']),
            'restore' => catalog_restore($db, $login, $p['uid'], $version, $ctx['now']),
        };
    } catch (CatalogConflict|CatalogError $e) {
        $fresh = admin_find_product($db, $p['uid']) ?? $p;
        $html = admin_product_form($ctx, $fresh, null, $e->getMessage(), '') . admin_product_extras($ctx, $fresh);
        return admin_html(admin_layout($fresh['title'], $html, $ctx['user'], $ctx['status']), $e instanceof CatalogConflict ? 409 : 422);
    }
    if ($action === 'delete' && $p['status'] === 'draft') {
        return admin_redirect('', ['notice' => 'removed']);
    }
    return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => ADMIN_ACTION_NOTICES[$action]]);
}

function admin_confirm_page(array $ctx, array $p, string $action, int $version): string
{
    $name = '«' . $p['title'] . '»';
    [$question, $explain, $button] = match (true) {
        $action === 'hide' => [
            'Снять ' . $name . ' с продажи?',
            'Страница останется на сайте с пометкой «Сейчас нет в продаже», заказать букет будет нельзя. Вернуть его в продажу можно в любой момент — на прежнее место.',
            'Снять с продажи',
        ],
        $p['status'] === 'draft' => ['Удалить черновик ' . $name . '?', 'Он исчезнет совсем — на сайте его не было.', 'Удалить черновик'],
        default => [
            'Удалить ' . $name . '?',
            'Страница исчезнет с сайта, с её адреса будет переадресация в раздел. Восстановить букет можно в течение 90 дней.',
            'Удалить',
        ],
    };
    return '<h1>' . h($question) . '</h1><p>' . h($explain) . '</p>'
        . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="buttons">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
        . '<input type="hidden" name="version" value="' . $version . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<button class="btn-danger" type="submit">' . h($button) . '</button>'
        . '<a class="btn-quiet" href="' . ADMIN_BASE . 'product.php?uid=' . h($p['uid']) . '">Отмена</a></form>';
}

/** Блок «Действия» под карточкой — отдельные формы: правки в карточке они не сохраняют. */
function admin_product_extras(array $ctx, array $p): string
{
    if ($p['status'] === 'deleted' && (string)$p['deleted_at'] < catalog_iso($ctx['now']->modify('-' . CATALOG_RESTORE_DAYS . ' days'))) {
        return '<h2>Действия</h2><p>Букет удалён больше 90 дней назад — восстановить его уже нельзя.</p>';
    }
    $actions = match ($p['status']) {
        'draft' => ['delete' => ['Удалить черновик', 'btn-danger']],
        'active' => ['hide' => ['Снять с продажи', 'btn-quiet'], 'delete' => ['Удалить', 'btn-danger']],
        'hidden' => ['unhide' => ['Вернуть в продажу', 'btn'], 'delete' => ['Удалить', 'btn-danger']],
        default => ['restore' => ['Восстановить', 'btn']],
    };
    $forms = '';
    foreach ($actions as $action => [$label, $class]) {
        $forms .= '<form method="post" action="' . ADMIN_BASE . 'product.php">' . admin_csrf_field($ctx['user'])
            . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
            . '<input type="hidden" name="version" value="' . (int)$p['version'] . '">'
            . '<button class="' . $class . '" type="submit" name="action" value="' . h($action) . '">' . h($label) . '</button></form>';
    }
    return '<h2>Действия</h2>'
        . ($p['status'] !== 'deleted' ? '<p class="hint">Несохранённые правки в карточке выше при этом не сохранятся.</p>' : '')
        . '<div class="buttons">' . $forms . '</div>';
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/pages-products.php tests/php/admin_actions_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): статусы из карточки — опубликовать, снять, вернуть, удалить, восстановить

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Фото — загрузка, порядок, корзина

**Files:**
- Create: `server-pay/admin/lib/photos.php`
- Create: `server-pay/admin/lib/pages-photos.php`
- Create: `server-pay/admin/photo.php`
- Create: `server-pay/admin/assets/photo.js`
- Create: `server-pay/catalog/photo-files.php`
- Modify: `server-pay/admin/lib/pages-products.php` (виджет фото в карточке; восстановление возвращает фото из корзины)
- Modify: `tests/php/admin_fixture.php` (`t_jpeg`, `t_upload`)
- Test: `tests/php/admin_photos_test.php`

**Interfaces:**
- Consumes: `CATALOG_IMAGE_PATH`, `CATALOG_IMAGES_MAX` (`catalog/products.php`), `CATALOG_SITE_IMAGE` (`catalog/sections.php`), `catalog_product_row`.
- Produces (`photos.php`):
  - `const ADMIN_PHOTO_MAX_BYTES = 20971520`, `ADMIN_PHOTO_WIDTH = 900`, `ADMIN_COVER_WIDTH = 1600`, `ADMIN_WEBP_QUALITY = 82`
  - `admin_photo_webp(string $bytes, int $maxWidth): string` — байты WebP; не картинка — `CatalogError`
  - `admin_write_file(string $path, string $bytes): void`
  - `admin_store_product_photo(string $webroot, array $product, string $bytes): string` — `/images/catalog/<main_section>/<slug>-<uid>-<хэш8>.webp`
  - `admin_store_section_photo(string $webroot, string $slug, string $kind, string $bytes): string` — `/images/catalog/_sections/<slug>-<tile|cover>-<хэш8>.webp`
  - `admin_photo_widget(array $user, string $name, array $paths, int $limit, array $data): string` — `$name` — имя поля (`images[]`, `covers[]`, `tileImage`), `$data` — `data-*` для запроса (`uid` или `section` и `kind`)
- Produces (`pages-photos.php`): `admin_page_photo(array $req, array $ctx): array` — JSON `{ok: true, path}` / `{ok: false, error}`
- Produces (`catalog/photo-files.php`): `const CATALOG_TRASH_DIR = '/images/catalog/_deleted'`, `catalog_photo_trash_path(string $rel): ?string`, `catalog_photo_trash(string $webroot, string $rel, DateTimeImmutable $now): bool`, `catalog_photo_untrash(string $webroot, string $rel): bool`
- Produces (проверки): `t_jpeg(int $width, int $height): string`, `t_upload(string $bytes): array` (как элемент `$_FILES`)

- [ ] **Step 1: Помощники для проверок**

Append to `tests/php/admin_fixture.php`:

```php
/** JPEG заданного размера — как присылает браузер. */
function t_jpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 176, 141, 122));
    ob_start();
    imagejpeg($image, null, 85);
    return (string)ob_get_clean();
}

/** Загруженный файл — как элемент $_FILES. */
function t_upload(string $bytes): array
{
    $tmp = t_tmpdir() . '/upload';
    file_put_contents($tmp, $bytes);
    return ['name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
}
```

- [ ] **Step 2: Проверка (пока падает)**

Create `tests/php/admin_photos_test.php`:

```php
<?php
/**
 * Фото: пересохранение в WebP, имена файлов, приём через photo.php, виджет в
 * карточке, корзина и возврат фото при восстановлении букета.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-photos.php';
require_once __DIR__ . '/../../server-pay/catalog/sections.php';

function t_photo_call(array $ctx, array $post, array $files): array
{
    return t_admin_call($ctx, 'photo', 'admin_page_photo', 'POST', post: $post, files: $files);
}

t_case('пересохранение в WebP', function (): void {
    $webp = admin_photo_webp(t_jpeg(2400, 1200), ADMIN_PHOTO_WIDTH);
    t_true(substr($webp, 0, 4) === 'RIFF' && substr($webp, 8, 4) === 'WEBP', 'на выходе WebP');
    $image = imagecreatefromstring($webp);
    t_equal([imagesx($image), imagesy($image)], [900, 450], 'уменьшено до 900 px по ширине, пропорции те же');
    $small = imagecreatefromstring(admin_photo_webp(t_jpeg(600, 800), ADMIN_PHOTO_WIDTH));
    t_equal([imagesx($small), imagesy($small)], [600, 800], 'маленькое фото не растягивается');
    t_throws(fn () => admin_photo_webp('это не картинка', ADMIN_PHOTO_WIDTH), CatalogError::class, 'не картинка — отказ');
    t_throws(fn () => admin_photo_webp('', ADMIN_PHOTO_WIDTH), CatalogError::class, 'пустой файл — отказ');
});

t_case('фото букета на диске', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $p = t_row($ctx['db'], $uid);
    $path = admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 1000));
    t_true((bool)preg_match('~^/images/catalog/bukety/buket-nezhnost-' . $uid . '-[0-9a-f]{8}\.webp$~', $path), 'имя: slug, uid и 8 знаков хэша; папка главного раздела');
    t_true(is_file($ctx['webroot'] . $path), 'файл записан');
    t_true((bool)preg_match(CATALOG_IMAGE_PATH, $path), 'путь годится для карточки букета');
    t_equal(admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 1000)), $path, 'то же фото — то же имя');
    t_true(admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 999)) !== $path, 'другое фото — другое имя: кэш браузеров не покажет старое');
});

t_case('приём фото', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $r = t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload(t_jpeg(1200, 1600))]);
    $data = json_decode($r['body'], true);
    t_true($r['status'] === 200 && $data['ok'] === true && is_file($ctx['webroot'] . $data['path']), 'фото принято, путь вернулся');
    t_equal($r['headers']['Content-Type'], 'application/json; charset=utf-8', 'ответ — JSON');
    $bad = json_decode(t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload('не картинка')])['body'], true);
    t_true($bad['ok'] === false && str_contains($bad['error'], 'не открылся как изображение'), 'не картинка — понятная ошибка');
    $none = json_decode(t_photo_call($ctx, ['uid' => $uid], [])['body'], true);
    t_equal($none['ok'], false, 'без файла — ошибка');
    $anon = admin_handle(admin_request('POST', post: ['uid' => $uid], files: ['photo' => t_upload(t_jpeg(10, 10))]), $ctx, 'photo', 'admin_page_photo');
    t_equal($anon['status'], 303, 'без входа фото не принимается');
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    catalog_delete($ctx['db'], 'anna', $uid, 2, t_now());
    t_equal(json_decode(t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload(t_jpeg(10, 10))])['body'], true)['ok'], false, 'удалённому букету фото не добавить');
});

t_case('фото раздела', function (): void {
    $ctx = t_admin_ctx();
    $cover = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'cover'], ['photo' => t_upload(t_jpeg(3000, 1200))])['body'], true);
    t_true($cover['ok'] && str_starts_with($cover['path'], '/images/catalog/_sections/roses-cover-') && preg_match(CATALOG_SITE_IMAGE, $cover['path']) === 1, 'обложка раздела');
    t_equal(imagesx(imagecreatefromstring((string)file_get_contents($ctx['webroot'] . $cover['path']))), 1600, 'обложка — до 1600 px, как нынешние');
    $tile = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => t_upload(t_jpeg(3000, 3000))])['body'], true);
    t_equal(imagesx(imagecreatefromstring((string)file_get_contents($ctx['webroot'] . $tile['path']))), 900, 'плитка — до 900 px');
    t_equal(json_decode(t_photo_call($ctx, ['section' => 'nope', 'kind' => 'tile'], ['photo' => t_upload(t_jpeg(10, 10))])['body'], true)['ok'], false, 'нет такого раздела');
});

t_case('фото в карточке', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(['images' => ['/images/catalog/bukety/a.webp']]), t_now());
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    t_true(str_contains($card, 'data-photo-upload') && str_contains($card, 'data-uid="' . $uid . '"') && str_contains($card, 'data-limit="4"'), 'загрузка фото подключена');
    t_true(str_contains($card, 'name="images[]" value="/images/catalog/bukety/a.webp"') && str_contains($card, 'data-act="remove"'), 'фото можно убрать и переставить');
});

t_case('корзина фото и восстановление букета', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $path = admin_store_product_photo($ctx['webroot'], t_row($db, $uid), t_jpeg(100, 100));
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$path]]), t_now());
    catalog_publish($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    t_true(catalog_photo_trash($ctx['webroot'], $path, t_now()), 'фото удалённого убрано в корзину');
    t_true(is_file($ctx['webroot'] . '/images/catalog/_deleted/bukety/' . basename($path)) && !is_file($ctx['webroot'] . $path), 'лежит в _deleted под тем же путём');
    t_equal(filemtime($ctx['webroot'] . '/images/catalog/_deleted/bukety/' . basename($path)), t_now()->getTimestamp(), 'время файла — момент переноса: по нему корзина чистится');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: ['action' => 'restore', 'uid' => $uid, 'version' => '4']);
    t_equal($r['status'], 303, 'букет восстановлен');
    t_true(is_file($ctx['webroot'] . $path), 'и фото вернулось на место');
    t_equal(catalog_photo_trash($ctx['webroot'], '/images/../pay/config.php', t_now()), false, 'за пределы фото каталога корзина не ходит');
    t_equal(catalog_photo_untrash($ctx['webroot'], '/images/catalog/_deleted/x/y.webp'), false, 'и из самой корзины — тоже');
});
```

- [ ] **Step 3: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/pages-photos.php'`.

- [ ] **Step 4: Реализация на сервере**

Create `server-pay/catalog/photo-files.php`:

```php
<?php
/**
 * Корзина фото каталога: images/catalog/_deleted/ — с тем же путём внутри.
 * Туда фото уносит ежедневная уборка (maintenance.php), оттуда их
 * возвращает восстановление букета. Через 90 дней корзина стирает их насовсем.
 */

declare(strict_types=1);

const CATALOG_TRASH_DIR = '/images/catalog/_deleted';

/** /images/catalog/bukety/x.webp → /images/catalog/_deleted/bukety/x.webp; не фото каталога — null. */
function catalog_photo_trash_path(string $rel): ?string
{
    if (!preg_match('~^/images/catalog/(?!_deleted/)([A-Za-z0-9_-]+/[A-Za-z0-9._-]+\.webp)\z~', $rel, $m) || str_contains($rel, '..')) {
        return null;
    }
    return CATALOG_TRASH_DIR . '/' . $m[1];
}

/** Убрать фото в корзину; время файла — момент переноса (по нему корзина и чистится). */
function catalog_photo_trash(string $webroot, string $rel, DateTimeImmutable $now): bool
{
    $trash = catalog_photo_trash_path($rel);
    if ($trash === null || !is_file($webroot . $rel)) {
        return false;
    }
    $to = $webroot . $trash;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
        return false;
    }
    if (!rename($webroot . $rel, $to)) {
        return false;
    }
    touch($to, $now->getTimestamp());
    return true;
}

/** Вернуть фото из корзины на место — если оно там и место свободно. */
function catalog_photo_untrash(string $webroot, string $rel): bool
{
    $trash = catalog_photo_trash_path($rel);
    if ($trash === null || !is_file($webroot . $trash) || is_file($webroot . $rel)) {
        return false;
    }
    $to = $webroot . $rel;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
        return false;
    }
    return rename($webroot . $trash, $to);
}
```

Create `server-pay/admin/lib/photos.php`:

```php
<?php
/**
 * Фото букетов и разделов. Браузер присылает JPEG до 2000 px (assets/photo.js);
 * сервер открывает его через GD — не открылся как картинка, значит не фото, —
 * уменьшает по ширине и сохраняет WebP качества 82, как все фото каталога.
 * От присланного файла остаётся только картинка: ни EXIF, ни чужих данных.
 *
 * Новое фото — новое имя (8 знаков хэша содержимого): фото на сайте
 * кэшируются на 30 дней, и под старым именем браузеры показывали бы прежний снимок.
 */

declare(strict_types=1);

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/../../catalog/db.php';

const ADMIN_PHOTO_MAX_BYTES = 20971520;
const ADMIN_PHOTO_WIDTH = 900;
const ADMIN_COVER_WIDTH = 1600;
const ADMIN_WEBP_QUALITY = 82;

function admin_photo_webp(string $bytes, int $maxWidth): string
{
    if ($bytes === '' || strlen($bytes) > ADMIN_PHOTO_MAX_BYTES) {
        throw new CatalogError('Фото пустое или слишком большое — попробуйте другое.');
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        throw new CatalogError('Файл не открылся как изображение — пришлите фото в JPEG или PNG.');
    }
    $width = imagesx($image);
    if ($width > $maxWidth) {
        $scaled = imagescale($image, $maxWidth, (int)round(imagesy($image) * $maxWidth / $width), IMG_BICUBIC);
        if ($scaled === false) {
            throw new RuntimeException('Не удалось уменьшить фото');
        }
        $image = $scaled;
    }
    // GIF и PNG с палитрой: WebP пишется только из полноцветной картинки.
    imagepalettetotruecolor($image);
    ob_start();
    $ok = imagewebp($image, null, ADMIN_WEBP_QUALITY);
    $webp = (string)ob_get_clean();
    if (!$ok || $webp === '') {
        throw new RuntimeException('Не удалось сохранить WebP');
    }
    return $webp;
}

/** Файл пишется во временный и получает своё имя только целым. */
function admin_write_file(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку: $dir");
    }
    $part = $path . '.part';
    if (file_put_contents($part, $bytes) !== strlen($bytes) || !rename($part, $path)) {
        if (is_file($part)) {
            unlink($part);
        }
        throw new RuntimeException("Не записать файл: $path");
    }
}

function admin_store_product_photo(string $webroot, array $product, string $bytes): string
{
    $webp = admin_photo_webp($bytes, ADMIN_PHOTO_WIDTH);
    $rel = '/images/catalog/' . $product['main_section'] . '/' . $product['slug'] . '-' . $product['uid']
        . '-' . substr(hash('sha256', $webp), 0, 8) . '.webp';
    admin_write_file($webroot . $rel, $webp);
    return $rel;
}

function admin_store_section_photo(string $webroot, string $slug, string $kind, string $bytes): string
{
    $webp = admin_photo_webp($bytes, $kind === 'cover' ? ADMIN_COVER_WIDTH : ADMIN_PHOTO_WIDTH);
    $rel = '/images/catalog/_sections/' . $slug . '-' . $kind . '-' . substr(hash('sha256', $webp), 0, 8) . '.webp';
    admin_write_file($webroot . $rel, $webp);
    return $rel;
}

/**
 * Фото в форме: превью, скрытые поля с путями (их и сохраняет форма),
 * кнопки «←», «→», «Убрать» и выбор файлов. Без JavaScript фото видны и
 * сохраняются как есть; загрузка и перестановка — через assets/photo.js.
 *
 * @param array<string, string> $data data-* для запроса загрузки: uid или section и kind
 */
function admin_photo_widget(array $user, string $name, array $paths, int $limit, array $data): string
{
    $attrs = '';
    foreach ($data as $key => $value) {
        $attrs .= ' data-' . h($key) . '="' . h($value) . '"';
    }
    $items = '';
    foreach ($paths as $path) {
        $items .= '<li><img src="' . h($path) . '" alt=""><input type="hidden" name="' . h($name) . '" value="' . h($path) . '">'
            . '<button type="button" class="btn-small" data-act="left">←</button>'
            . '<button type="button" class="btn-small" data-act="right">→</button>'
            . '<button type="button" class="btn-small" data-act="remove">Убрать</button></li>';
    }
    return '<div class="photos" data-photo-upload data-endpoint="' . ADMIN_BASE . 'photo.php" data-csrf="' . h($user['csrf']) . '"'
        . ' data-name="' . h($name) . '" data-limit="' . $limit . '"' . $attrs . '>'
        . '<ul data-photo-list>' . $items . '</ul>'
        . '<label class="upload">Добавить фото<input type="file" accept="image/*" multiple></label>'
        . '<p class="hint" data-photo-message></p></div>';
}
```

Create `server-pay/admin/lib/pages-photos.php`:

```php
<?php
/**
 * Приём фото (pay/admin/photo.php): один снимок за запрос, ответ — JSON с
 * путём к сохранённому WebP. Путь попадает в форму карточки и сохраняется
 * вместе с ней.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/products.php';

function admin_page_photo(array $req, array $ctx): array
{
    if ($req['method'] !== 'POST') {
        return admin_json(['ok' => false, 'error' => 'Фото принимаются только из формы.'], 405);
    }
    $file = $req['files']['photo'] ?? null;
    $bytes = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($file['tmp_name'] ?? null)
        ? (string)@file_get_contents($file['tmp_name'])
        : '';
    if ($bytes === '') {
        return admin_json(['ok' => false, 'error' => 'Фото не дошло — попробуйте ещё раз.'], 422);
    }
    try {
        $section = admin_str($req['post'], 'section');
        if ($section !== '') {
            $q = $ctx['db']->prepare('SELECT 1 FROM sections WHERE slug = ?');
            $q->execute([$section]);
            if ($q->fetchColumn() === false) {
                throw new CatalogError('Такого раздела нет — обновите страницу.');
            }
            $kind = admin_str($req['post'], 'kind') === 'cover' ? 'cover' : 'tile';
            $path = admin_store_section_photo($ctx['webroot'], $section, $kind, $bytes);
        } else {
            $product = catalog_product_row($ctx['db'], admin_str($req['post'], 'uid'));
            if ($product['status'] === 'deleted') {
                throw new CatalogError('Букет удалён — сначала восстановите его.');
            }
            $path = admin_store_product_photo($ctx['webroot'], $product, $bytes);
        }
        return admin_json(['ok' => true, 'path' => $path]);
    } catch (CatalogError $e) {
        return admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}
```

Create `server-pay/admin/photo.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-photos.php';

admin_run('photo', 'admin_page_photo');
```

In `server-pay/admin/lib/pages-products.php`:

1. After `require_once __DIR__ . '/forms.php';` add:

```php
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/photo-files.php';
```

2. In `admin_product_form` replace

```php
        . '<fieldset class="form"><legend>Фото</legend><p class="hint">До четырёх. Первое — главное.</p>' . admin_photo_list($images) . '</fieldset>'
```

with

```php
        . '<fieldset class="form"><legend>Фото</legend><p class="hint">До четырёх. Первое — главное.</p>'
        . admin_photo_widget($ctx['user'], 'images[]', $images, CATALOG_IMAGES_MAX, ['uid' => $p['uid']]) . '</fieldset>'
```

3. In `admin_product_action` replace

```php
    if ($action === 'delete' && $p['status'] === 'draft') {
```

with

```php
    if ($action === 'restore') {
        // Уборка могла унести фото удалённого букета в корзину — возвращаем их на место.
        foreach (json_decode($p['images'], true) ?: [] as $path) {
            catalog_photo_untrash($ctx['webroot'], $path);
        }
    }
    if ($action === 'delete' && $p['status'] === 'draft') {
```

- [ ] **Step 5: Уменьшение фото в браузере**

Create `server-pay/admin/assets/photo.js`:

```js
/*
 * Фото в карточках букетов и разделов. Браузер сам уменьшает снимок до
 * 2000 px по длинной стороне и отправляет JPEG — по одному снимку за запрос,
 * так он быстро уходит и с мобильного интернета. Остальное делает сервер
 * (pay/admin/photo.php): пересохраняет в WebP и возвращает путь к файлу,
 * а путь попадает в форму и сохраняется вместе с ней. Порядок меняют
 * стрелками; первое фото — главное.
 */
(function () {
  'use strict';
  var MAX_SIDE = 2000;

  function shrink(file) {
    return createImageBitmap(file).then(function (bitmap) {
      var scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          if (blob) { resolve(blob); } else { reject(new Error('')); }
        }, 'image/jpeg', 0.9);
      });
    });
  }

  function button(text, act) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn-small';
    b.textContent = text;
    b.dataset.act = act;
    return b;
  }

  function item(name, path) {
    var li = document.createElement('li');
    var img = document.createElement('img');
    img.src = path;
    img.alt = '';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = path;
    li.append(img, input, button('←', 'left'), button('→', 'right'), button('Убрать', 'remove'));
    return li;
  }

  function send(box, blob) {
    var form = new FormData();
    form.append('csrf', box.dataset.csrf);
    ['uid', 'section', 'kind'].forEach(function (key) {
      if (box.dataset[key]) { form.append(key, box.dataset[key]); }
    });
    form.append('photo', blob, 'photo.jpg');
    return fetch(box.dataset.endpoint, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (r) {
      var type = r.headers.get('Content-Type') || '';
      if (r.redirected || type.indexOf('application/json') !== 0) {
        throw new Error('Сессия закончилась — обновите страницу и войдите заново.');
      }
      return r.json();
    }).then(function (data) {
      if (!data.ok) { throw new Error(data.error); }
      return data.path;
    });
  }

  function setup(box) {
    var list = box.querySelector('[data-photo-list]');
    var input = box.querySelector('input[type=file]');
    var message = box.querySelector('[data-photo-message]');
    var limit = Number(box.dataset.limit);

    function refresh() {
      var full = list.children.length >= limit;
      input.disabled = full;
      box.classList.toggle('photos-full', full);
    }

    list.addEventListener('click', function (event) {
      var b = event.target.closest('button[data-act]');
      if (!b) { return; }
      var li = b.closest('li');
      if (b.dataset.act === 'remove') {
        li.remove();
      } else if (b.dataset.act === 'left' && li.previousElementSibling) {
        list.insertBefore(li, li.previousElementSibling);
      } else if (b.dataset.act === 'right' && li.nextElementSibling) {
        list.insertBefore(li.nextElementSibling, li);
      }
      refresh();
    });

    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files, 0, Math.max(0, limit - list.children.length));
      input.value = '';
      message.textContent = files.length ? 'Загружаю…' : '';
      files.reduce(function (chain, file) {
        return chain.then(function () {
          return shrink(file).then(function (blob) {
            return send(box, blob);
          }).then(function (path) {
            list.appendChild(item(box.dataset.name, path));
            refresh();
          });
        });
      }, Promise.resolve()).then(function () {
        message.textContent = '';
      }, function (error) {
        message.textContent = error && error.message
          ? error.message
          : 'Фото не загрузилось. Попробуйте ещё раз или выберите другое.';
      });
    });

    refresh();
  }

  document.querySelectorAll('[data-photo-upload]').forEach(setup);
})();
```

- [ ] **Step 6: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 7: Коммит**

```bash
git add server-pay/admin/lib/photos.php server-pay/admin/lib/pages-photos.php server-pay/admin/photo.php server-pay/admin/assets/photo.js server-pay/catalog/photo-files.php server-pay/admin/lib/pages-products.php tests/php/admin_fixture.php tests/php/admin_photos_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): фото — уменьшение в браузере, WebP через GD, порядок, корзина

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Разделы и порядок плиток

**Files:**
- Create: `server-pay/admin/lib/pages-sections.php`
- Create: `server-pay/admin/sections.php`, `server-pay/admin/section.php`
- Test: `tests/php/admin_sections_test.php`

**Interfaces:**
- Consumes: `catalog_create_section`, `catalog_update_section`, `catalog_reorder_tiles`, `CATALOG_SECTION_COLUMNS`, `CATALOG_COVERS_MAX`, `CATALOG_JSON` (2А); `admin_photo_widget` (Task 9).
- Produces:
  - `admin_tiles(PDO $db): list<array{id: int, type: string, section: ?string, label: string, section_label: ?string, visible: ?int}>` — в порядке сетки
  - `admin_page_sections(array $req, array $ctx): array` — список, POST `action` = `move` (`tile`, `dir` = `up`|`down`) | `create` (`label`)
  - `admin_page_section(array $req, array $ctx): array` — карточка раздела, POST `action=save`
  - `admin_section_changes(array $post): array` — только изменённые поля (поле => значение для `catalog_update_section`)
  - `admin_section_form(array $ctx, array $row, ?array $post, string $error): string`

Раздел нельзя удалить — только скрыть. Постоянные плитки («Цветы» и др.) только двигаются. Карточка раздела присылает рядом с каждым полем его исходное значение (`orig[поле]`), и в базу уходят только поля, которые сотрудник поменял: правка коллеги в других полях не затирается (замечание из 2А — у разделов нет версии).

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_sections_test.php`:

```php
<?php
/**
 * Разделы: порядок плиток, новый раздел, карточка раздела, правка только
 * изменённых полей.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';

/** Строка раздела из базы. */
function t_section(PDO $db, string $slug): array
{
    $q = $db->prepare('SELECT * FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    return $q->fetch();
}

/** POST карточки раздела: поля формы как есть в базе, плюс изменения. */
function t_section_post(PDO $db, string $slug, array $changes): array
{
    $row = t_section($db, $slug);
    $form = [
        'label' => $row['label'], 'tileImage' => $row['tile_image'], 'visible' => $row['visible'] ? '1' : '0',
        'coverTitle' => $row['cover_title'], 'coverSub' => $row['cover_sub'], 'heading' => $row['heading'],
        'headingSub' => $row['heading_sub'], 'hasNotFound' => $row['has_not_found'] ? '1' : '0',
        'seoTitle' => (string)$row['seo_title'], 'seoDescription' => (string)$row['seo_description'],
        'covers' => json_encode(json_decode($row['covers'], true), CATALOG_JSON),
    ];
    $post = ['action' => 'save', 'slug' => $slug, 'orig' => $form];
    foreach ($form as $field => $value) {
        if ($field === 'covers') {
            $post['covers'] = json_decode($value, true);
        } elseif (($field === 'visible' || $field === 'hasNotFound') && $value === '0') {
            continue; // снятая галочка в форму не приходит
        } else {
            $post[$field] = $value;
        }
    }
    return array_merge($post, $changes);
}

t_case('плитки', function (): void {
    $ctx = t_admin_ctx();
    $page = t_admin_call($ctx, 'sections', 'admin_page_sections')['body'];
    t_true(strpos($page, 'Цветы') < strpos($page, 'Букеты') && strpos($page, 'Букеты') < strpos($page, 'Розы'), 'плитки в порядке сетки');
    t_true(str_contains($page, 'Новинки') && str_contains($page, 'скрыт'), 'скрытый раздел виден и подписан');
    t_true(str_contains($page, 'постоянная плитка'), 'постоянные плитки подписаны');
    $roses = (int)$ctx['db']->query("SELECT id FROM tiles WHERE section = 'roses'")->fetchColumn();
    $r = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'move', 'tile' => (string)$roses, 'dir' => 'up']);
    t_equal($r['headers']['Location'], '/pay/admin/sections.php', 'после перестановки — снова список');
    t_equal(array_column(catalog_export_data($ctx['db'])['tiles'], 'slug'), ['roses', 'bukety'], 'розы поднялись выше букетов');
    $first = (int)$ctx['db']->query("SELECT id FROM tiles WHERE label = 'Цветы'")->fetchColumn();
    t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'move', 'tile' => (string)$first, 'dir' => 'up']);
    t_equal(catalog_export_data($ctx['db'])['tiles'][0]['label'] ?? null, 'Цветы', 'первую плитку выше не поднять — ничего не сломалось');
});

t_case('новый раздел', function (): void {
    $ctx = t_admin_ctx();
    $r = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'create', 'label' => 'Осень']);
    t_equal($r['headers']['Location'], '/pay/admin/section.php?slug=osen&notice=section-created', 'создан — сразу в его карточку');
    t_equal(t_section($ctx['db'], 'osen')['label'], 'Осень', 'раздел в базе');
    $bad = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'create', 'label' => '  ']);
    t_true($bad['status'] === 422 && str_contains($bad['body'], 'Название раздела'), 'без названия — объяснение');
});

t_case('карточка раздела', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $card = t_admin_call($ctx, 'section', 'admin_page_section', query: ['slug' => 'roses'])['body'];
    t_true(str_contains($card, 'value="Розы"') && str_contains($card, 'name="orig[label]" value="Розы"'), 'поля и их исходные значения');
    t_true(str_contains($card, 'data-kind="tile"') && str_contains($card, 'data-kind="cover"') && str_contains($card, 'data-limit="3"'), 'фото плитки и обложки (до трёх)');
    t_true(str_contains($card, 'нельзя удалить'), 'объяснение, почему нет кнопки «Удалить»');

    $r = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['label' => 'Розы поштучно']));
    t_equal($r['headers']['Location'], '/pay/admin/section.php?slug=roses&notice=saved', 'сохранено');
    t_equal(t_section($db, 'roses')['label'], 'Розы поштучно', 'название сменилось');
    t_equal($db->query("SELECT field FROM audit WHERE object_type = 'section'")->fetchAll(PDO::FETCH_COLUMN), ['label'], 'в журнале — только изменённое поле');

    $post = t_section_post($db, 'roses', ['heading' => 'РОЗЫ ИЗ ЭКВАДОРА']);
    // Пока форма была открыта, коллега поменял подзаголовок обложки.
    catalog_update_section($db, 'olga', 'roses', ['coverSub' => 'Прямые поставки'], t_now());
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: $post);
    $row = t_section($db, 'roses');
    t_equal([$row['heading'], $row['cover_sub']], ['РОЗЫ ИЗ ЭКВАДОРА', 'Прямые поставки'], 'своё поле сохранено, правка коллеги не затёрта');

    $post = t_section_post($db, 'roses', []);
    unset($post['visible']);
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: $post);
    t_equal((int)t_section($db, 'roses')['visible'], 0, 'сняли галочку — раздел скрыт');

    $bad = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['label' => '']));
    t_true($bad['status'] === 422 && str_contains($bad['body'], 'Название раздела'), 'ошибка — понятная, форма на месте');
    t_equal(t_admin_call($ctx, 'section', 'admin_page_section', query: ['slug' => 'nope'])['status'], 404, 'нет такого раздела');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/pages-sections.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/pages-sections.php`:

```php
<?php
/**
 * Разделы: порядок плиток в каталоге, новый раздел, карточка раздела.
 * Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/sections.php';

/** Плитки в порядке сетки: у раздела — его название и видимость, у постоянной — подпись. */
function admin_tiles(PDO $db): array
{
    return $db->query('SELECT t.id, t.type, t.section, t.label, s.label AS section_label, s.visible
        FROM tiles t LEFT JOIN sections s ON s.slug = t.section ORDER BY t.position, t.id')->fetchAll();
}

function admin_page_sections(array $req, array $ctx): array
{
    $error = '';
    if ($req['method'] === 'POST') {
        $post = $req['post'];
        $action = admin_str($post, 'action');
        try {
            if ($action === 'create') {
                $slug = catalog_create_section($ctx['db'], $ctx['user']['login'], admin_str($post, 'label'), $ctx['now']);
                return admin_redirect('section.php', ['slug' => $slug, 'notice' => 'section-created']);
            }
            if ($action === 'move') {
                $ids = array_map('intval', array_column(admin_tiles($ctx['db']), 'id'));
                $i = array_search((int)admin_str($post, 'tile'), $ids, true);
                $j = $i === false ? -1 : $i + (admin_str($post, 'dir') === 'up' ? -1 : 1);
                if ($i !== false && isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    catalog_reorder_tiles($ctx['db'], $ctx['user']['login'], $ids, $ctx['now']);
                }
                return admin_redirect('sections.php');
            }
            $error = 'Неизвестное действие — обновите страницу.';
        } catch (CatalogError $e) {
            $error = $e->getMessage();
        }
    }
    $rows = '';
    foreach (admin_tiles($ctx['db']) as $t) {
        $name = $t['type'] === 'section'
            ? '<a href="' . ADMIN_BASE . 'section.php?slug=' . h($t['section']) . '">' . h($t['section_label']) . '</a>'
                . ((int)$t['visible'] === 1 ? '' : ' <span class="badge">скрыт</span>')
            : h($t['label']) . ' <span class="hint">— постоянная плитка</span>';
        $move = '';
        foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow) {
            $move .= '<form method="post" action="' . ADMIN_BASE . 'sections.php">' . admin_csrf_field($ctx['user'])
                . '<input type="hidden" name="action" value="move"><input type="hidden" name="tile" value="' . (int)$t['id'] . '">'
                . '<button class="btn-small" type="submit" name="dir" value="' . $dir . '">' . $arrow . '</button></form>';
        }
        $rows .= '<li><div class="tile-row"><span>' . $name . '</span><span>' . $move . '</span></div></li>';
    }
    $html = '<h1>Разделы и плитки</h1>'
        . '<p class="hint">Порядок — как в сетке каталога на сайте. Скрытого раздела в сетке нет, но его страница работает.</p>'
        . admin_error($error) . '<ol class="tiles">' . $rows . '</ol>'
        . '<h2>Новый раздел</h2>'
        . '<form method="post" action="' . ADMIN_BASE . 'sections.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="create">'
        . '<label>Название<input name="label" maxlength="60" required value="' . h(admin_str($req['post'], 'label')) . '"></label>'
        . '<p class="buttons"><button class="btn" type="submit">Создать раздел</button></p></form>';
    return admin_html(admin_layout('Разделы', $html, $ctx['user'], $ctx['status'], admin_notice($req)), $error !== '' ? 422 : 200);
}

/**
 * Поля карточки, которые сотрудник поменял: сравниваются с исходными
 * значениями из формы (orig). Нет исходного значения — поле считается изменённым.
 */
function admin_section_changes(array $post): array
{
    $orig = is_array($post['orig'] ?? null) ? $post['orig'] : [];
    $now = [
        'label' => admin_str($post, 'label'),
        'tileImage' => admin_str($post, 'tileImage'),
        'visible' => admin_str($post, 'visible') === '1' ? '1' : '0',
        'coverTitle' => admin_str($post, 'coverTitle'),
        'coverSub' => admin_str($post, 'coverSub'),
        'covers' => json_encode(admin_list($post, 'covers'), CATALOG_JSON),
        'heading' => admin_str($post, 'heading'),
        'headingSub' => admin_str($post, 'headingSub'),
        'hasNotFound' => admin_str($post, 'hasNotFound') === '1' ? '1' : '0',
        'seoTitle' => admin_str($post, 'seoTitle'),
        'seoDescription' => admin_str($post, 'seoDescription'),
    ];
    $changes = [];
    foreach ($now as $field => $value) {
        if (!is_string($orig[$field] ?? null) || $orig[$field] !== $value) {
            $changes[$field] = match ($field) {
                'visible', 'hasNotFound' => $value === '1',
                'covers' => json_decode($value, true),
                default => $value,
            };
        }
    }
    return $changes;
}

function admin_page_section(array $req, array $ctx): array
{
    $slug = $req['method'] === 'POST' ? admin_str($req['post'], 'slug') : admin_str($req['query'], 'slug');
    $q = $ctx['db']->prepare('SELECT * FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    $row = $q->fetch();
    if ($row === false) {
        return admin_not_found($ctx, 'Такого раздела нет', 'sections.php');
    }
    if ($req['method'] === 'POST') {
        try {
            $changes = admin_section_changes($req['post']);
            if ($changes !== []) {
                catalog_update_section($ctx['db'], $ctx['user']['login'], $slug, $changes, $ctx['now']);
            }
            return admin_redirect('section.php', ['slug' => $slug, 'notice' => 'saved']);
        } catch (CatalogError $e) {
            return admin_html(admin_layout($row['label'], admin_section_form($ctx, $row, $req['post'], $e->getMessage()),
                $ctx['user'], $ctx['status']), 422);
        }
    }
    return admin_html(admin_layout($row['label'], admin_section_form($ctx, $row, null, ''), $ctx['user'], $ctx['status'], admin_notice($req)));
}

/** Карточка раздела. $post — прислано формой (показать снова после ошибки), null — из базы. */
function admin_section_form(array $ctx, array $row, ?array $post, string $error): string
{
    $orig = [
        'label' => $row['label'], 'tileImage' => $row['tile_image'], 'visible' => $row['visible'] ? '1' : '0',
        'coverTitle' => $row['cover_title'], 'coverSub' => $row['cover_sub'],
        'covers' => json_encode(json_decode($row['covers'], true) ?: [], CATALOG_JSON),
        'heading' => $row['heading'], 'headingSub' => $row['heading_sub'], 'hasNotFound' => $row['has_not_found'] ? '1' : '0',
        'seoTitle' => (string)$row['seo_title'], 'seoDescription' => (string)$row['seo_description'],
    ];
    if ($post !== null && is_array($post['orig'] ?? null)) {
        $orig = array_map(fn ($v): string => is_string($v) ? $v : '', $post['orig'] + $orig);
    }
    $value = fn (string $field): string => $post !== null ? admin_str($post, $field) : $orig[$field];
    $checked = fn (string $field): string => ($post !== null ? admin_str($post, $field) === '1' : $orig[$field] === '1') ? ' checked' : '';
    $covers = $post !== null ? admin_list($post, 'covers') : (json_decode($row['covers'], true) ?: []);
    $tile = $value('tileImage');
    $hidden = '';
    foreach ($orig as $field => $original) {
        $hidden .= '<input type="hidden" name="orig[' . h($field) . ']" value="' . h($original) . '">';
    }
    $text = fn (string $label, string $field, int $max): string =>
        '<label>' . h($label) . '<input name="' . h($field) . '" maxlength="' . $max . '" value="' . h($value($field)) . '"></label>';
    return '<h1>' . h($row['label']) . '</h1>'
        . '<p class="hint">Адрес раздела: <span class="address">https://pionperm.ru/' . h($row['slug']) . '/</span></p>'
        . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'section.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="' . h($row['slug']) . '">' . $hidden
        . '<label>Название<input name="label" maxlength="60" required value="' . h($value('label')) . '"></label>'
        . '<p class="hint">Оно же — подпись плитки, хлебные крошки и заголовок для поиска.</p>'
        . '<label class="check"><input type="checkbox" name="visible" value="1"' . $checked('visible') . '> Показывать в каталоге</label>'
        . '<fieldset class="form"><legend>Фото плитки</legend>'
        . admin_photo_widget($ctx['user'], 'tileImage', $tile !== '' ? [$tile] : [], 1, ['section' => $row['slug'], 'kind' => 'tile']) . '</fieldset>'
        . '<details><summary>Дополнительно</summary>'
        . $text('Заголовок обложки', 'coverTitle', 300) . $text('Подзаголовок обложки', 'coverSub', 300)
        . '<fieldset class="form"><legend>Фото обложки</legend><p class="hint">До трёх.</p>'
        . admin_photo_widget($ctx['user'], 'covers[]', $covers, CATALOG_COVERS_MAX, ['section' => $row['slug'], 'kind' => 'cover']) . '</fieldset>'
        . $text('Заголовок над сеткой', 'heading', 300) . $text('Подзаголовок над сеткой', 'headingSub', 300)
        . '<label class="check"><input type="checkbox" name="hasNotFound" value="1"' . $checked('hasNotFound') . '> Блок «Не нашли нужное?»</label>'
        . $text('SEO-заголовок', 'seoTitle', 300) . $text('SEO-описание', 'seoDescription', 300)
        . '<p class="hint">Пустые SEO-поля — сайт подставит заголовок и описание по шаблону.</p></details>'
        . '<p class="buttons"><button class="btn" type="submit">Сохранить</button></p></form>'
        . '<p class="hint">Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.</p>'
        . '<p><a href="' . ADMIN_BASE . 'sections.php">← Все разделы</a></p>';
}
```

Create `server-pay/admin/sections.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-sections.php';

admin_run('sections', 'admin_page_sections');
```

Create `server-pay/admin/section.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-sections.php';

admin_run('section', 'admin_page_section');
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/pages-sections.php server-pay/admin/sections.php server-pay/admin/section.php tests/php/admin_sections_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): разделы — порядок плиток, новый раздел, карточка раздела

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Журнал и история в карточке

**Files:**
- Create: `server-pay/admin/lib/pages-log.php`
- Create: `server-pay/admin/log.php`
- Modify: `server-pay/admin/lib/pages-products.php` (`admin_product_extras` — история под действиями)
- Test: `tests/php/admin_log_test.php`

**Interfaces:**
- Consumes: таблица `audit` (2А); `admin_change_on_site` и `$ctx['deployed']`, `$ctx['inSync']` (Task 5); `admin_sections` (Task 6).
- Produces:
  - `const ADMIN_FIELD_LABELS` (поле журнала => подпись)
  - `admin_audit_value(string $field, ?string $value, array $sectionLabels): string`
  - `admin_audit_rows(PDO $db, ?string $productUid = null, int $limit = 500): list<array>` (строки `audit` + `name` сотрудника)
  - `admin_audit_list(PDO $db, array $rows, ?array $deployed, bool $inSync, bool $withObject): string` — `$deployed` и `$inSync` берутся из `$ctx` (Task 5), `state.json` второй раз не читается
  - `admin_page_log(array $req, array $ctx): array`

Спецификация: «Журнал. Кто, когда и что поменял: было → стало. Последние 500 изменений.» «В журнале и в карточке букета у каждого изменения своя отметка: «на сайте» или «ждёт выкладки».»

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/admin_log_test.php`:

```php
<?php
/**
 * Журнал: кто, когда, что — было → стало, последние 500, отметки «на сайте /
 * ждёт выкладки»; история в карточке букета.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-log.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

t_case('журнал', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4800, 'sections' => ['bukety', 'roses']]), t_now('+1 minute'));
    catalog_publish($db, 'anna', $uid, 2, t_now('+2 minutes'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, "Цена: 4\u{00A0}400\u{00A0}₽ → 4\u{00A0}800\u{00A0}₽"), 'цена — рублями');
    t_true(str_contains($page, 'Статус: Черновик → В продаже'), 'статус — словами');
    t_true(str_contains($page, 'Разделы: Букеты → Букеты, Розы'), 'разделы — названиями');
    t_true(str_contains($page, 'Анна') && str_contains($page, '5 октября, 14:02'), 'кто и когда');
    t_true(str_contains($page, 'href="/pay/admin/product.php?uid=' . $uid . '"'), 'ссылка на букет');
    t_true(strpos($page, 'Статус:') < strpos($page, 'Цена:'), 'свежие изменения — сверху');
});

t_case('экранирование и предел', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    catalog_create_product($db, 'anna', t_fields(['title' => '<script>alert(1)</script>']), t_now());
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, '<script>alert(1)</script>'), 'значения экранированы');
    $add = $db->prepare("INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value) VALUES (?, 'anna', 'product', '1', 'price', '1', '2')");
    for ($i = 0; $i < 510; $i++) {
        $add->execute(['2026-10-05T15:00:00+05:00']);
    }
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_equal(substr_count($page, '<li class="log-item">'), 500, 'последние 500 изменений');
});

t_case('отметки и история в карточке', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now('+1 minute'));
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['price' => 4500]), t_now('+10 minutes'));
    $other = catalog_create_product($db, 'anna', t_fields(['title' => 'Другой букет']), t_now('+11 minutes'));
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'на сайте') && str_contains($page, 'ждёт выкладки'), 'у изменений — отметки');
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    t_true(str_contains($card, '<h2>История</h2>') && str_contains($card, 'Цена:'), 'в карточке — история этого букета');
    t_true(!str_contains($card, 'Другой букет'), 'и только его');
    unlink($ctx['deployHome'] . '/state.json');
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, 'на сайте') && !str_contains($page, 'ждёт выкладки'), 'выложенное неизвестно — без отметок');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/admin/lib/pages-log.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/admin/lib/pages-log.php`:

```php
<?php
/**
 * Журнал: кто, когда и что поменял — было → стало. Последние 500
 * изменений, у каждого — отметка «на сайте» или «ждёт выкладки».
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/queries.php';

const ADMIN_FIELD_LABELS = [
    'title' => 'Название', 'description' => 'Состав', 'price' => 'Цена', 'images' => 'Фото', 'sections' => 'Разделы',
    'mainSection' => 'Главный раздел', 'slug' => 'Адрес', 'status' => 'Статус', 'created' => 'Создан', 'deleted' => 'Удалён',
    'label' => 'Название раздела', 'tileImage' => 'Фото плитки', 'visible' => 'Показывать в каталоге',
    'coverTitle' => 'Заголовок обложки', 'coverSub' => 'Подзаголовок обложки', 'covers' => 'Фото обложки',
    'heading' => 'Заголовок над сеткой', 'headingSub' => 'Подзаголовок над сеткой', 'hasNotFound' => 'Блок «Не нашли нужное?»',
    'seoTitle' => 'SEO-заголовок', 'seoDescription' => 'SEO-описание', 'order' => 'Порядок плиток',
    'password' => 'Пароль', 'imported' => 'Загрузка каталога',
];

/** Значение из журнала — по-человечески: рубли, статусы и разделы словами, фото — числом. */
function admin_audit_value(string $field, ?string $value, array $sectionLabels): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return match ($field) {
        'price' => ctype_digit($value) ? admin_rub((int)$value) : $value,
        'status' => ADMIN_STATUS_LABELS[$value] ?? $value,
        'images', 'covers' => count(json_decode($value, true) ?: []) . ' фото',
        'sections' => implode(', ', array_map(fn (string $s): string => $sectionLabels[$s] ?? $s, explode(', ', $value))),
        'mainSection' => $sectionLabels[$value] ?? $value,
        'visible', 'hasNotFound' => $value === '1' ? 'да' : 'нет',
        default => mb_strimwidth($value, 0, 160, '…'),
    };
}

function admin_audit_rows(PDO $db, ?string $productUid = null, int $limit = 500): array
{
    $where = $productUid !== null ? "WHERE a.object_type = 'product' AND a.object_id = ?" : '';
    $q = $db->prepare("SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.login = a.login $where ORDER BY a.id DESC LIMIT " . $limit);
    $q->execute($productUid !== null ? [$productUid] : []);
    return $q->fetchAll();
}

function admin_audit_list(PDO $db, array $rows, ?array $deployed, bool $inSync, bool $withObject): string
{
    $sectionLabels = array_column(admin_sections($db), 'label', 'slug');
    $titles = $db->query('SELECT uid, title FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
    $items = '';
    foreach ($rows as $r) {
        $object = '';
        if ($withObject) {
            $object = match ($r['object_type']) {
                'product' => '<a href="' . ADMIN_BASE . 'product.php?uid=' . h($r['object_id']) . '">' . h($titles[$r['object_id']] ?? 'букет удалён') . '</a> · ',
                'section' => '<a href="' . ADMIN_BASE . 'section.php?slug=' . h($r['object_id']) . '">' . h($sectionLabels[$r['object_id']] ?? $r['object_id']) . '</a> · ',
                'tiles' => 'Плитки каталога · ',
                default => '',
            };
        }
        $field = ADMIN_FIELD_LABELS[$r['field']] ?? $r['field'];
        $change = in_array($r['field'], ['created', 'deleted', 'imported', 'password'], true)
            ? h($field)
            : h($field) . ': ' . h(admin_audit_value($r['field'], $r['old_value'], $sectionLabels))
                . ' → ' . h(admin_audit_value($r['field'], $r['new_value'], $sectionLabels));
        $mark = match (admin_change_on_site($r['at'], $deployed, $inSync)) {
            true => ' · <span class="on-site">на сайте</span>',
            false => ' · <span class="waiting">ждёт выкладки</span>',
            null => '',
        };
        $items .= '<li class="log-item"><div class="log-head">' . h(admin_date($r['at'])) . ' · ' . h($r['name'] ?? $r['login']) . $mark . '</div>'
            . '<div>' . $object . $change . '</div></li>';
    }
    return $items === '' ? '<p class="hint">Изменений пока нет.</p>' : '<ul class="log">' . $items . '</ul>';
}

function admin_page_log(array $req, array $ctx): array
{
    $html = '<h1>Журнал</h1><p class="hint">Последние 500 изменений: кто, когда и что поменял.</p>'
        . admin_audit_list($ctx['db'], admin_audit_rows($ctx['db']), $ctx['deployed'], $ctx['inSync'], true);
    return admin_html(admin_layout('Журнал', $html, $ctx['user'], $ctx['status']));
}
```

Create `server-pay/admin/log.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/lib/pages-log.php';

admin_run('log', 'admin_page_log');
```

In `server-pay/admin/lib/pages-products.php`:

1. After `require_once __DIR__ . '/../../catalog/photo-files.php';` add:

```php
require_once __DIR__ . '/pages-log.php';
```

2. In `admin_product_extras` replace the final

```php
    return '<h2>Действия</h2>'
        . ($p['status'] !== 'deleted' ? '<p class="hint">Несохранённые правки в карточке выше при этом не сохранятся.</p>' : '')
        . '<div class="buttons">' . $forms . '</div>';
```

with

```php
    return '<h2>Действия</h2>'
        . ($p['status'] !== 'deleted' ? '<p class="hint">Несохранённые правки в карточке выше при этом не сохранятся.</p>' : '')
        . '<div class="buttons">' . $forms . '</div>'
        . '<h2>История</h2>'
        . admin_audit_list($ctx['db'], admin_audit_rows($ctx['db'], $p['uid'], 10), $ctx['deployed'], $ctx['inSync'], false);
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/admin/lib/pages-log.php server-pay/admin/log.php server-pay/admin/lib/pages-products.php tests/php/admin_log_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): журнал изменений и история в карточке букета

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Обслуживание — копия базы и уборка фото

**Files:**
- Create: `server-pay/catalog/maintenance.php`
- Create: `server-pay/catalog/maintenance-cli.php`
- Test: `tests/php/catalog_maintenance_test.php`

**Interfaces:**
- Consumes: `catalog_backup` (Task 1), `catalog_photo_trash`, `CATALOG_TRASH_DIR` (Task 9).
- Produces:
  - `const CATALOG_PHOTO_GRACE = 86400`, `const CATALOG_TRASH_DAYS = 90`
  - `catalog_photos_referenced(PDO $db): array<string, true>` — фото букетов (кроме удалённых) и разделов
  - `catalog_photo_owner_known(PDO $db, string $dir, string $name): bool`
  - `catalog_photos_sweep(PDO $db, string $webroot, string $candidatesFile, DateTimeImmutable $now): array{candidates: int, moved: int, purged: int}`
  - `php maintenance-cli.php` — копия базы и уборка; для расписания (этап 3)

Спецификация: «Фото удалённых букетов переносятся в images/catalog/_deleted/ и стираются через 90 дней. Фото, заменённые новыми, — так же.» Уборка ждёт сутки после того, как на фото перестали ссылаться (список кандидатов `pion-catalog/photo-candidates.json`): до выкладки старая страница на сайте ещё показывает его. Файлы, хозяина которых база не знает (uid из имени нет в базе), не трогаются никогда — так переживут уборку и фото каталога до его переноса в базу.

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/catalog_maintenance_test.php`:

```php
<?php
/**
 * Уборка фото: кандидаты, корзина через сутки, корзина стирается через 90
 * дней, чужие файлы не трогаются; maintenance-cli.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';
require_once __DIR__ . '/../../server-pay/catalog/maintenance.php';

/** Файл фото во временном корне сайта. */
function t_photo_file(string $webroot, string $rel): void
{
    if (!is_dir(dirname($webroot . $rel))) {
        mkdir(dirname($webroot . $rel), 0777, true);
    }
    file_put_contents($webroot . $rel, 'webp');
}

t_case('уборка фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $kept = "/images/catalog/bukety/buket-nezhnost-$uid-aaaaaaaa.webp";
    $dropped = "/images/catalog/bukety/buket-nezhnost-$uid-bbbbbbbb.webp";
    $foreign = '/images/catalog/bukety/buket-barhatnye-grani-553645466981.webp';
    foreach ([$kept, $dropped, $foreign] as $rel) {
        t_photo_file($webroot, $rel);
    }
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$kept]]), t_now());

    $first = catalog_photos_sweep($db, $webroot, $candidates, t_now());
    t_equal($first, ['candidates' => 1, 'moved' => 0, 'purged' => 0], 'фото без ссылок — пока только кандидат');
    t_true(is_file($webroot . $dropped), 'сразу не уносится: старая страница на сайте может его ещё показывать');

    $second = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal($second['moved'], 1, 'через сутки — в корзину');
    t_true(!is_file($webroot . $dropped) && is_file($webroot . '/images/catalog/_deleted/bukety/' . basename($dropped)), 'лежит в _deleted');
    t_true(is_file($webroot . $kept), 'фото со ссылкой не тронуто');
    t_true(is_file($webroot . $foreign), 'фото, хозяина которого база не знает, не тронуто');
});

t_case('снова нужное фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-cccccccc.webp";
    t_photo_file($webroot, $rel);
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now('+1 hour'));
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal([$r['moved'], $r['candidates'], is_file($webroot . $rel)], [0, 0, true], 'на фото снова ссылаются — остаётся, из кандидатов выбывает');
});

t_case('фото удалённого букета и разделов', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-dddddddd.webp";
    t_photo_file($webroot, $rel);
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now());
    catalog_publish($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    $tileOld = '/images/catalog/_sections/roses-tile-eeeeeeee.webp';
    $tileNow = '/images/catalog/_sections/roses-tile-ffffffff.webp';
    $alien = '/images/catalog/_sections/nope-tile-12345678.webp';
    foreach ([$tileOld, $tileNow, $alien] as $file) {
        t_photo_file($webroot, $file);
    }
    catalog_update_section($db, 'anna', 'roses', ['tileImage' => $tileNow], t_now());
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_true(!is_file($webroot . $rel), 'фото удалённого букета — в корзине');
    t_true(!is_file($webroot . $tileOld) && is_file($webroot . $tileNow), 'заменённая плитка раздела — в корзине, нынешняя — на месте');
    t_true(is_file($webroot . $alien), 'плитка неизвестного раздела не тронута');
});

t_case('корзина стирается через 90 дней', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $old = '/images/catalog/_deleted/bukety/old.webp';
    $fresh = '/images/catalog/_deleted/bukety/fresh.webp';
    t_photo_file($webroot, $old);
    t_photo_file($webroot, $fresh);
    touch($webroot . $old, t_now('-91 days')->getTimestamp());
    touch($webroot . $fresh, t_now('-89 days')->getTimestamp());
    $r = catalog_photos_sweep($db, $webroot, t_tmpdir() . '/c.json', t_now());
    t_equal([$r['purged'], is_file($webroot . $old), is_file($webroot . $fresh)], [1, false, true], 'старше 90 дней — стёрто, остальное ждёт');
});

t_case('maintenance-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    t_equal([$code, str_contains($out, 'ещё нет')], [0, true], 'базы нет — спокойно выходит');
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    putenv('PION_WEBROOT=' . t_tmpdir());
    try {
        [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    } finally {
        putenv('PION_WEBROOT');
    }
    t_true($code === 0 && str_contains($out, 'Копия:') && str_contains($out, 'Фото:'), 'копия и уборка сделаны');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия на месте');
});
```

- [ ] **Step 2: Убедиться, что падает**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '.../server-pay/catalog/maintenance.php'`.

- [ ] **Step 3: Реализация**

Create `server-pay/catalog/maintenance.php`:

```php
<?php
/**
 * Ежедневное обслуживание каталога: копия базы и уборка фото.
 *
 * Фото, на которые больше не ссылается ни один букет (кроме удалённых) и ни
 * один раздел, — заменили, убрали из карточки, букет удалён, — переезжают в
 * корзину images/catalog/_deleted/. Но не сразу: сначала фото становится
 * кандидатом, и только если через сутки ссылок на него так и нет, уходит —
 * до выкладки старая страница на сайте ещё показывает его. Корзина стирает
 * фото через 90 дней. Файлы, хозяина которых база не знает (uid или раздела
 * из имени в ней нет), не трогаются никогда.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/photo-files.php';

/** Сколько фото должно пробыть без ссылок, прежде чем уйти в корзину. */
const CATALOG_PHOTO_GRACE = 86400;
const CATALOG_TRASH_DAYS = 90;

function catalog_photos_referenced(PDO $db): array
{
    $paths = [];
    foreach ($db->query("SELECT images FROM products WHERE status <> 'deleted'") as $row) {
        foreach (json_decode($row['images'], true) ?: [] as $path) {
            $paths[$path] = true;
        }
    }
    foreach ($db->query('SELECT tile_image, covers FROM sections') as $row) {
        if ($row['tile_image'] !== '') {
            $paths[$row['tile_image']] = true;
        }
        foreach (json_decode($row['covers'], true) ?: [] as $path) {
            $paths[$path] = true;
        }
    }
    return $paths;
}

/** Знает ли база хозяина файла: раздел — для фото из _sections, букет по uid из имени — для остальных. */
function catalog_photo_owner_known(PDO $db, string $dir, string $name): bool
{
    if ($dir === '_sections') {
        if (!preg_match('/^(.+)-(?:tile|cover)-[0-9a-f]{8}\.webp$/', $name, $m)) {
            return false;
        }
        $q = $db->prepare('SELECT 1 FROM sections WHERE slug = ?');
        $q->execute([$m[1]]);
        return $q->fetchColumn() !== false;
    }
    if (!preg_match('/-(\d{12})(?:-\d+|-[0-9a-f]{8})?\.webp$/', $name, $m)) {
        return false;
    }
    $q = $db->prepare('SELECT 1 FROM products WHERE uid = ?');
    $q->execute([$m[1]]);
    return $q->fetchColumn() !== false;
}

/** @return array{candidates: int, moved: int, purged: int} */
function catalog_photos_sweep(PDO $db, string $webroot, string $candidatesFile, DateTimeImmutable $now): array
{
    $time = $now->getTimestamp();
    $referenced = catalog_photos_referenced($db);
    $previous = json_decode((string)@file_get_contents($candidatesFile), true);
    $previous = is_array($previous) ? $previous : [];
    $candidates = [];
    $moved = 0;
    foreach (glob($webroot . '/images/catalog/*', GLOB_ONLYDIR) ?: [] as $dirPath) {
        $dir = basename($dirPath);
        if ($dir === '_deleted') {
            continue;
        }
        foreach (glob($dirPath . '/*.webp') ?: [] as $file) {
            $name = basename($file);
            $rel = '/images/catalog/' . $dir . '/' . $name;
            if (isset($referenced[$rel]) || !catalog_photo_owner_known($db, $dir, $name)) {
                continue;
            }
            $since = is_int($previous[$rel] ?? null) ? $previous[$rel] : $time;
            if ($time - $since >= CATALOG_PHOTO_GRACE && catalog_photo_trash($webroot, $rel, $now)) {
                $moved++;
                continue;
            }
            $candidates[$rel] = $since;
        }
    }
    $old = [];
    $trash = $webroot . CATALOG_TRASH_DIR;
    if (is_dir($trash)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getMTime() < $time - CATALOG_TRASH_DAYS * 86400) {
                $old[] = $f->getPathname();
            }
        }
    }
    $purged = 0;
    foreach ($old as $path) {
        if (unlink($path)) {
            $purged++;
        }
    }
    file_put_contents($candidatesFile, json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return ['candidates' => count($candidates), 'moved' => $moved, 'purged' => $purged];
}
```

Create `server-pay/catalog/maintenance-cli.php`:

```php
<?php
/**
 * Ежедневное обслуживание каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/maintenance-cli.php
 *
 * Делает копию базы и убирает фото, на которые больше никто не ссылается.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/maintenance.php';

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — обслуживать нечего.', PHP_EOL;
    exit(0);
}
// pay/catalog → pay → корень сайта
$webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
$now = new DateTimeImmutable();
try {
    echo 'Копия: ', catalog_backup($file, catalog_home() . '/backups', $now), PHP_EOL;
    $r = catalog_photos_sweep(catalog_db_open($file), $webroot, catalog_home() . '/photo-candidates.json', $now);
    printf("Фото: ждут уборки %d, убрано в корзину %d, стёрто из корзины %d.\n", $r['candidates'], $r['moved'], $r['purged']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Обслуживание не удалось: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

- [ ] **Step 4: Убедиться, что проходит**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 5: Коммит**

```bash
git add server-pay/catalog/maintenance.php server-pay/catalog/maintenance-cli.php tests/php/catalog_maintenance_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): ежедневное обслуживание — копия базы и уборка фото

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Сквозная проверка по HTTP и инструкция

**Files:**
- Create: `tests/php/admin_e2e_test.php`
- Modify: `docs/catalog.md`

**Interfaces:**
- Consumes: всё из Tasks 1–12; `t_jpeg` (Task 9).
- Produces: проверка админки через встроенный веб-сервер PHP; инструкция для разработчика и салона.

Проверки страниц вызывают функции напрямую. Здесь — то, чего они не видят: настоящие заголовки и куки (`Set-Cookie`, `Location`), разбор форм и загрузки файла самим PHP, точки входа `*.php`, выгрузка после правки в админке.

- [ ] **Step 1: Проверка**

Create `tests/php/admin_e2e_test.php`:

```php
<?php
/**
 * Админка целиком, как в браузере: встроенный сервер PHP раздаёт копию /pay/,
 * запросы идут по HTTP. Проверяется то, чего не видно в проверках страниц:
 * настоящие заголовки и куки, точки входа, загрузка фото, выгрузка после правки.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';

/** Копия server-pay как /pay/ во временном корне сайта. */
function t_e2e_site(): string
{
    $root = t_tmpdir();
    $src = str_replace('\\', '/', dirname(__DIR__, 2) . '/server-pay');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $root . '/pay/' . substr(str_replace('\\', '/', $item->getPathname()), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
    return $root;
}

/** Встроенный сервер PHP на свободном порту; [процесс, порт]. */
function t_e2e_start(string $webroot, array $env): array
{
    $log = t_tmpdir() . '/server.log';
    for ($try = 0; $try < 5; $try++) {
        $port = random_int(20000, 45000);
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $webroot],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $webroot,
            $env + getenv(),
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return [$proc, $port];
            }
            usleep(100000);
        }
        proc_terminate($proc);
        proc_close($proc);
    }
    throw new RuntimeException('встроенный сервер PHP не запустился');
}

/** @return array{status: int, headers: array<string, list<string>>, body: string} */
function t_http(int $port, string $method, string $path, array $fields = [], string $cookie = '', ?string $jpeg = null): array
{
    $headers = [];
    $content = '';
    if ($jpeg !== null) {
        $boundary = 'pion' . bin2hex(random_bytes(8));
        foreach ($fields as $name => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"photo.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n$jpeg\r\n--$boundary--\r\n";
        $headers[] = "Content-Type: multipart/form-data; boundary=$boundary";
    } elseif ($fields !== []) {
        $content = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m)) {
            $status = (int)$m[1];
            $out = [];
            continue;
        }
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $out[strtolower(trim($name))][] = trim($value);
    }
    return ['status' => $status, 'headers' => $out, 'body' => $body];
}

function t_csrf(string $html): string
{
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m);
    return $m[1] ?? '';
}

t_case('админка по HTTP', function (): void {
    $webroot = t_e2e_site();
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $password = catalog_user_add($db, 'olga', 'Ольга', t_now());
    $db = null;
    [$proc, $port] = t_e2e_start($webroot, ['PION_CATALOG_HOME' => $home, 'PION_WEBROOT' => $webroot, 'PION_DEPLOY_HOME' => t_tmpdir()]);
    try {
        $r = t_http($port, 'GET', '/pay/admin/');
        t_equal([$r['status'], $r['headers']['location'][0] ?? ''], [303, '/pay/admin/login.php'], 'без входа — на страницу входа');

        $r = t_http($port, 'GET', '/pay/admin/login.php');
        t_equal([$r['status'], $r['headers']['x-robots-tag'][0] ?? '', $r['headers']['cache-control'][0] ?? ''],
            [200, 'noindex, nofollow', 'no-store'], 'страница входа закрыта от поиска и кэша');

        $r = t_http($port, 'POST', '/pay/admin/login.php', ['login' => 'olga', 'password' => 'не тот']);
        t_true($r['status'] === 200 && str_contains($r['body'], 'Неверный логин или пароль'), 'неверный пароль — отказ');

        $r = t_http($port, 'POST', '/pay/admin/login.php', ['login' => 'Olga', 'password' => $password]);
        $setCookie = $r['headers']['set-cookie'][0] ?? '';
        t_true($r['status'] === 303 && str_contains($setCookie, 'HttpOnly') && str_contains($setCookie, 'Secure') && str_contains($setCookie, 'SameSite=Strict'),
            'вход — кука сессии с защитными флагами');
        $cookie = explode(';', $setCookie)[0];

        $r = t_http($port, 'GET', '/pay/admin/', [], $cookie);
        t_equal([$r['status'], $r['headers']['location'][0] ?? ''], [303, '/pay/admin/password.php'], 'первый вход — сначала смена пароля');

        $csrf = t_csrf(t_http($port, 'GET', '/pay/admin/password.php', [], $cookie)['body']);
        $r = t_http($port, 'POST', '/pay/admin/password.php', ['csrf' => $csrf, 'current' => $password, 'new' => 'новый-пароль-ольги', 'repeat' => 'новый-пароль-ольги'], $cookie);
        t_equal($r['status'], 303, 'пароль сменён');

        $r = t_http($port, 'POST', '/pay/admin/product.php', ['csrf' => $csrf, 'action' => 'create', 'title' => 'Букет «Сквозной»', 'price' => '4 400', 'sections' => ['bukety']], $cookie);
        t_equal($r['status'], 303, 'черновик создан');
        parse_str((string)parse_url($r['headers']['location'][0] ?? '', PHP_URL_QUERY), $q);
        $uid = (string)($q['uid'] ?? '');

        $r = t_http($port, 'POST', '/pay/admin/photo.php', ['csrf' => $csrf, 'uid' => $uid], $cookie, t_jpeg(1200, 1600));
        $photo = json_decode($r['body'], true);
        t_true(($photo['ok'] ?? false) === true && is_file($webroot . $photo['path']), 'фото загружено и пересохранено в WebP');

        $r = t_http($port, 'POST', '/pay/admin/product.php', [
            'csrf' => $csrf, 'action' => 'publish', 'uid' => $uid, 'version' => '1', 'title' => 'Букет «Сквозной»', 'price' => '4400',
            'description' => '', 'sections' => ['bukety'], 'mainSection' => 'bukety', 'images' => [$photo['path'] ?? ''],
        ], $cookie);
        t_equal($r['status'], 303, 'опубликован');

        $export = json_decode(t_http($port, 'GET', '/pay/catalog-export.php')['body'], true);
        t_equal(array_column($export['products'] ?? [], 'title'), ['Букет «Сквозной»'], 'новый букет — в выгрузке для сайта');
        t_equal($export['products'][0]['images'] ?? [], [$photo['path'] ?? ''], 'с фото');

        $r = t_http($port, 'POST', '/pay/admin/product.php', ['action' => 'create', 'title' => 'Без токена', 'price' => '100', 'sections' => ['bukety']], $cookie);
        t_equal($r['status'], 400, 'POST без CSRF-токена отклонён');

        t_http($port, 'POST', '/pay/admin/logout.php', ['csrf' => $csrf], $cookie);
        t_equal(t_http($port, 'GET', '/pay/admin/', [], $cookie)['status'], 303, 'после выхода кука больше не пускает');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
```

- [ ] **Step 2: Прогнать**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`. Если падает — это находка в коде Tasks 3–12 (заголовки, куки, точки входа): чинить код, а не проверку.

- [ ] **Step 3: Инструкция**

In `docs/catalog.md`:

1. Replace the first paragraph (from `База каталога, правила данных и выгрузка — этап 2А админки` to `(переключение — этап 3).`) with:

```markdown
База каталога, правила данных и выгрузка — этап 2А
(`docs/superpowers/plans/2026-10-07-catalog-server.md`); экраны админки —
этап 2Б (`docs/superpowers/plans/2026-10-07-catalog-admin-screens.md`). Сайт
пока собирается из файлов репозитория (переключение — этап 3), поэтому
правки в админке на нём ещё не появляются — строка статуса вверху каждого
экрана так и говорит.

На сервере SQLite 3.26.0 (AlmaLinux 8): SQL новее 3.26 в коде `/pay/` не
пропускает проверка `tests/php/catalog_sqlite326_test.php`, копии базы
делаются через `SQLite3::backup()`.
```

2. Replace the whole section `## Расписание` (from that heading to the end of the file) with:

````markdown
## Админка

`https://pionperm.ru/pay/admin/` — с телефона и с компьютера. Ссылок на неё
на сайте нет, поисковикам она закрыта. Учётные записи заводит разработчик
(`php user-cli.php add …`); при первом входе админка просит сменить
выданный пароль. Пять неверных попыток за 10 минут закрывают вход на 10
минут. После 12 часов без действий — снова вход.

Экраны: «Букеты» (список, поиск, фильтры; «Добавить букет» — короткая форма,
потом карточка с фото), «Разделы» (порядок плиток, новый раздел, карточка
раздела), «Журнал» (последние 500 изменений), «Пароль».

Фото: браузер уменьшает снимок и отправляет его сразу; сервер пересохраняет
в WebP. Убранные и заменённые фото, а также фото удалённых букетов через
сутки уходят в `images/catalog/_deleted/` и стираются через 90 дней;
восстановление букета возвращает его фото на место.

## Расписание

Обслуживание раз в сутки — задание добавить на этапе 3, когда в базе
появится настоящий каталог (вместо отдельной копии через `backup-cli.php`):

```
15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/maintenance-cli.php
```

`maintenance-cli.php` делает копию базы и убирает фото без ссылок.
````

- [ ] **Step 4: Все проверки**

Run: `npm test && /c/php82/php.exe tests/php/run.php && find server-pay -name '*.php' -print0 | xargs -0 -n1 /c/php82/php.exe -l | grep -v '^No syntax errors'`
Expected: vitest — все PASS; PHP — `не прошло: 0`; `php -l` — пустой вывод.

- [ ] **Step 5: Коммит и отправка ветки**

```bash
git add tests/php/admin_e2e_test.php docs/catalog.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "test(admin): сквозная проверка по HTTP; docs: как пользоваться админкой

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin catalog-admin
```

- [ ] **Step 6: CI на ветке зелёный**

Run (через несколько минут после отправки; `gh` на машине нет, репозиторий публичный):

```bash
curl -s "https://api.github.com/repos/kidw3st/pion/actions/runs?branch=catalog-admin&per_page=3" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{for(const r of JSON.parse(s).workflow_runs||[])console.log(r.head_sha.slice(0,7),r.name,r.status,r.conclusion)})"
```

Expected: запуск «Проверка сборки» последнего коммита — `completed success`.

---

## После слияния в master

Автовыкладка увезёт `/pay/admin/` за 5–20 минут. Базы на сервере ещё нет —
админка должна честно ответить 503. Проверить снаружи, по одному запросу на
адрес (хостинг банит IP за частые обращения):

```bash
curl -s -o /dev/null -D - https://pionperm.ru/pay/admin/login.php | grep -iE '^HTTP|x-robots-tag|cache-control'
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/pay/admin/lib/auth.php
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/pay/admin/assets/admin.css
```

Expected: `HTTP/… 503`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`; затем `403`; затем `200`.
