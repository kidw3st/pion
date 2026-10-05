# Автовыкладка pionperm.ru (этап 1) — план работ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** любой коммит в `master` сам собирается на GitHub Actions и не позже чем через 15 минут после сборки оказывается на pionperm.ru — без ручных шагов, с проверками, откатом и сообщением о сбоях.

**Architecture:** `.github/workflows/deploy.yml` на каждый push в `master` прогоняет тесты, собирает сайт и кладёт в ветку `server-build` два архива и `build-info.json` с их sha256. На сервере `pay/deploy.php` раз в 15 минут узнаёт SHA ветки, скачивает файлы по SHA, сверяет sha256, распаковывает во временную папку, проверяет и раскладывает, удаляя файлы прошлой сборки, которых нет в новой. Всё без сети — в `server-pay/deploy-lib.php` и покрыто PHP-проверками; сеть, Telegram и режимы командной строки — в `server-pay/deploy.php`.

**Tech Stack:** GitHub Actions (Node 20, PHP 8.2 через `shivammathur/setup-php`), Next.js 14 (static export), PHP 8.2 CLI на хостинге (`exec`, `tar`, `curl`), vitest, PHP-проверки без PHPUnit.

**Спецификация:** `docs/superpowers/specs/2026-10-05-catalog-admin-design.md`, разделы «Архитектура», «4. Сборка на GitHub Actions», «5. Выкладка на сервер», этап 1.

**Отличия от спецификации на этом этапе.** Расписания на GitHub пока нет: сборка запускается только push в `master` и вручную. Расписание раз в 15 минут и его продление раз в 50 дней появятся на этапе 3 вместе с выгрузкой каталога. В `build-info.json` пока нет версии каталога. Сторож вместо «каталог не выложен 90 минут» следит за тем, что коммит в `master` 90 минут не превращается в сборку.

## Global Constraints

- Сервер — shared-хостинг reg.ru, PHP 8.2.33 CLI; `exec`, `tar`, `git`, `curl`, `flock` есть; Node нет. Веб-корень `/var/www/u3620798/data/www/pionperm.ru`, служебная папка выкладки `/var/www/u3620798/data/pion-deploy` (вне веб-корня).
- Сервер берёт сборку только по SHA: `https://raw.githubusercontent.com/kidw3st/pion/<SHA>/<файл>`. SHA ветки — из `https://github.com/kidw3st/pion.git/info/refs?service=git-upload-pack`, не из REST API: у него лимит 60 запросов в час на IP, а IP хостинга общий.
- Защищённые пути веб-корня выкладка сайта не перезаписывает и не удаляет: `pay/`, `blog/` (кроме `blog/wp-content/themes/pion/`), `images/catalog/`, `images/showcase/`, `api/showcase.json`, `.well-known/` (кроме `.well-known/agent-skills/` — их кладёт сборка, исправлено при выполнении задачи 5).
- Обязательные файлы архива сайта: `index.html`, `404.html`, `.htaccess`, `sitemap.xml`, `robots.txt`.
- `images/catalog/` в архив сайта не входит. `config.php` в платёжный архив не входит и выкладкой не трогается никогда.
- Каждый PHP-файл сборки (платёжная часть и тема блога) проходит `php -l`. Одна ошибка — выкладка отменяется целиком, сайт остаётся прежним.
- Хранятся три сборки: текущая и две прошлые для отката. Первая выкладка по новой схеме ничего не удаляет.
- Расписание сервера — раз в 15 минут. Сторож пишет только в служебный чат `DEPLOY_ALERT_CHAT_ID`, никогда в чат заказов `TELEGRAM_CHAT_ID`. Испорченная сборка — сразу; сбой сети — если не прошёл за час; коммит в `master` не стал сборкой — через 90 минут. Одно и то же — не чаще раза в 3 часа.
- У workflow нет прав `pages`; GitHub Pages у репозитория выключены.
- `master` — это то, что на сайте: незаконченная работа живёт в отдельных ветках.
- Секреты — только в `config.php` на сервере. Репозиторий публичный.
- Комментарии, сообщения и логи — по-русски, как во всём проекте. PHP-файлы начинаются с `declare(strict_types=1);`.
- Глобальной git-личности на машине нет. Коммит — только так, иначе он не создастся:
  `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit ...`; в конце сообщения строка `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; после коммита — `git push origin master`.
- Локально PHP — `C:\php82\php.exe` (в Git Bash `/c/php82/php.exe`, ставится в задаче 1). Путь без кириллицы намеренно: из `exec` PHP на Windows о неё спотыкается.
- Папки `Documents\Pion-backup-2026-08-12` и архив `Pion-backup-2026-08-17.zip` не трогать.
- pionperm.ru не заваливать запросами: хостинг уже банил IP за частые обращения.

## Файлы

| Файл | Что делает |
|---|---|
| `tests/php/run.php` | прогон PHP-проверок и их помощники (`t_equal`, `t_tmpdir`, …) |
| `tests/php/deploy_lib_test.php` | защищённые пути, ветки из `info/refs`, списки файлов, sha256 |
| `tests/php/deploy_apply_test.php` | раскладка сборки на временных папках с настоящим `tar` |
| `tests/php/deploy_state_test.php` | состояние выкладки, откат, решения сторожа |
| `server-pay/deploy-lib.php` | всё о выкладке, что не ходит в сеть |
| `server-pay/deploy.php` | командная строка: GitHub, Telegram, блокировка, режимы |
| `server-pay/.htaccess` | закрыть `deploy*.php` от браузера |
| `server-pay/config.example.php` | константа `DEPLOY_ALERT_CHAT_ID` |
| `scripts/pack-build.mjs`, `scripts/pack-build.test.mjs` | упаковка сборки и `build-info.json` |
| `.github/workflows/deploy.yml` | сборка `master` и выкладка в `server-build` |
| `.github/workflows/ci.yml` | проверки веток и pull request, плюс PHP |
| `vitest.config.ts`, `package.json` | тесты из `scripts/`, команды `test:php` и `pack` |
| `docs/deploy.md` | как устроена выкладка и что делать при сбое |

---

### Task 1: PHP для проверок — локально и в CI

**Files:**
- Create: `tests/php/run.php`
- Modify: `package.json` (раздел `scripts`)
- Modify: `.github/workflows/ci.yml`
- Вне репозитория: `C:\php82\` — переносной PHP 8.2 для Windows

**Interfaces:**
- Produces: `php tests/php/run.php` подключает по алфавиту все `tests/php/*_test.php` и выходит с кодом 1, если хоть одна проверка не прошла. Помощники для проверок:
  - `t_equal(mixed $got, mixed $want, string $what): void` — строгое `===`;
  - `t_true(bool $cond, string $what): void`;
  - `t_throws(callable $fn, string $class, string $what): ?Throwable` — ждёт исключение класса `$class`, возвращает его;
  - `t_tmpdir(): string` — новая пустая папка (путь через `/`), удаляется в конце прогона;
  - `t_put_files(string $dir, array $files): void` — `относительный путь => содержимое`;
  - `t_snapshot(string $dir): array` — `относительный путь => md5`, по ключам; для проверок «ничего не изменилось».

- [ ] **Step 1: Получить разрешение на скачивание PHP**

Скачивание файла — только с разрешения владельца. Если план выполняют субагенты, этот шаг и шаг 2 делает основной агент до их запуска. Спросить:

> Для проверок PHP-кода выкладки нужен PHP 8.2 на этом компьютере. Скачаю официальную сборку для Windows с windows.php.net (файл вида `php-8.2.N-nts-Win32-vs16-x64.zip`, около 30 МБ) и распакую в `C:\php82`, в систему ничего не ставится. Можно?

- [ ] **Step 2: Скачать и распаковать PHP (PowerShell)**

```powershell
$rel = Invoke-RestMethod https://windows.php.net/downloads/releases/releases.json
$zip = $rel.'8.2'.'nts-vs16-x64'.zip
"$($zip.path)  sha256 $($zip.sha256)"
Invoke-WebRequest "https://windows.php.net/downloads/releases/$($zip.path)" -OutFile "$env:TEMP\php82.zip"
if ((Get-FileHash "$env:TEMP\php82.zip" -Algorithm SHA256).Hash -ne $zip.sha256) { throw 'sha256 не совпал — файл не тот' }
Expand-Archive "$env:TEMP\php82.zip" -DestinationPath C:\php82 -Force
Remove-Item "$env:TEMP\php82.zip"
```

Если в `releases.json` другая структура, открыть https://windows.php.net/downloads/releases/ и взять самый новый `php-8.2.*-nts-Win32-vs16-x64.zip`. Если в `C:\` нельзя создать папку, использовать `C:\Users\Public\php82` и дальше везде подставлять этот путь.

- [ ] **Step 3: Включить расширения и проверить**

```powershell
Copy-Item C:\php82\php.ini-development C:\php82\php.ini
(Get-Content C:\php82\php.ini) `
  -replace '^;extension_dir = "ext"', 'extension_dir = "ext"' `
  -replace '^;extension=(curl|mbstring|openssl|pdo_sqlite|sqlite3|gd|fileinfo)$', 'extension=$1' |
  Set-Content -Encoding ascii C:\php82\php.ini
C:\php82\php.exe -v
C:\php82\php.exe -m
```

Expected: первая строка `PHP 8.2.… (cli)`. В списке модулей есть `curl`, `gd`, `mbstring`, `openssl`, `Phar`, `pdo_sqlite`, `sqlite3`, `zlib`. Если `php.exe` не запускается с ошибкой про `VCRUNTIME140.dll`, попросить владельца поставить «Microsoft Visual C++ Redistributable 2015–2022 (x64)» с сайта Microsoft и повторить.

- [ ] **Step 4: Написать прогон проверок `tests/php/run.php`**

```php
<?php
/**
 * PHP-проверки проекта: по очереди подключает tests/php/*_test.php.
 *
 *   php tests/php/run.php
 *
 * PHPUnit сюда не тянем: в проекте нет composer, а проверкам хватает
 * нескольких функций ниже. Код выхода 1, если хоть одна проверка не
 * прошла, — по нему CI понимает, что сборку выкладывать нельзя.
 */

declare(strict_types=1);

$GLOBALS['t_passed'] = 0;
$GLOBALS['t_failed'] = 0;
$GLOBALS['t_dirs'] = [];

/** Строгое сравнение: тип тоже важен. */
function t_equal(mixed $got, mixed $want, string $what): void
{
    if ($got === $want) {
        $GLOBALS['t_passed']++;
        return;
    }
    $GLOBALS['t_failed']++;
    fwrite(STDERR, '  ПЛОХО: ' . $what . PHP_EOL
        . '    получили: ' . var_export($got, true) . PHP_EOL
        . '    ждали:    ' . var_export($want, true) . PHP_EOL);
}

function t_true(bool $cond, string $what): void
{
    t_equal($cond, true, $what);
}

/** Функция должна бросить исключение класса $class; возвращает его. */
function t_throws(callable $fn, string $class, string $what): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        t_true($e instanceof $class, $what . ' (бросило ' . get_class($e) . ': ' . $e->getMessage() . ')');
        return $e;
    }
    t_true(false, $what . ' (исключения не было)');
    return null;
}

/** Новая пустая папка; удаляется в конце прогона. */
function t_tmpdir(): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pion-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    $GLOBALS['t_dirs'][] = $dir;
    return $dir;
}

/** Кладёт файлы в папку: относительный путь => содержимое. */
function t_put_files(string $dir, array $files): void
{
    foreach ($files as $rel => $body) {
        $path = $dir . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $body);
    }
}

/** Снимок папки: относительный путь => md5 содержимого. */
function t_snapshot(string $dir): array
{
    $snap = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
            $snap[$rel] = md5_file($file->getPathname());
        }
    }
    ksort($snap, SORT_STRING);
    return $snap;
}

function t_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$tests = glob(__DIR__ . '/*_test.php') ?: [];
sort($tests, SORT_STRING);
foreach ($tests as $test) {
    echo basename($test), PHP_EOL;
    require $test;
}

foreach ($GLOBALS['t_dirs'] as $dir) {
    t_rmtree($dir);
}

printf('Проверок: %d, не прошло: %d%s', $GLOBALS['t_passed'] + $GLOBALS['t_failed'], $GLOBALS['t_failed'], PHP_EOL);
exit($GLOBALS['t_failed'] > 0 ? 1 : 0);
```

- [ ] **Step 5: Запустить прогон**

Run: `/c/php82/php.exe tests/php/run.php; echo "exit=$?"`
Expected: `Проверок: 0, не прошло: 0` и `exit=0`.

- [ ] **Step 6: Команда `test:php` и PHP в CI**

В `package.json`, в `scripts`, после строки `"test": "vitest run",` добавить:

```json
    "test:php": "php tests/php/run.php",
```

В `.github/workflows/ci.yml` между `- run: npm test` и комментарием «Боевая сборка» вставить:

```yaml
      # PHP — та же версия, что на хостинге. Сначала синтаксис всех файлов,
      # которые уезжают на сервер, потом проверки выкладки.
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none

      - run: find server-pay wp-theme -name '*.php' -print0 | xargs -0 -n1 php -l

      - run: php tests/php/run.php

```

- [ ] **Step 7: Commit и проверка CI**

```bash
git add tests/php/run.php package.json .github/workflows/ci.yml
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "chore: PHP-проверки — прогон без PHPUnit и PHP 8.2 в CI" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
```

Через 3–5 минут проверить последний запуск CI (без ключа, один запрос):

```bash
curl -s "https://api.github.com/repos/kidw3st/pion/actions/workflows/ci.yml/runs?per_page=1" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{const r=JSON.parse(s).workflow_runs[0];console.log(r.head_sha.slice(0,7),r.status,r.conclusion)})"
```

Expected: SHA последнего коммита, `completed success`. Если `in_progress` — подождать и повторить.

---

### Task 2: Чистые функции выкладки

**Files:**
- Create: `server-pay/deploy-lib.php`
- Create: `tests/php/deploy_lib_test.php`

**Interfaces:**
- Consumes: помощники из `tests/php/run.php` (задача 1).
- Produces (в `server-pay/deploy-lib.php`):
  - константы `DEPLOY_PROTECTED`, `DEPLOY_OWNED_IN_PROTECTED`, `DEPLOY_REQUIRED`;
  - `final class DeployFatal extends RuntimeException` — сборка испорчена, повторять бесполезно;
  - `deploy_is_protected(string $path): bool`;
  - `deploy_parse_refs(string $body): array` — `ветка => SHA`;
  - `deploy_list_files(string $dir): array` — `list<string>`, пути через `/`, по алфавиту, без папок;
  - `deploy_stale_files(array $old, array $new): array` — что удалить;
  - `deploy_forbidden_files(array $files): array` — файлы сборки в защищённых путях;
  - `deploy_missing_required(array $files): array` — каких обязательных файлов нет;
  - `deploy_check_archives(array $info, array $paths): array` — ошибки сверки sha256, `$paths` — `'site'|'pay' => путь`.

- [ ] **Step 1: Написать проверки `tests/php/deploy_lib_test.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

// --- Защищённые пути -------------------------------------------------------
foreach ([
    'pay/config.php' => true,
    'pay' => true,
    'blog/index.php' => true,
    'blog/wp-content/uploads/a.jpg' => true,
    'blog/wp-content/themes/pion/style.css' => false,
    'images/catalog/bukety/a.webp' => true,
    'images/showcase/b.webp' => true,
    'api/showcase.json' => true,
    '.well-known/acme-challenge/x' => true,
    'index.html' => false,
    'images/site/logo.webp' => false,
    'api/catalog/bukety.json' => false,
    'payment/index.html' => false,
] as $path => $want) {
    t_equal(deploy_is_protected($path), $want, "защищён ли $path");
}

// --- Ветки из ответа git-сервера ------------------------------------------
// Формат pkt-line: 4 hex-цифры длины строки (вместе с ними), затем строка.
$pkt = static fn(string $line): string => sprintf('%04x', strlen($line) + 4) . $line;
$body = $pkt("# service=git-upload-pack\n") . '0000'
    . $pkt(str_repeat('a', 40) . " HEAD\0multi_ack symref=HEAD:refs/heads/master\n")
    . $pkt(str_repeat('a', 40) . " refs/heads/master\n")
    . $pkt(str_repeat('b', 40) . " refs/heads/server-build\n")
    . $pkt(str_repeat('c', 40) . " refs/heads/feature/x\n")
    . $pkt(str_repeat('d', 40) . " refs/tags/v1.0.0\n")
    . '0000';
t_equal(deploy_parse_refs($body), [
    'master' => str_repeat('a', 40),
    'server-build' => str_repeat('b', 40),
    'feature/x' => str_repeat('c', 40),
], 'ветки из ответа info/refs');
t_equal(deploy_parse_refs('<html>rate limit</html>'), [], 'не тот ответ — веток нет');

// --- Список файлов распакованной сборки -----------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['b/x.html' => '1', 'a.txt' => '2', '.htaccess' => '3', 'b/c/d.css' => '4']);
mkdir($dir . '/empty');
t_equal(
    deploy_list_files($dir),
    ['.htaccess', 'a.txt', 'b/c/d.css', 'b/x.html'],
    'только файлы, через «/», по алфавиту',
);

// --- Что удалить после выкладки -------------------------------------------
t_equal(
    deploy_stale_files(
        ['index.html', 'old/index.html', 'images/catalog/a.webp', 'pay/init.php', 'blog/wp-content/themes/pion/old.php'],
        ['index.html', 'new/index.html'],
    ),
    ['old/index.html', 'blog/wp-content/themes/pion/old.php'],
    'удаляется только своё и пропавшее',
);
t_equal(deploy_stale_files(['a', 'b'], ['a', 'b']), [], 'одинаковые сборки — удалять нечего');

// --- Состав архива ----------------------------------------------------------
t_equal(
    deploy_forbidden_files(['index.html', 'images/catalog/x.webp', 'pay/init.php']),
    ['images/catalog/x.webp', 'pay/init.php'],
    'чужие пути в архиве сайта',
);
t_equal(deploy_missing_required(['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt']), [], 'всё обязательное на месте');
t_equal(deploy_missing_required(['index.html', '.htaccess']), ['404.html', 'sitemap.xml', 'robots.txt'], 'чего не хватает');

// --- Сверка архивов с build-info.json -------------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['site.tgz' => 'site-bytes', 'pay.tgz' => 'pay-bytes']);
$info = [
    'site' => ['sha256' => hash('sha256', 'site-bytes')],
    'pay' => ['sha256' => hash('sha256', 'pay-bytes')],
];
$paths = ['site' => "$dir/site.tgz", 'pay' => "$dir/pay.tgz"];
t_equal(deploy_check_archives($info, $paths), [], 'sha256 сошлись');
$info['pay']['sha256'] = str_repeat('0', 64);
t_equal(deploy_check_archives($info, $paths), ['pay: sha256 не совпадает'], 'подменённый архив');
unset($info['site']);
t_equal(
    deploy_check_archives($info, $paths),
    ['в build-info.json нет sha256 для site', 'pay: sha256 не совпадает'],
    'нет записи в build-info.json',
);
```

- [ ] **Step 2: Убедиться, что проверки падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: FAIL — `Failed opening required '.../server-pay/deploy-lib.php'`.

- [ ] **Step 3: Написать `server-pay/deploy-lib.php`**

```php
<?php
/**
 * Выкладка сборки сайта на сервер — всё, что не ходит в сеть.
 *
 * deploy.php (запуск по расписанию) спрашивает у GitHub, есть ли новая
 * сборка, скачивает её и отдаёт сюда: проверить, разложить, удалить
 * устаревшее. Разделено ради проверок: эти функции гоняются в tests/php/
 * на временных папках, без сервера и без GitHub.
 */

declare(strict_types=1);

/**
 * Пути веб-корня, которые сборке не принадлежат. Их наполняют сервер
 * (витрина из CRM, фото каталога из админки), WordPress или платёжный
 * архив. Выкладка сайта их не перезаписывает и не удаляет, даже если они
 * по ошибке попали в список файлов прошлой сборки.
 */
const DEPLOY_PROTECTED = [
    'pay/',
    'blog/',
    'images/catalog/',
    'images/showcase/',
    'api/showcase.json',
    '.well-known/',
];

/** Внутри защищённых путей сборке принадлежит только тема блога. */
const DEPLOY_OWNED_IN_PROTECTED = ['blog/wp-content/themes/pion/'];
// При выполнении задачи 5 сюда добавлен '.well-known/agent-skills/' —
// эти файлы для ИИ-ассистентов кладёт сборка (scripts/build-agent-assets.mjs).

/** Без этих файлов сайт не работает — такую сборку не выкладываем. */
const DEPLOY_REQUIRED = ['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt'];

/**
 * Сборка испорчена: повторять бесполезно, пока не придёт новая. Сетевые
 * сбои — обычный RuntimeException: после них выкладку просто пробуют снова.
 */
final class DeployFatal extends RuntimeException
{
}

function deploy_is_protected(string $path): bool
{
    foreach (DEPLOY_OWNED_IN_PROTECTED as $owned) {
        if (str_starts_with($path, $owned)) {
            return false;
        }
    }
    foreach (DEPLOY_PROTECTED as $prefix) {
        if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * SHA веток из ответа git-сервера на /info/refs?service=git-upload-pack —
 * с этого запроса начинает git clone. У REST API GitHub без ключа лимит
 * 60 запросов в час на IP, а IP у хостинга общий с чужими сайтами.
 *
 * @return array<string,string> ветка => SHA
 */
function deploy_parse_refs(string $body): array
{
    preg_match_all('~([0-9a-f]{40}) refs/heads/([^\s\x00]+)~', $body, $matches, PREG_SET_ORDER);
    $refs = [];
    foreach ($matches as [, $sha, $branch]) {
        $refs[$branch] = $sha;
    }
    return $refs;
}

/**
 * Файлы папки: пути относительно неё через «/», по алфавиту. Папки в список
 * не входят — сравниваются и удаляются только файлы.
 *
 * @return list<string>
 */
function deploy_list_files(string $dir): array
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Что удалить после выкладки: файлы прошлой сборки, которых нет в новой.
 * Защищённые пути не удаляются никогда.
 *
 * @param list<string> $old
 * @param list<string> $new
 * @return list<string>
 */
function deploy_stale_files(array $old, array $new): array
{
    $keep = array_flip($new);
    $stale = [];
    foreach ($old as $path) {
        if (!isset($keep[$path]) && !deploy_is_protected($path)) {
            $stale[] = $path;
        }
    }
    return $stale;
}

/**
 * Файлы сборки в чужих путях. Их быть не должно: значит, сломалась
 * упаковка, и сборка затёрла бы фото каталога или платёжную часть.
 *
 * @param list<string> $files
 * @return list<string>
 */
function deploy_forbidden_files(array $files): array
{
    return array_values(array_filter($files, 'deploy_is_protected'));
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function deploy_missing_required(array $files): array
{
    return array_values(array_diff(DEPLOY_REQUIRED, $files));
}

/**
 * Сверяет скачанные архивы с build-info.json. Пустой ответ — всё сошлось.
 *
 * @param array<string,mixed> $info разобранный build-info.json
 * @param array<string,string> $paths 'site' / 'pay' => путь к архиву
 * @return list<string>
 */
function deploy_check_archives(array $info, array $paths): array
{
    $errors = [];
    foreach ($paths as $key => $path) {
        $want = $info[$key]['sha256'] ?? null;
        if (!is_string($want) || preg_match('/^[0-9a-f]{64}$/', $want) !== 1) {
            $errors[] = "в build-info.json нет sha256 для $key";
            continue;
        }
        if (!is_file($path) || hash_file('sha256', $path) !== $want) {
            $errors[] = "$key: sha256 не совпадает";
        }
    }
    return $errors;
}
```

- [ ] **Step 4: Убедиться, что проверки проходят**

Run: `/c/php82/php.exe tests/php/run.php; echo "exit=$?"`
Expected: `deploy_lib_test.php`, затем `Проверок: 24, не прошло: 0`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git add server-pay/deploy-lib.php tests/php/deploy_lib_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat: выкладка — защищённые пути, ветки из info/refs, списки файлов" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
```

---

### Task 3: Раскладка сборки на сайт

**Files:**
- Modify: `server-pay/deploy-lib.php` (дописать в конец)
- Create: `tests/php/deploy_apply_test.php`

**Interfaces:**
- Consumes: всё из задачи 2; помощники из задачи 1.
- Produces:
  - `deploy_tar(): string` — уже экранированная команда `tar` для `exec`;
  - `deploy_rmtree(string $dir): void`;
  - `deploy_extract(string $archive, string $dir): void` — `RuntimeException`, если `tar` не справился;
  - `deploy_lint_php(string $root, array $files, string $label = ''): array` — ошибки `php -l` вида `pay/init.php: PHP Parse error …`;
  - `deploy_copy_files(string $from, array $files, string $to): void`;
  - `deploy_delete_files(string $root, array $files): array` — что удалено;
  - `deploy_prune_dirs(string $root, string $relDir): void`;
  - `deploy_apply(array $archives, ?array $previousFiles, string $webroot, bool $updatePay, string $work, bool $dryRun): array` — отчёт `['files' => list, 'stale' => list, 'deleted' => list, 'payFiles' => list, 'payUpdated' => bool]`. `$archives` — `['site' => путь, 'pay' => путь]`. Испорченная сборка — `DeployFatal`, сбой распаковки или копирования — `RuntimeException`.

- [ ] **Step 1: Написать проверки `tests/php/deploy_apply_test.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

/** Архив, как его собирает GitHub: tar.gz от корня папки. */
function t_make_archive(string $path, array $files): void
{
    $src = t_tmpdir();
    t_put_files($src, $files);
    exec(deploy_tar() . ' -czf ' . escapeshellarg($path) . ' -C ' . escapeshellarg($src) . ' . 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('tar: ' . implode(' ', $out));
    }
}

/** Сборка с обязательными файлами и тем, что добавлено сверху. */
function t_site(array $extra = []): array
{
    return [
        'index.html' => 'new home',
        '404.html' => 'not found',
        '.htaccess' => 'rules',
        'sitemap.xml' => '<urlset/>',
        'robots.txt' => 'User-agent: *',
    ] + $extra;
}

/** Веб-корень, где уже лежат прошлая сборка, WordPress, /pay/ и чужие файлы. */
function t_webroot(): string
{
    $web = t_tmpdir();
    t_put_files($web, [
        'index.html' => 'old home',
        'old-page/index.html' => 'stale',
        'images/catalog/bukety/a.webp' => 'photo from admin',
        'api/showcase.json' => '{"showcase":1}',
        'pay/config.php' => '<?php // secrets',
        'pay/init.php' => '<?php echo 0;',
        'blog/index.php' => '<?php // wordpress',
        'manual.txt' => 'not from any build',
    ]);
    return $web;
}

/** Список прошлой сборки; защищённые пути попали в него «по ошибке». */
$prevFiles = ['index.html', 'old-page/index.html', 'images/catalog/bukety/a.webp', 'api/showcase.json', 'blog/index.php'];

// --- Обычная выкладка поверх прошлой --------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site([
    'catalog/index.html' => 'catalog',
    'blog/wp-content/themes/pion/style.css' => 'theme',
]));
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;', '.htaccess' => 'Require all denied']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false);

t_equal(file_get_contents("$web/index.html"), 'new home', 'главная заменена');
t_equal(file_get_contents("$web/catalog/index.html"), 'catalog', 'новая страница появилась');
t_true(!file_exists("$web/old-page/index.html"), 'устаревшая страница удалена');
t_true(!is_dir("$web/old-page"), 'опустевшая папка удалена');
t_equal(file_get_contents("$web/images/catalog/bukety/a.webp"), 'photo from admin', 'фото каталога не тронуто, хоть и было в прошлом списке');
t_equal(file_get_contents("$web/api/showcase.json"), '{"showcase":1}', 'витрина не тронута');
t_equal(file_get_contents("$web/blog/index.php"), '<?php // wordpress', 'WordPress не тронут');
t_equal(file_get_contents("$web/blog/wp-content/themes/pion/style.css"), 'theme', 'тема блога выложена');
t_equal(file_get_contents("$web/manual.txt"), 'not from any build', 'файл не из сборки остался');
t_equal(file_get_contents("$web/pay/config.php"), '<?php // secrets', 'config.php не тронут');
t_equal(file_get_contents("$web/pay/init.php"), '<?php echo 1;', '/pay/ обновлён');
t_equal(file_get_contents("$web/pay/.htaccess"), 'Require all denied', 'скрытые файлы /pay/ тоже выложены');
t_equal($report['deleted'], ['old-page/index.html'], 'отчёт: удалено');
t_equal(count($report['files']), 7, 'отчёт: файлы сборки');
t_true($report['payUpdated'], 'отчёт: /pay/ обновлён');
t_true(!is_dir("$work/x"), 'рабочая папка убрана');
t_equal(
    array_values(array_filter(array_keys(t_snapshot($web)), static fn(string $p): bool => str_ends_with($p, '.deploy-part'))),
    [],
    'временных файлов не осталось',
);

// --- Первая выкладка: удалять нечего ------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site());
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], null, $web, true, "$work/x", false);
t_equal($report['deleted'], [], 'первая выкладка ничего не удаляет');
t_true(is_file("$web/old-page/index.html"), 'старая страница на месте');

// --- Платёжный архив не менялся ------------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site());
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, false, "$work/x", false);
t_equal(file_get_contents("$web/pay/init.php"), '<?php echo 0;', '/pay/ не тронут, если архив тот же');
t_true(!$report['payUpdated'], 'отчёт: /pay/ не обновлялся');

// --- Пробный прогон ничего не меняет -------------------------------------
$work = t_tmpdir();
$web = t_webroot();
$before = t_snapshot($web);
t_make_archive("$work/site.tgz", t_site(['catalog/index.html' => 'catalog']));
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", true);
t_equal(t_snapshot($web), $before, 'пробный прогон не трогает сайт');
t_equal($report['stale'], ['old-page/index.html'], 'пробный прогон знает, что удалил бы');

// --- Испорченные сборки: сайт не меняется -------------------------------
$broken = [
    'нет robots.txt' => [array_diff_key(t_site(), ['robots.txt' => 1]), ['init.php' => '<?php echo 1;']],
    'фото каталога в архиве сайта' => [t_site(['images/catalog/x.webp' => 'x']), ['init.php' => '<?php echo 1;']],
    'config.php в платёжном архиве' => [t_site(), ['init.php' => '<?php echo 1;', 'config.php' => '<?php // leak']],
    'синтаксис в /pay/' => [t_site(), ['init.php' => '<?php echo (;']],
    'синтаксис в теме блога' => [t_site(['blog/wp-content/themes/pion/functions.php' => '<?php function (']), ['init.php' => '<?php echo 1;']],
];
foreach ($broken as $what => [$siteFiles, $payFiles]) {
    $work = t_tmpdir();
    $web = t_webroot();
    $before = t_snapshot($web);
    t_make_archive("$work/site.tgz", $siteFiles);
    t_make_archive("$work/pay.tgz", $payFiles);
    t_throws(
        fn() => deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false),
        DeployFatal::class,
        "$what — сборка отклонена",
    );
    t_equal(t_snapshot($web), $before, "$what — сайт не тронут");
}

// --- Битый архив — сбой, но не приговор сборке -------------------------
$work = t_tmpdir();
$web = t_webroot();
file_put_contents("$work/site.tgz", 'not a tar');
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$e = t_throws(
    fn() => deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false),
    RuntimeException::class,
    'битый архив — сбой',
);
t_true(!($e instanceof DeployFatal), 'битый архив сервер скачает заново, а не пометит сборку плохой');
```

- [ ] **Step 2: Убедиться, что проверки падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: FAIL — `Call to undefined function deploy_tar()`.

- [ ] **Step 3: Дописать в конец `server-pay/deploy-lib.php`**

```php

// --- Раскладка сборки ------------------------------------------------------

/**
 * tar для exec. На хостинге — системный GNU tar. На Windows (локальные
 * проверки) — встроенный bsdtar по полному пути: tar из Git Bash принял бы
 * «C:» в пути к архиву за имя удалённого сервера.
 */
function deploy_tar(): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'tar';
    }
    return escapeshellarg((getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe');
}

/** Удаляет папку со всем содержимым; нет папки — ничего не делает. */
function deploy_rmtree(string $dir): void
{
    if (is_file($dir) || is_link($dir)) {
        unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

/** Распаковывает tar.gz в папку, создавая её. Ошибка tar — исключение. */
function deploy_extract(string $archive, string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("не создать папку $dir");
    }
    $out = [];
    exec(deploy_tar() . ' -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('не распаковался ' . basename($archive) . ': ' . implode(' ', array_slice($out, 0, 2)));
    }
}

/**
 * php -l для каждого PHP-файла. Ошибка синтаксиса в /pay/ — сломанная
 * оплата, в теме — белый экран блога, поэтому такую сборку не выкладываем.
 *
 * @param list<string> $files пути относительно $root
 * @return list<string> ошибки вида «pay/init.php: PHP Parse error …»
 */
function deploy_lint_php(string $root, array $files, string $label = ''): array
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $errors = [];
    foreach ($files as $rel) {
        if (!str_ends_with($rel, '.php')) {
            continue;
        }
        $out = [];
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $first = trim((string)($out[0] ?? 'ошибка синтаксиса'));
            $errors[] = $label . $rel . ': ' . str_replace($root . '/', '', $first);
        }
    }
    return $errors;
}

/**
 * Копирует файлы на место. Каждый пишется рядом под временным именем и
 * переименовывается: посетитель не получит наполовину записанную страницу.
 *
 * @param list<string> $files пути относительно $from
 */
function deploy_copy_files(string $from, array $files, string $to): void
{
    foreach ($files as $rel) {
        $dst = $to . '/' . $rel;
        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("не создать папку $dir");
        }
        $tmp = $dst . '.deploy-part';
        if (!copy($from . '/' . $rel, $tmp) || !rename($tmp, $dst)) {
            @unlink($tmp);
            throw new RuntimeException("не скопировать $rel");
        }
    }
}

/**
 * Удаляет устаревшие файлы сборки и опустевшие после этого папки.
 *
 * @param list<string> $files пути относительно $root
 * @return list<string> что действительно удалено
 */
function deploy_delete_files(string $root, array $files): array
{
    $deleted = [];
    foreach ($files as $rel) {
        if (deploy_is_protected($rel) || !is_file($root . '/' . $rel)) {
            continue;
        }
        if (unlink($root . '/' . $rel)) {
            $deleted[] = $rel;
            deploy_prune_dirs($root, dirname($rel));
        }
    }
    return $deleted;
}

/** Поднимается от папки к веб-корню и удаляет пустые; защищённые не трогает. */
function deploy_prune_dirs(string $root, string $relDir): void
{
    while ($relDir !== '.' && $relDir !== '' && !deploy_is_protected($relDir . '/')) {
        $abs = $root . '/' . $relDir;
        if (!is_dir($abs) || (new FilesystemIterator($abs))->valid() || !@rmdir($abs)) {
            return;
        }
        $relDir = dirname($relDir);
    }
}

/**
 * Раскладывает сборку на сайт.
 *
 * Испорченная сборка не трогает ни одного файла: распаковка во временную
 * папку и все проверки идут до копирования. Проверки: обязательные файлы,
 * ничего в защищённых путях, нет config.php в платёжном архиве, php -l.
 * Провал — DeployFatal, сайт остаётся прежним.
 *
 * @param array{site:string,pay:string} $archives
 * @param list<string>|null $previousFiles файлы прошлой сборки; null — первая выкладка, удалять нечего
 * @param bool $updatePay раскладывать ли платёжный архив (false — он не менялся)
 * @param string $work временная папка; очищается до и после
 * @param bool $dryRun только проверить и посчитать
 * @return array{files:list<string>,stale:list<string>,deleted:list<string>,payFiles:list<string>,payUpdated:bool}
 */
function deploy_apply(
    array $archives,
    ?array $previousFiles,
    string $webroot,
    bool $updatePay,
    string $work,
    bool $dryRun,
): array {
    deploy_rmtree($work);
    try {
        deploy_extract($archives['site'], $work . '/site');
        deploy_extract($archives['pay'], $work . '/pay');
        $files = deploy_list_files($work . '/site');
        $payFiles = deploy_list_files($work . '/pay');

        $problems = [];
        $missing = deploy_missing_required($files);
        if ($missing !== []) {
            $problems[] = 'нет обязательных файлов: ' . implode(', ', $missing);
        }
        $forbidden = deploy_forbidden_files($files);
        if ($forbidden !== []) {
            $problems[] = 'файлы в чужих путях: ' . implode(', ', array_slice($forbidden, 0, 5));
        }
        if (in_array('config.php', $payFiles, true)) {
            $problems[] = 'в платёжном архиве лежит config.php';
        }
        array_push(
            $problems,
            ...deploy_lint_php($work . '/site', $files),
            ...deploy_lint_php($work . '/pay', $payFiles, 'pay/'),
        );
        if ($problems !== []) {
            throw new DeployFatal(implode('; ', $problems));
        }

        $report = [
            'files' => $files,
            'stale' => $previousFiles === null ? [] : deploy_stale_files($previousFiles, $files),
            'deleted' => [],
            'payFiles' => $payFiles,
            'payUpdated' => false,
        ];
        if ($dryRun) {
            return $report;
        }

        deploy_copy_files($work . '/site', $files, $webroot);
        $report['deleted'] = deploy_delete_files($webroot, $report['stale']);
        if ($updatePay) {
            deploy_copy_files($work . '/pay', $payFiles, $webroot . '/pay');
            $report['payUpdated'] = true;
        }
        return $report;
    } finally {
        deploy_rmtree($work);
    }
}
```

- [ ] **Step 4: Убедиться, что проверки проходят**

Run: `/c/php82/php.exe tests/php/run.php; echo "exit=$?"`
Expected: оба файла проверок, `не прошло: 0`, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git add server-pay/deploy-lib.php tests/php/deploy_apply_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat: выкладка — проверка и раскладка сборки с удалением устаревших файлов" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
```

---

### Task 4: Состояние, сторож и команда `deploy.php`

**Files:**
- Modify: `server-pay/deploy-lib.php` (дописать в конец)
- Create: `server-pay/deploy.php`
- Create: `tests/php/deploy_state_test.php`
- Modify: `server-pay/.htaccess`
- Modify: `server-pay/config.example.php`

**Interfaces:**
- Consumes: всё из задач 2–3.
- Produces (в `deploy-lib.php`). Запись о сборке — `['sha' => string, 'commit' => string, 'paySha256' => string, 'deployedAt' => int]`, где `sha` — коммит ветки `server-build`, `commit` — коммит кода в `master`.
  - константы `DEPLOY_KEEP_PREVIOUS = 2`, `DEPLOY_TRANSIENT_ALERT_AFTER = 3600`, `DEPLOY_LAG_ALERT_AFTER = 5400`, `DEPLOY_ALERT_COOLDOWN = 10800`;
  - `deploy_empty_state(): array` — ключи `current`, `history`, `bad`, `failure`, `alerts`, `master`;
  - `deploy_state_load(string $home): array`, `deploy_state_save(string $home, array $state): void`;
  - `deploy_state_after_success(array $state, array $release, int $now): array`;
  - `deploy_state_after_failure(array $state, string $kind, string $message, ?string $sha, int $now): array` — `$kind`: `'transient'` или `'fatal'`;
  - `deploy_state_after_rollback(array $state, int $now): array` — `RuntimeException`, если откатываться не на что;
  - `deploy_state_idle(array $state): array`;
  - `deploy_mark_bad(array $state, string $sha, string $why): array`;
  - `deploy_note_master(array $state, string $sha, int $now): array`;
  - `deploy_alert_due(array $state, string $kind, int $now): bool`;
  - `deploy_pending_alerts(array $state, int $now): array` — `list` из `'fatal' | 'transient' | 'lag'`;
  - `deploy_mark_alerted(array $state, string $kind, int $now): array`;
  - `deploy_alert_text(string $kind, array $state): string`;
  - `deploy_release_dir(string $home, string $sha): string`;
  - `deploy_release_files(string $home, ?string $sha): ?array`, `deploy_save_release_files(string $home, string $sha, array $files): void`;
  - `deploy_prune_releases(string $home, array $state): array` — имена удалённых папок.
- Produces (`deploy.php`, только командная строка): режимы без аргумента, `--dry-run`, `--rollback`, `--status`, `--find-chat`, `--test-alert`. Переменные окружения `PION_DEPLOY_HOME` и `PION_DEPLOY_WEBROOT` подменяют пути для локального запуска.

- [ ] **Step 1: Написать проверки `tests/php/deploy_state_test.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

$rel = static fn(string $c): array => ['sha' => str_repeat($c, 40), 'commit' => 'commit-' . $c, 'paySha256' => 'pay-' . $c];

// --- Успешные выкладки: для отката хранятся две прошлые -------------------
$s = deploy_empty_state();
$s = deploy_state_after_success($s, $rel('a'), 100);
t_equal($s['current'], $rel('a') + ['deployedAt' => 100], 'первая выкладка');
t_equal($s['history'], [], 'истории ещё нет');
$s = deploy_state_after_success($s, $rel('b'), 200);
$s = deploy_state_after_success($s, $rel('c'), 300);
$s = deploy_state_after_success($s, $rel('d'), 400);
t_equal($s['current']['sha'], str_repeat('d', 40), 'текущая — последняя');
t_equal(array_column($s['history'], 'sha'), [str_repeat('c', 40), str_repeat('b', 40)], 'хранятся две прошлые, новая первой');

// --- Успешная выкладка снимает сбой ---------------------------------------
$s = deploy_state_after_failure($s, 'transient', 'GitHub не ответил', null, 500);
$s = deploy_state_after_success($s, $rel('e'), 600);
t_equal($s['failure'], null, 'после выкладки сбоя нет');

// --- Повторяющийся сбой помнит, когда начался -----------------------------
$s = deploy_empty_state();
$s = deploy_state_after_failure($s, 'transient', 'таймаут', null, 1000);
$s = deploy_state_after_failure($s, 'transient', 'код 502', null, 1900);
t_equal($s['failure']['since'], 1000, 'тот же сбой — время начала прежнее');
t_equal($s['failure']['message'], 'код 502', 'текст — последний');
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', str_repeat('f', 40), 2000);
t_equal($s['failure']['since'], 2000, 'другой сбой — отсчёт заново');
t_equal($s['bad'], [str_repeat('f', 40) => 'нет robots.txt'], 'испорченная сборка помечена плохой');

// --- Без дела: сеть восстановилась, а испорченная сборка всё ещё последняя -
$t = deploy_state_after_failure(deploy_empty_state(), 'transient', 'таймаут', null, 1);
t_equal(deploy_state_idle($t)['failure'], null, 'сеть восстановилась — сбоя нет');
t_equal(deploy_state_idle($s)['failure']['kind'], 'fatal', 'испорченная сборка — сбой остаётся');

// --- Список плохих сборок не растёт бесконечно ----------------------------
$b = deploy_empty_state();
for ($i = 0; $i < 25; $i++) {
    $b = deploy_mark_bad($b, str_pad(dechex($i), 40, 'a', STR_PAD_LEFT), 'плохая');
}
t_equal(count($b['bad']), 20, 'помним 20 последних плохих сборок');
t_true(isset($b['bad'][str_pad(dechex(24), 40, 'a', STR_PAD_LEFT)]), 'последняя на месте');
t_true(!isset($b['bad'][str_pad(dechex(0), 40, 'a', STR_PAD_LEFT)]), 'самая старая забыта');

// --- Откат -------------------------------------------------------------------
$s = deploy_empty_state();
foreach (['a', 'b', 'c'] as $i => $c) {
    $s = deploy_state_after_success($s, $rel($c), 100 * ($i + 1));
}
$s = deploy_state_after_rollback($s, 999);
t_equal($s['current'], $rel('b') + ['deployedAt' => 999], 'вернулись на прошлую');
t_equal(array_column($s['history'], 'sha'), [str_repeat('a', 40)], 'в истории осталась позапрошлая');
t_equal(array_keys($s['bad']), [str_repeat('c', 40)], 'откаченная помечена плохой');
$s = deploy_state_after_rollback($s, 1000);
t_throws(fn() => deploy_state_after_rollback($s, 1001), RuntimeException::class, 'дальше откатываться некуда');

// --- Отставание сборки от master ------------------------------------------
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_master($s, 'commit-a', 0);
t_equal(deploy_pending_alerts($s, 10_000), [], 'master собран — тишина');
$s = deploy_note_master($s, 'commit-new', 1000);
$s = deploy_note_master($s, 'commit-new', 3000);
t_equal($s['master']['since'], 1000, 'тот же master — время прежнее');
t_equal(deploy_pending_alerts($s, 1000 + 5399), [], 'меньше 90 минут — ждём');
t_equal(deploy_pending_alerts($s, 1000 + 5400), ['lag'], '90 минут без сборки — пишем');
$s = deploy_mark_alerted($s, 'lag', 6400);
t_equal(deploy_pending_alerts($s, 6400 + 3600), [], 'через час не повторяем');
t_equal(deploy_pending_alerts($s, 6400 + 10800), ['lag'], 'через 3 часа напоминаем');

// --- Сбои -------------------------------------------------------------------
$s = deploy_state_after_failure(deploy_empty_state(), 'transient', 'таймаут', null, 0);
t_equal(deploy_pending_alerts($s, 3599), [], 'сеть: меньше часа — молчим');
t_equal(deploy_pending_alerts($s, 3600), ['transient'], 'сеть: час — пишем');
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_master($s, 'commit-new', 0);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', str_repeat('f', 40), 0);
t_equal(deploy_pending_alerts($s, 0), ['fatal'], 'испорченная сборка — сразу');
t_equal(deploy_pending_alerts($s, 10_000), ['fatal'], 'при сбое выкладки об отставании не пишем — причина та же');

// --- Текст сообщений ------------------------------------------------------
$text = deploy_alert_text('fatal', $s);
t_true(str_contains($text, 'fffffff') && str_contains($text, 'php -l'), 'в сообщении сборка и причина');
t_true(str_contains(deploy_alert_text('lag', deploy_note_master($s, 'abcdef1234', 0)), 'abcdef1'), 'в сообщении об отставании — коммит');

// --- Состояние на диске ----------------------------------------------------
$home = t_tmpdir();
t_equal(deploy_state_load($home), deploy_empty_state(), 'нет файла — пустое состояние');
deploy_state_save($home, $s);
t_equal(deploy_state_load($home), $s, 'сохраняется и читается без потерь');

// --- Скачанные сборки ------------------------------------------------------
$home = t_tmpdir();
$s = deploy_empty_state();
foreach (['a', 'b', 'c', 'd'] as $i => $c) {
    mkdir(deploy_release_dir($home, str_repeat($c, 40)), 0777, true);
    $s = deploy_state_after_success($s, $rel($c), $i);
}
mkdir(deploy_release_dir($home, str_repeat('e', 40)) . '.part', 0777, true);
t_equal(
    deploy_prune_releases($home, $s),
    [str_repeat('a', 40), str_repeat('e', 40) . '.part'],
    'удалены лишняя сборка и недокачанная',
);
t_true(is_dir(deploy_release_dir($home, str_repeat('b', 40))), 'позапрошлая для отката на месте');
deploy_save_release_files($home, str_repeat('d', 40), ['index.html', 'a/b.html']);
t_equal(deploy_release_files($home, str_repeat('d', 40)), ['index.html', 'a/b.html'], 'список файлов сборки');
t_equal(deploy_release_files($home, null), null, 'нет сборки — нет списка');
t_equal(deploy_release_files($home, str_repeat('b', 40)), null, 'список не сохранялся — null');
```

- [ ] **Step 2: Убедиться, что проверки падают**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: FAIL — `Call to undefined function deploy_empty_state()`.

- [ ] **Step 3: Дописать в конец `server-pay/deploy-lib.php`**

```php

// --- Состояние выкладки ------------------------------------------------------

/** Сколько прошлых сборок хранится для отката (плюс текущая). */
const DEPLOY_KEEP_PREVIOUS = 2;
/**
 * Временный сбой (сеть, GitHub, не запустился tar или php -l) — пишем, если
 * он не прошёл за час: такое обычно проходит само.
 */
const DEPLOY_TRANSIENT_ALERT_AFTER = 3600;
/** Коммит в master 90 минут не стал сборкой — видимо, сборка падает. */
const DEPLOY_LAG_ALERT_AFTER = 5400;
/** Одно и то же сообщение — не чаще раза в 3 часа. */
const DEPLOY_ALERT_COOLDOWN = 10800;

/**
 * Состояние лежит в pion-deploy/state.json:
 *   current — выложенная сборка: sha (коммит ветки server-build), commit
 *             (коммит кода в master), paySha256, deployedAt;
 *   history — прошлые сборки для отката, новая первой;
 *   bad     — сборки, которые выкладывать нельзя: sha => причина;
 *   failure — текущий сбой: kind (transient|fatal), message, sha, since;
 *   alerts  — когда последний раз писали о сбое каждого рода;
 *   master  — последний увиденный коммит master и с какого времени.
 */
function deploy_empty_state(): array
{
    return ['current' => null, 'history' => [], 'bad' => [], 'failure' => null, 'alerts' => [], 'master' => null];
}

function deploy_state_load(string $home): array
{
    $raw = @file_get_contents($home . '/state.json');
    $state = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($state) ? $state + deploy_empty_state() : deploy_empty_state();
}

/** Пишет состояние целиком: во временный файл, потом подменяет. */
function deploy_state_save(string $home, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $home . '/state.json.part';
    if ($json === false || file_put_contents($tmp, $json . PHP_EOL) === false || !rename($tmp, $home . '/state.json')) {
        throw new RuntimeException('не записать state.json');
    }
}

/** @param array{sha:string,commit:string,paySha256:string} $release */
function deploy_state_after_success(array $state, array $release, int $now): array
{
    if ($state['current'] !== null) {
        array_unshift($state['history'], $state['current']);
        $state['history'] = array_slice($state['history'], 0, DEPLOY_KEEP_PREVIOUS);
    }
    $release['deployedAt'] = $now;
    $state['current'] = $release;
    $state['failure'] = null;
    return $state;
}

/** Помечает сборку плохой. Помним 20 последних — этого хватает с запасом. */
function deploy_mark_bad(array $state, string $sha, string $why): array
{
    unset($state['bad'][$sha]);
    $state['bad'][$sha] = $why;
    $state['bad'] = array_slice($state['bad'], -20, null, true);
    return $state;
}

function deploy_state_after_failure(array $state, string $kind, string $message, ?string $sha, int $now): array
{
    $prev = $state['failure'];
    $same = is_array($prev) && $prev['kind'] === $kind && $prev['sha'] === $sha;
    $state['failure'] = [
        'kind' => $kind,
        'message' => $message,
        'sha' => $sha,
        'since' => $same ? $prev['since'] : $now,
    ];
    if ($kind === 'fatal' && $sha !== null) {
        $state = deploy_mark_bad($state, $sha, $message);
    }
    return $state;
}

function deploy_state_after_rollback(array $state, int $now): array
{
    $previous = $state['history'][0] ?? null;
    if ($state['current'] === null || $previous === null) {
        throw new RuntimeException('откатываться не на что: прошлой сборки нет');
    }
    $state = deploy_mark_bad($state, $state['current']['sha'], 'откат вручную');
    array_shift($state['history']);
    $previous['deployedAt'] = $now;
    $state['current'] = $previous;
    $state['failure'] = null;
    return $state;
}

/**
 * Новой сборки нет. Сбой сети на этом прошёл, а испорченная сборка всё ещё
 * последняя — её сбой не снимаем, пока не придёт новая.
 */
function deploy_state_idle(array $state): array
{
    if (($state['failure']['kind'] ?? null) === 'transient') {
        $state['failure'] = null;
    }
    return $state;
}

function deploy_note_master(array $state, string $sha, int $now): array
{
    if (($state['master']['sha'] ?? null) !== $sha) {
        $state['master'] = ['sha' => $sha, 'since' => $now];
    }
    return $state;
}

function deploy_alert_due(array $state, string $kind, int $now): bool
{
    $last = $state['alerts'][$kind] ?? null;
    return !is_int($last) || $now - $last >= DEPLOY_ALERT_COOLDOWN;
}

/**
 * О чём пора написать в служебный чат.
 *
 * @return list<string> 'fatal' | 'transient' | 'lag'
 */
function deploy_pending_alerts(array $state, int $now): array
{
    $failure = $state['failure'];
    if (is_array($failure)) {
        $ripe = $failure['kind'] === 'fatal' || $now - $failure['since'] >= DEPLOY_TRANSIENT_ALERT_AFTER;
        // Пока выкладка сбоит, об отставании от master не пишем: причина та же.
        return $ripe && deploy_alert_due($state, $failure['kind'], $now) ? [$failure['kind']] : [];
    }
    $master = $state['master'];
    $current = $state['current'];
    if (is_array($master) && is_array($current) && $master['sha'] !== $current['commit']
        && $now - $master['since'] >= DEPLOY_LAG_ALERT_AFTER && deploy_alert_due($state, 'lag', $now)) {
        return ['lag'];
    }
    return [];
}

function deploy_mark_alerted(array $state, string $kind, int $now): array
{
    $state['alerts'][$kind] = $now;
    return $state;
}

function deploy_alert_text(string $kind, array $state): string
{
    $failure = $state['failure'] ?? [];
    $build = substr((string)($failure['sha'] ?? ''), 0, 7);
    $message = (string)($failure['message'] ?? '');
    $master = substr((string)($state['master']['sha'] ?? ''), 0, 7);
    return match ($kind) {
        'fatal' => "Выкладка pionperm.ru остановлена: сборка $build не прошла проверку — $message. Сайт работает на прежней сборке.",
        'transient' => "Выкладка pionperm.ru: больше часа не получается выложить новую сборку — $message.",
        'lag' => "Выкладка pionperm.ru: коммит $master в master больше 90 минут не превращается в сборку. Проверьте GitHub Actions.",
        default => "Выкладка pionperm.ru: $kind",
    };
}

// --- Скачанные сборки --------------------------------------------------------

function deploy_release_dir(string $home, string $sha): string
{
    return $home . '/releases/' . $sha;
}

/**
 * Файлы, которые разложила сборка. null — неизвестно (ещё не выкладывалась
 * или выложена до новой схемы): тогда удалять нечего.
 *
 * @return list<string>|null
 */
function deploy_release_files(string $home, ?string $sha): ?array
{
    if ($sha === null) {
        return null;
    }
    $raw = @file_get_contents(deploy_release_dir($home, $sha) . '/files.txt');
    if (!is_string($raw)) {
        return null;
    }
    return array_values(array_filter(
        explode("\n", str_replace("\r", '', $raw)),
        static fn(string $line): bool => $line !== '',
    ));
}

/** @param list<string> $files */
function deploy_save_release_files(string $home, string $sha, array $files): void
{
    if (file_put_contents(deploy_release_dir($home, $sha) . '/files.txt', implode("\n", $files) . "\n") === false) {
        throw new RuntimeException('не записать список файлов сборки');
    }
}

/**
 * Удаляет скачанные сборки, кроме текущей и прошлых для отката, и
 * недокачанные (.part).
 *
 * @return list<string> имена удалённых папок
 */
function deploy_prune_releases(string $home, array $state): array
{
    $keep = array_column(array_filter([$state['current'], ...$state['history']]), 'sha');
    $removed = [];
    foreach (glob($home . '/releases/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!in_array(basename($dir), $keep, true)) {
            deploy_rmtree($dir);
            $removed[] = basename($dir);
        }
    }
    sort($removed, SORT_STRING);
    return $removed;
}
```

- [ ] **Step 4: Убедиться, что проверки проходят**

Run: `/c/php82/php.exe tests/php/run.php; echo "exit=$?"`
Expected: три файла проверок, `не прошло: 0`, `exit=0`.

- [ ] **Step 5: Написать `server-pay/deploy.php`**

```php
<?php
/**
 * Выкладка сборки сайта с GitHub на этот сервер.
 *
 * Сборку делает GitHub Actions (.github/workflows/deploy.yml) на каждый
 * коммит в master и кладёт в ветку server-build три файла: pion-site.tar.gz,
 * pion-pay.tar.gz и build-info.json с их sha256. Расписание ISPmanager
 * запускает этот файл раз в 15 минут.
 *
 *   php deploy.php               есть новая сборка — проверить и выложить
 *   php deploy.php --dry-run     скачать и проверить, ничего не меняя
 *   php deploy.php --rollback    вернуть предыдущую сборку
 *   php deploy.php --status      что выложено и были ли сбои
 *   php deploy.php --find-chat   id чатов, писавших боту, — для DEPLOY_ALERT_CHAT_ID
 *   php deploy.php --test-alert  проверочное сообщение в служебный чат
 *
 * Всё, что не ходит в сеть, — в deploy-lib.php и проверено в tests/php/.
 * Подробности — docs/deploy.md в репозитории.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/deploy-lib.php';
// Конфиг нужен только ради Telegram: без него выкладка всё равно работает.
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

date_default_timezone_set('Asia/Yekaterinburg');

const DEPLOY_REPO = 'kidw3st/pion';
const DEPLOY_BRANCH = 'server-build';
const DEPLOY_ARCHIVES = ['site' => 'pion-site.tar.gz', 'pay' => 'pion-pay.tar.gz'];

/** Сервер ответил «нет такого файла» — в отличие от сетевого сбоя, сам не пройдёт. */
final class DeployNotFound extends RuntimeException
{
}

/**
 * GET по HTTPS. В тексте ошибки только хост: в адресе Telegram есть токен.
 * С $saveTo ответ пишется прямо в файл — архив сайта весит мегабайты.
 */
function deploy_http(string $url, ?string $saveTo = null, int $timeout = 60): string
{
    $options = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'pion-deploy',
    ];
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if ($fh === false) {
            throw new RuntimeException("не открыть $saveTo");
        }
        $options[CURLOPT_FILE] = $fh;
    } else {
        $options[CURLOPT_RETURNTRANSFER] = true;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($fh !== null) {
        fclose($fh);
    }
    if ($body === false || $code !== 200) {
        if ($saveTo !== null) {
            @unlink($saveTo);
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $why = $error !== '' ? $error : "код $code";
        if ($code === 404) {
            throw new DeployNotFound("$host: нет файла ($why)");
        }
        throw new RuntimeException("$host не ответил: $why");
    }
    return $saveTo === null ? (string)$body : '';
}

/** Строка в pion-deploy/deploy.log и на экран. Лог больше 512 КБ уходит в deploy.log.1. */
function deploy_log(string $home, string $message): void
{
    $log = $home . '/deploy.log';
    if (is_file($log) && filesize($log) > 512 * 1024) {
        @rename($log, $log . '.1');
    }
    $line = date('Y-m-d H:i:s') . ' | ' . $message;
    @file_put_contents($log, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $line, PHP_EOL;
}

/** Сообщение в служебный чат. Не в чат заказов: салону это ни к чему. */
function deploy_telegram(string $text): string
{
    $token = defined('TELEGRAM_TOKEN') ? (string)TELEGRAM_TOKEN : '';
    $chat = defined('DEPLOY_ALERT_CHAT_ID') ? (string)DEPLOY_ALERT_CHAT_ID : '';
    if ($token === '' || $chat === '') {
        return 'служебный чат не настроен (DEPLOY_ALERT_CHAT_ID в config.php)';
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chat,
            'text' => mb_substr($text, 0, 4000),
            'disable_web_page_preview' => 'true',
        ]),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code === 200 && is_string($raw) && str_contains($raw, '"ok":true') ? 'отправлено' : "Telegram ответил $code";
}

/** Отправляет то, о чём пора написать, и сохраняет состояние. */
function deploy_finish(string $home, array $state, int $now): void
{
    foreach (deploy_pending_alerts($state, $now) as $kind) {
        deploy_log($home, "сообщение о сбое ($kind): " . deploy_telegram(deploy_alert_text($kind, $state)));
        $state = deploy_mark_alerted($state, $kind, $now);
    }
    deploy_state_save($home, $state);
}

/**
 * Скачивает сборку в releases/<sha>/ и сверяет sha256 с build-info.json.
 * Уже скачанная и проверенная сборка берётся из папки.
 *
 * @return array{sha:string,commit:string,paySha256:string}
 */
function deploy_fetch_release(string $home, string $sha): array
{
    $dir = deploy_release_dir($home, $sha);
    if (!is_file($dir . '/build-info.json')) {
        $part = $dir . '.part';
        deploy_rmtree($part);
        if (!mkdir($part, 0755, true)) {
            throw new RuntimeException("не создать $part");
        }
        $base = 'https://raw.githubusercontent.com/' . DEPLOY_REPO . '/' . $sha . '/';
        try {
            foreach (['build-info.json', ...array_values(DEPLOY_ARCHIVES)] as $name) {
                deploy_http($base . $name, $part . '/' . $name, 600);
            }
        } catch (DeployNotFound $e) {
            throw new DeployFatal('в ветке ' . DEPLOY_BRANCH . ' не хватает файлов — ' . $e->getMessage());
        }
        $info = json_decode((string)file_get_contents($part . '/build-info.json'), true);
        if (!is_array($info)) {
            throw new DeployFatal('build-info.json не читается');
        }
        $errors = deploy_check_archives($info, [
            'site' => $part . '/' . DEPLOY_ARCHIVES['site'],
            'pay' => $part . '/' . DEPLOY_ARCHIVES['pay'],
        ]);
        if ($errors !== []) {
            // Скачано не то, что собрал GitHub, — скорее всего, оборвалась
            // загрузка. Через 15 минут сервер скачает заново.
            throw new RuntimeException(implode('; ', $errors));
        }
        if (!rename($part, $dir)) {
            throw new RuntimeException("не переименовать $part");
        }
    }
    $info = json_decode((string)file_get_contents($dir . '/build-info.json'), true);
    return [
        'sha' => $sha,
        'commit' => (string)($info['commit'] ?? ''),
        'paySha256' => (string)($info['pay']['sha256'] ?? ''),
    ];
}

/** @return array{site:string,pay:string} */
function deploy_release_archives(string $home, string $sha): array
{
    $dir = deploy_release_dir($home, $sha);
    return ['site' => $dir . '/' . DEPLOY_ARCHIVES['site'], 'pay' => $dir . '/' . DEPLOY_ARCHIVES['pay']];
}

function deploy_run(string $home, string $webroot, bool $dryRun): int
{
    $now = time();
    $state = deploy_state_load($home);

    try {
        $refs = deploy_parse_refs(deploy_http('https://github.com/' . DEPLOY_REPO . '.git/info/refs?service=git-upload-pack'));
        if (!isset($refs[DEPLOY_BRANCH])) {
            throw new RuntimeException('на GitHub нет ветки ' . DEPLOY_BRANCH);
        }
    } catch (RuntimeException $e) {
        deploy_log($home, 'не узнать, есть ли новая сборка: ' . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'transient', $e->getMessage(), null, $now), $now);
        }
        return 1;
    }
    $sha = $refs[DEPLOY_BRANCH];
    if (isset($refs['master'])) {
        $state = deploy_note_master($state, $refs['master'], $now);
    }

    if (!$dryRun && ($sha === ($state['current']['sha'] ?? null) || isset($state['bad'][$sha]))) {
        deploy_finish($home, deploy_state_idle($state), $now);
        return 0;
    }

    $short = substr($sha, 0, 7);
    try {
        $release = deploy_fetch_release($home, $sha);
        $updatePay = $release['paySha256'] !== ($state['current']['paySha256'] ?? null);
        $report = deploy_apply(
            deploy_release_archives($home, $sha),
            deploy_release_files($home, $state['current']['sha'] ?? null),
            $webroot,
            $updatePay,
            $home . '/work',
            $dryRun,
        );
    } catch (DeployFatal $e) {
        deploy_log($home, "сборка $short отклонена: " . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'fatal', $e->getMessage(), $sha, $now), $now);
        }
        return 1;
    } catch (RuntimeException $e) {
        deploy_log($home, "сборка $short не выложена: " . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'transient', $e->getMessage(), $sha, $now), $now);
        }
        return 1;
    }

    $summary = sprintf(
        'сборка %s (коммит %s): файлов %d, удалено %d, /pay/ %s',
        $short,
        substr($release['commit'], 0, 7),
        count($report['files']),
        count($dryRun ? $report['stale'] : $report['deleted']),
        $updatePay ? 'обновлён' : 'без изменений',
    );
    if ($dryRun) {
        echo 'Пробный прогон, ничего не изменено. Выкладка дала бы: ', $summary, PHP_EOL;
        foreach (array_slice($report['stale'], 0, 50) as $path) {
            echo '  удалить: ', $path, PHP_EOL;
        }
        return 0;
    }

    deploy_save_release_files($home, $sha, $report['files']);
    $state = deploy_state_after_success($state, $release, $now);
    foreach (deploy_prune_releases($home, $state) as $old) {
        deploy_log($home, 'удалена старая сборка ' . substr($old, 0, 7));
    }
    deploy_log($home, 'выложена ' . $summary);
    deploy_finish($home, $state, $now);
    return 0;
}

function deploy_rollback(string $home, string $webroot): int
{
    $state = deploy_state_load($home);
    $previous = $state['history'][0] ?? null;
    if ($state['current'] === null || $previous === null) {
        echo 'Откатываться не на что: прошлой сборки нет.', PHP_EOL;
        return 1;
    }
    try {
        $report = deploy_apply(
            deploy_release_archives($home, $previous['sha']),
            deploy_release_files($home, $state['current']['sha']),
            $webroot,
            $previous['paySha256'] !== $state['current']['paySha256'],
            $home . '/work',
            false,
        );
    } catch (RuntimeException $e) {
        echo 'Откат не удался, сайт не менялся: ', $e->getMessage(), PHP_EOL;
        return 1;
    }
    deploy_save_release_files($home, $previous['sha'], $report['files']);
    $bad = substr($state['current']['sha'], 0, 7);
    deploy_state_save($home, deploy_state_after_rollback($state, time()));
    deploy_log($home, sprintf(
        'откат: сборка %s помечена плохой, выложена %s (коммит %s), удалено файлов %d',
        $bad,
        substr($previous['sha'], 0, 7),
        substr($previous['commit'], 0, 7),
        count($report['deleted']),
    ));
    return 0;
}

function deploy_status(string $home): int
{
    $state = deploy_state_load($home);
    $current = $state['current'];
    if ($current === null) {
        echo 'Ещё ничего не выкладывалось.', PHP_EOL;
    } else {
        printf(
            'Выложена сборка %s (коммит %s) %s.%s',
            substr($current['sha'], 0, 7),
            substr($current['commit'], 0, 7),
            date('d.m.Y H:i', $current['deployedAt']),
            PHP_EOL,
        );
    }
    if ($state['history'] !== []) {
        echo 'Для отката хранятся: ',
            implode(', ', array_map(static fn(array $r): string => substr($r['sha'], 0, 7), $state['history'])),
            PHP_EOL;
    }
    foreach ($state['bad'] as $sha => $why) {
        printf('Не выкладывать %s: %s%s', substr((string)$sha, 0, 7), $why, PHP_EOL);
    }
    $failure = $state['failure'];
    if (is_array($failure)) {
        printf(
            'Сбой (%s) с %s: %s%s',
            $failure['kind'] === 'fatal' ? 'сборка испорчена' : 'временный',
            date('d.m.Y H:i', $failure['since']),
            $failure['message'],
            PHP_EOL,
        );
    }
    return 0;
}

/** id чатов, которые писали боту за последние сутки: так узнаётся DEPLOY_ALERT_CHAT_ID. */
function deploy_find_chat(): int
{
    $token = defined('TELEGRAM_TOKEN') ? (string)TELEGRAM_TOKEN : '';
    if ($token === '') {
        echo 'В config.php нет TELEGRAM_TOKEN.', PHP_EOL;
        return 1;
    }
    try {
        $updates = json_decode(deploy_http('https://api.telegram.org/bot' . $token . '/getUpdates', null, 20), true);
    } catch (RuntimeException $e) {
        echo 'Telegram: ', $e->getMessage(), PHP_EOL;
        return 1;
    }
    $chats = [];
    foreach (($updates['result'] ?? []) as $update) {
        $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;
        if (is_array($chat)) {
            $chats[(string)$chat['id']] = $chat['title']
                ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
        }
    }
    if ($chats === []) {
        echo 'Боту никто не писал за последние сутки. Напишите ему что-нибудь и запустите снова.', PHP_EOL;
        return 1;
    }
    foreach ($chats as $id => $name) {
        echo $id, ' — ', $name, PHP_EOL;
    }
    return 0;
}

function deploy_usage(): int
{
    echo 'Режимы: без аргумента, --dry-run, --rollback, --status, --find-chat, --test-alert.', PHP_EOL;
    return 2;
}

$webroot = rtrim(getenv('PION_DEPLOY_WEBROOT') ?: dirname(__DIR__), '/');
$home = rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/');
if (!is_dir($home) && !mkdir($home, 0700, true) && !is_dir($home)) {
    fwrite(STDERR, "Не создать $home" . PHP_EOL);
    exit(2);
}

$mode = $argv[1] ?? '';
if ($mode === '--status') {
    exit(deploy_status($home));
}
if ($mode === '--find-chat') {
    exit(deploy_find_chat());
}
if ($mode === '--test-alert') {
    echo deploy_telegram('Проверка: сообщения о выкладке pionperm.ru приходят сюда.'), PHP_EOL;
    exit(0);
}

// Выкладка и откат — по одному: расписание не должно запустить вторую
// выкладку, пока первая ещё копирует файлы.
$lock = fopen($home . '/deploy.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Другая выкладка ещё идёт.', PHP_EOL;
    exit(0);
}

exit(match ($mode) {
    '' => deploy_run($home, $webroot, false),
    '--dry-run' => deploy_run($home, $webroot, true),
    '--rollback' => deploy_rollback($home, $webroot),
    default => deploy_usage(),
});
```

- [ ] **Step 6: Закрыть файлы выкладки от браузера и описать новую константу**

В `server-pay/.htaccess` после блока `<Files "sync-showcase.php"> … </Files>` добавить:

```apache
<Files "deploy.php">
  Require all denied
</Files>
<Files "deploy-lib.php">
  Require all denied
</Files>
```

В `server-pay/config.example.php` сразу после строки `const TELEGRAM_CHAT_ID = '';` добавить:

```php

// Служебный чат для сообщений о сбоях выкладки (deploy.php). Не чат заказов:
// салону эти сообщения ни к чему. id подскажет `php deploy.php --find-chat`
// после того, как разработчик напишет боту. Пусто — сбои пишутся только в лог.
const DEPLOY_ALERT_CHAT_ID = '';
```

- [ ] **Step 7: Проверить синтаксис и запуск без сети**

```bash
for f in server-pay/deploy.php server-pay/deploy-lib.php server-pay/config.example.php; do /c/php82/php.exe -l "$f"; done
T=$(cygpath -m "$(mktemp -d)") && mkdir -p "$T/www/pionperm.ru" && PION_DEPLOY_WEBROOT="$T/www/pionperm.ru" PION_DEPLOY_HOME="$T/pion-deploy" /c/php82/php.exe server-pay/deploy.php --status; echo "exit=$?"
PION_DEPLOY_WEBROOT="$T/www/pionperm.ru" PION_DEPLOY_HOME="$T/pion-deploy" /c/php82/php.exe server-pay/deploy.php --what; echo "exit=$?"
/c/php82/php.exe tests/php/run.php; echo "exit=$?"
```

Expected: трижды `No syntax errors detected`; `Ещё ничего не выкладывалось.` и `exit=0`; строка `Режимы: …` и `exit=2`; проверки — `не прошло: 0`, `exit=0`.

- [ ] **Step 8: Commit**

```bash
git add server-pay/deploy-lib.php server-pay/deploy.php server-pay/.htaccess server-pay/config.example.php tests/php/deploy_state_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat: deploy.php — выкладка по расписанию, откат, сторож в служебный чат" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
```

---

### Task 5: Сборка на GitHub Actions

**Files:**
- Create: `scripts/pack-build.mjs`
- Create: `scripts/pack-build.test.mjs`
- Create: `.github/workflows/deploy.yml`
- Modify: `.github/workflows/ci.yml` (триггеры и комментарий)
- Modify: `vitest.config.ts` (`include`)
- Modify: `package.json` (команда `pack`)

**Interfaces:**
- Consumes: `npm run build` (готовая папка `out/`), `server-pay/`; на сервере архивы читает `deploy.php` из задачи 4.
- Produces: в ветке `server-build` три файла: `pion-site.tar.gz` (`out/` без `images/catalog/`), `pion-pay.tar.gz` (`server-pay/` без `config.php`), `build-info.json`:

```json
{
  "commit": "<SHA коммита в master>",
  "builtAt": "2026-10-05T10:00:00.000Z",
  "site": { "file": "pion-site.tar.gz", "bytes": 15999863, "sha256": "<64 hex>" },
  "pay": { "file": "pion-pay.tar.gz", "bytes": 25389, "sha256": "<64 hex>" }
}
```

  В `scripts/pack-build.mjs` экспортируются `REQUIRED`, `listArchive(file): string[]`, `pack({ outDir, payDir, dest, commit, now? }): BuildInfo`.

- [ ] **Step 1: Добавить тесты из `scripts/` в vitest**

В `vitest.config.ts` строку `include: ['src/**/*.test.ts'],` заменить на:

```ts
    include: ['src/**/*.test.ts', 'scripts/**/*.test.mjs'],
```

- [ ] **Step 2: Написать тест `scripts/pack-build.test.mjs`**

```js
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { listArchive, pack } from './pack-build.mjs';

/** Временная папка с файлами: путь => содержимое. */
function tree(files) {
  const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-pack-'));
  for (const [rel, body] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(dir, rel)), { recursive: true });
    writeFileSync(path.join(dir, rel), body);
  }
  return dir;
}

const SITE = {
  'index.html': 'home',
  '404.html': '404',
  '.htaccess': 'rules',
  'sitemap.xml': '<urlset/>',
  'robots.txt': 'User-agent: *',
  'images/site/logo.webp': 'logo',
  'images/catalog/bukety/a.webp': 'photo',
  'bukety/index.html': 'section',
};

describe('pack', () => {
  it('кладёт сайт без фото каталога, /pay/ без config.php и sha256 обоих', () => {
    const dest = path.join(tree({}), 'build');
    const info = pack({
      outDir: tree(SITE),
      payDir: tree({ 'init.php': '<?php', 'config.php': '<?php // secret', '.htaccess': 'deny' }),
      dest,
      commit: 'abc1234def',
      now: new Date('2026-10-05T10:00:00Z'),
    });

    const site = listArchive(path.join(dest, 'pion-site.tar.gz'));
    expect(site).toContain('bukety/index.html');
    expect(site).toContain('images/site/logo.webp');
    expect(site.filter((f) => f.startsWith('images/catalog'))).toEqual([]);
    expect(listArchive(path.join(dest, 'pion-pay.tar.gz')).sort()).toEqual(['.htaccess', 'init.php']);

    expect(JSON.parse(readFileSync(path.join(dest, 'build-info.json'), 'utf8'))).toEqual(info);
    expect(info.commit).toBe('abc1234def');
    expect(info.builtAt).toBe('2026-10-05T10:00:00.000Z');
    const sha = createHash('sha256').update(readFileSync(path.join(dest, 'pion-site.tar.gz'))).digest('hex');
    expect(info.site.sha256).toBe(sha);
    expect(info.site.file).toBe('pion-site.tar.gz');
  });

  it('не пакует сборку без обязательных файлов', () => {
    const { 'robots.txt': _robots, ...site } = SITE;
    expect(() =>
      pack({
        outDir: tree(site),
        payDir: tree({ 'init.php': '<?php' }),
        dest: path.join(tree({}), 'build'),
        commit: 'x',
      }),
    ).toThrow(/robots\.txt/);
  });
});
```

- [ ] **Step 3: Убедиться, что тест падает**

Run: `npx vitest run scripts/pack-build.test.mjs`
Expected: FAIL — `Failed to load url ./pack-build.mjs` (файла ещё нет).

- [ ] **Step 4: Написать `scripts/pack-build.mjs`**

```js
/**
 * Упаковывает готовую сборку для сервера: архив сайта, архив /pay/ и
 * build-info.json с sha256 обоих. По нему deploy.php на сервере проверяет,
 * что скачал ровно то, что собрал GitHub.
 *
 *   npm run build && npm run pack     # кладёт всё в build/
 *
 * Фото каталога в архив сайта не входят: ими владеет сервер (их загружает
 * админка), и deploy.php не выложит сборку, которая лезет в images/catalog/.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Без этих файлов deploy.php сборку не примет — проверяем уже здесь. */
export const REQUIRED = ['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt'];

// На Windows — встроенный bsdtar по полному пути: tar из Git Bash принял бы
// «C:» в пути к архиву за имя удалённого сервера.
const TAR =
  process.platform === 'win32'
    ? path.join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', 'tar.exe')
    : 'tar';

/** Файлы архива: пути без «./», без папок. */
export function listArchive(file) {
  return execFileSync(TAR, ['-tzf', file], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
    .split(/\r?\n/)
    .map((line) => line.replace(/^\.\//, ''))
    .filter((line) => line !== '' && line !== '.' && !line.endsWith('/'));
}

function describeArchive(file) {
  return {
    file: path.basename(file),
    bytes: statSync(file).size,
    sha256: createHash('sha256').update(readFileSync(file)).digest('hex'),
  };
}

export function pack({ outDir, payDir, dest, commit, now = new Date() }) {
  rmSync(dest, { recursive: true, force: true });
  mkdirSync(dest, { recursive: true });
  const site = path.join(dest, 'pion-site.tar.gz');
  const pay = path.join(dest, 'pion-pay.tar.gz');

  execFileSync(TAR, ['-czf', site, '-C', outDir, '--exclude', './images/catalog', '.']);
  execFileSync(TAR, ['-czf', pay, '-C', payDir, '--exclude', './config.php', '.']);

  const siteFiles = listArchive(site);
  const leaked = siteFiles.filter((f) => f.startsWith('images/catalog/'));
  if (leaked.length > 0) {
    throw new Error(`в архив сайта попали фото каталога: ${leaked.slice(0, 3).join(', ')}`);
  }
  const missing = REQUIRED.filter((f) => !siteFiles.includes(f));
  if (missing.length > 0) {
    throw new Error(`в сборке нет ${missing.join(', ')} — сначала npm run build`);
  }
  if (listArchive(pay).includes('config.php')) {
    throw new Error('в платёжный архив попал config.php');
  }

  const info = { commit, builtAt: now.toISOString(), site: describeArchive(site), pay: describeArchive(pay) };
  writeFileSync(path.join(dest, 'build-info.json'), JSON.stringify(info, null, 2) + '\n');
  return info;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const commit =
    process.env.GITHUB_SHA || execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const info = pack({
    outDir: path.join(root, 'out'),
    payDir: path.join(root, 'server-pay'),
    dest: path.join(root, 'build'),
    commit,
  });
  console.log(
    `[pack] build/: сайт ${(info.site.bytes / 1048576).toFixed(1)} МБ, /pay/ ${Math.round(info.pay.bytes / 1024)} КБ, коммит ${commit.slice(0, 7)}`,
  );
}
```

В `package.json`, в `scripts`, после `"test:php"` добавить:

```json
    "pack": "node scripts/pack-build.mjs",
```

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `npx vitest run scripts/pack-build.test.mjs && npm test`
Expected: 2 passed в `pack-build.test.mjs`; весь `npm test` зелёный.

- [ ] **Step 6: Написать `.github/workflows/deploy.yml`**

```yaml
name: Сборка и выкладка

# Каждый коммит в master собирается здесь и кладётся в ветку server-build:
# pion-site.tar.gz, pion-pay.tar.gz и build-info.json с их sha256. Сервер
# (pay/deploy.php по расписанию раз в 15 минут) сам забирает новую сборку.
# master — это то, что на сайте: незаконченная работа живёт в ветках.
#
# Публикации на GitHub Pages здесь нет и не будет: копия на github.io уже
# отбирала у pionperm.ru позиции в поиске. Прав pages у workflow нет.

on:
  push:
    branches: [master]
  workflow_dispatch:

permissions:
  contents: write

# Два коммита подряд — собирается последний: в нём есть всё.
concurrency:
  group: deploy
  cancel-in-progress: true

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      # PHP — та же версия, что на хостинге.
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none

      - run: npm ci
      - run: npm test
      - run: find server-pay wp-theme -name '*.php' -print0 | xargs -0 -n1 php -l
      - run: php tests/php/run.php

      # Боевая сборка. postbuild проверяет, что canonical, og, Schema и
      # sitemap указывают на pionperm.ru, и роняет сборку, если нет.
      - run: npm run build
        env:
          SITE_URL: https://pionperm.ru

      - run: node scripts/pack-build.mjs

      # Ветка хранит только последнюю сборку: история архивов на мегабайты
      # в git никому не нужна.
      - name: Выложить в server-build
        working-directory: build
        env:
          TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          git init -q -b server-build
          git add pion-site.tar.gz pion-pay.tar.gz build-info.json
          git -c user.name='github-actions[bot]' \
              -c user.email='41898282+github-actions[bot]@users.noreply.github.com' \
              commit -q -m "Сборка ${GITHUB_SHA::7}"
          git push -q --force "https://x-access-token:${TOKEN}@github.com/${GITHUB_REPOSITORY}.git" server-build
```

- [ ] **Step 7: Перевести `ci.yml` на ветки и pull request**

В `.github/workflows/ci.yml` заменить блок

```yaml
on:
  push:
    branches: [master]
  pull_request:
  workflow_dispatch:
```

на

```yaml
# master проверяет и выкладывает deploy.yml — здесь ветки и pull request.
on:
  push:
    branches-ignore: [master, server-build]
  pull_request:
  workflow_dispatch:
```

- [ ] **Step 8: Commit**

```bash
git add scripts/pack-build.mjs scripts/pack-build.test.mjs .github/workflows/deploy.yml .github/workflows/ci.yml vitest.config.ts package.json
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat: сборка master на GitHub Actions и выкладка архивов в server-build" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
```

- [ ] **Step 9: Проверить первую сборку на GitHub**

Через 5–7 минут после push:

```bash
curl -s "https://api.github.com/repos/kidw3st/pion/actions/workflows/deploy.yml/runs?per_page=1" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{const r=JSON.parse(s).workflow_runs[0];console.log(r.head_sha.slice(0,7),r.status,r.conclusion,r.html_url)})"
```

Expected: SHA последнего коммита, `completed success`. Пока `in_progress` — подождать и повторить, не чаще раза в минуту.

```bash
SHA=$(git ls-remote origin refs/heads/server-build | cut -f1) && curl -s "https://raw.githubusercontent.com/kidw3st/pion/$SHA/build-info.json"
git rev-parse HEAD
```

Expected: `build-info.json` с `commit`, равным `git rev-parse HEAD`; `site.bytes` около 16 МБ; у `site` и `pay` есть `sha256`.

---

### Task 6: Запуск на сервере

**Files:**
- Create: `docs/deploy.md`
- Сервер (через Shell-клиент и Планировщик CRON в ISPmanager): `pay/` из новой сборки, `/var/www/u3620798/data/pion-deploy/`, `config.php`, расписание.

**Interfaces:**
- Consumes: ветка `server-build` с `build-info.json` (задача 5), `deploy.php` (задача 4).

Это работа на боевом сервере. Перед шагом 3 (первое изменение `/pay/`), шагом 5 (первая настоящая выкладка) и шагом 6 (расписание) — подтверждение владельца в чате. Команды в Shell-клиент набирает агент; если там появится пароль или токен, вкладку не скриншотить, пока владелец не очистит экран.

- [ ] **Step 1: GitHub Pages выключены**

```bash
curl -s https://api.github.com/repos/kidw3st/pion | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>console.log('has_pages:',JSON.parse(s).has_pages))"
curl -s -o /dev/null -w "github.io: %{http_code}\n" https://kidw3st.github.io/pion/
```

Expected: `has_pages: false`, `github.io: 404`. Если `has_pages: true`, попросить владельца в github.com → kidw3st/pion → Settings → Pages отключить публикацию («Unpublish site» или источник «None»). Это его настройки, сам не меняю. Потом повторить проверку.

- [ ] **Step 2: Убедиться, что в server-build новая сборка (Shell-клиент)**

```bash
cd /var/www/u3620798/data && SHA=$(git ls-remote https://github.com/kidw3st/pion.git refs/heads/server-build | cut -f1) && echo "$SHA" && curl -fsSL "https://raw.githubusercontent.com/kidw3st/pion/$SHA/build-info.json" | head -4
```

Expected: SHA и начало `build-info.json` с `commit` последнего коммита `master`.

- [ ] **Step 3: Положить новый `/pay/` с `deploy.php` (с подтверждения владельца)**

`deploy.php` приезжает в платёжном архиве, который он же раскладывает. Первый раз — вручную, с резервной копией, как раньше. Переменная `$SHA` — из шага 2; если Shell-клиент переоткрывался, сначала повторить шаг 2.

```bash
cd /var/www/u3620798/data && mkdir -p pion-deploy/bootstrap && cd pion-deploy/bootstrap && rm -rf pay && mkdir pay && curl -fsSL -o pay.tgz "https://raw.githubusercontent.com/kidw3st/pion/$SHA/pion-pay.tar.gz" && tar -xzf pay.tgz -C pay && find pay -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors'; ls pay
```

Expected: `grep` ничего не выводит (ошибок синтаксиса нет); в списке есть `deploy.php`, `deploy-lib.php`, `init.php`, `notify.php`; `config.php` нет.

```bash
cp -a /var/www/u3620798/data/www/pionperm.ru/pay /var/www/u3620798/data/pay-backup-$(date +%Y%m%d-%H%M) && cp -a pay/. /var/www/u3620798/data/www/pionperm.ru/pay/ && cd .. && rm -rf bootstrap && ls -d /var/www/u3620798/data/pay-backup-* | tail -1
```

С этого компьютера проверить, что оплата жива, а файлы выкладки закрыты:

```bash
curl -s https://pionperm.ru/pay/uds-check.php; echo; for p in deploy.php deploy-lib.php; do curl -s -o /dev/null -w "$p: %{http_code}\n" "https://pionperm.ru/pay/$p"; done
```

Expected: `{"enabled":true}`, `deploy.php: 403`, `deploy-lib.php: 403`.

- [ ] **Step 4: Пробный прогон на сервере**

```bash
php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php --status; php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php --dry-run
```

Expected: `Ещё ничего не выкладывалось.`; затем `Пробный прогон, ничего не изменено. Выкладка дала бы: сборка <7 знаков> (коммит <7 знаков>): файлов ~1245, удалено 0, /pay/ обновлён`. Удалено 0 — потому что это первая выкладка по новой схеме. Если вместо этого `сборка … отклонена` — остановиться и разобрать причину по тексту, сайт не тронут.

- [ ] **Step 5: Первая настоящая выкладка (с подтверждения владельца)**

```bash
php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php; php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php --status
```

Expected: строка `выложена сборка …`; статус `Выложена сборка <SHA> (коммит <SHA>) <дата время>.`. С этого компьютера — по одному запросу на адрес:

```bash
for u in / /bukety/ /catalog/ /blog/ /v-nalichii/ /sitemap.xml; do curl -s -o /dev/null -w "$u %{http_code}\n" "https://pionperm.ru$u"; done
```

Expected: везде `200`.

- [ ] **Step 6: Расписание (с подтверждения владельца)**

В ISPmanager → «Планировщик CRON»:
1. «Создать»: команда `php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php`, расписание — каждые 15 минут (минуты `*/15`, остальное `*`), описание «Выкладка сайта из сборки (deploy.php)». Сохранить.
2. Выделить старое задание «Обновление сайта из сборки (временное)» (`0 0 * * *`, качает `pion-site.tar.gz` по адресу ветки) → «Удалить».

Проверить в Shell-клиенте:

```bash
crontab -l | grep -v '^#' | grep -v '^$'
```

Expected: строка `*/15 * * * * php …/pay/deploy.php …` и строка синхронизации витрины `*/15 … sync-showcase.php …`; строки с `pion-site.tar.gz` нет.

- [ ] **Step 7: Служебный чат для сообщений о сбоях**

1. Попросить владельца написать любое сообщение боту салона в Telegram — с того аккаунта, куда должны приходить сообщения о сбоях.
2. В Shell-клиенте:

```bash
php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php --find-chat
```

Expected: строки вида `123456789 — Имя`. Выбрать id владельца.

3. Дописать константу в боевой `config.php` с резервной копией и проверкой. Содержимое `config.php` на экран не выводить: там ключи.

```bash
cd /var/www/u3620798/data/www/pionperm.ru/pay && cp config.php ~/config-backup-$(date +%Y%m%d-%H%M).php && tail -c 3 config.php | od -c | head -1
```

Expected: последние байты — не `?>` (закрывающего тега нет, можно дописывать в конец). Затем, подставив id:

```bash
printf "\n// Служебный чат для сообщений о сбоях выкладки (deploy.php).\nconst DEPLOY_ALERT_CHAT_ID = '%s';\n" 'ID_ИЗ_ШАГА_2' >> config.php && php -l config.php && grep -c "DEPLOY_ALERT_CHAT_ID" config.php && php deploy.php --test-alert
```

Expected: `No syntax errors detected`, `1`, `отправлено`. У владельца в Telegram — «Проверка: сообщения о выкладке pionperm.ru приходят сюда.»

- [ ] **Step 8: Написать `docs/deploy.md`**

```markdown
# Выкладка pionperm.ru

С октября 2026 сайт выкладывается сам. Вручную собирать и заливать архивы
больше не нужно и нельзя: ручная сборка без `build-info.json` будет отклонена.

## Как это устроено

1. Коммит в `master` запускает `.github/workflows/deploy.yml`: тесты (vitest и
   PHP), `next build`, проверка адресов, упаковка (`scripts/pack-build.mjs`) и
   проверка упакованной сборки теми же правилами, что на сервере
   (`scripts/check-server-build.php`). Не прошла — на сервер ничего не уходит,
   а запуск в GitHub Actions красный.
2. В ветку `server-build` кладутся `pion-site.tar.gz` (сайт без
   `images/catalog/`), `pion-pay.tar.gz` (`server-pay/` без `config.php`) и
   `build-info.json` с sha256 обоих. Архивы воспроизводимые: при том же
   содержимом sha256 тот же.
3. На сервере расписание раз в 15 минут запускает `pay/deploy.php`. Он
   узнаёт SHA ветки, скачивает файлы по SHA, сверяет sha256, проверяет состав
   и `php -l`, раскладывает сайт и удаляет файлы прошлой сборки, которых нет
   в новой. `/pay/` обновляется, только если его архив изменился.

От коммита до сайта — обычно 5–20 минут.

`master` — это то, что на сайте. Незаконченная работа — в отдельных ветках:
там её проверяет `ci.yml`, но никуда не выкладывает.

## Что выкладка не трогает никогда

`pay/config.php` и вообще `pay/` (кроме раскладки платёжного архива),
`blog/` (кроме нашей темы `blog/wp-content/themes/pion/`), `images/catalog/`,
`images/showcase/`, `api/showcase.json`, `.well-known/` (кроме
`.well-known/agent-skills/` — эти файлы для ИИ-ассистентов кладёт сборка).
Списки — `DEPLOY_PROTECTED` и `DEPLOY_OWNED_IN_PROTECTED` в
`server-pay/deploy-lib.php`.

## Команды на сервере (ISPmanager → Shell-клиент)

    cd /var/www/u3620798/data/www/pionperm.ru/pay
    php deploy.php --status      что выложено, что хранится для отката, сбои
    php deploy.php --dry-run     скачать и проверить новую сборку, ничего не меняя
    php deploy.php --rollback    вернуть предыдущую сборку
    php deploy.php --test-alert  проверить служебный чат

Служебные файлы — в `/var/www/u3620798/data/pion-deploy/`: `state.json`
(состояние), `deploy.log` (журнал), `releases/` (три последние сборки).

## Если что-то пошло не так

- **Сборка на GitHub упала** (красный запуск в Actions, письмо от GitHub) —
  сайт не менялся. Исправить код и закоммитить снова.
- **Сообщение «сборка … отклонена»** — сервер её не выложил: не хватает
  файлов, ошибка синтаксиса PHP или файлы в чужих путях. Причина — в тексте
  и в `deploy.log`. Сайт работает на прежней сборке; следующий коммит
  выложится как обычно.
- **Выложилось, но сломалось** — `php deploy.php --rollback`. Сборка
  помечается плохой и больше не ставится. Чтобы выложить исправление —
  новый коммит в `master`.
- **Пересобрать без изменений кода** — GitHub → Actions → «Сборка и выкладка»
  → «Run workflow».
- **«больше часа не получается выложить новую сборку»** — недоступен GitHub,
  сеть хостинга, или на сервере не запустились tar либо php -l (причина — в
  конце сообщения). Обычно проходит само; сервер пробует каждые 15 минут.
- **«коммит … больше 90 минут не превращается в сборку»** — проверить
  GitHub Actions: запуск упал или не начался.

Сообщения о сбоях приходят в служебный чат `DEPLOY_ALERT_CHAT_ID` из
`config.php` — не в чат заказов салона.
```

- [ ] **Step 9: Commit — это же сквозная проверка**

```bash
git add docs/deploy.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "docs: как устроена автовыкладка и что делать при сбое" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push origin master
git rev-parse --short HEAD
```

Этот коммит проходит весь путь сам. Через 5–7 минут проверить сборку, как в задаче 5, шаг 9. Затем не дольше 15 минут подождать расписание и в Shell-клиенте выполнить:

```bash
php /var/www/u3620798/data/www/pionperm.ru/pay/deploy.php --status; tail -5 /var/www/u3620798/data/pion-deploy/deploy.log
```

Expected: `Выложена сборка … (коммит <короткий SHA из git rev-parse>)`; в журнале строка `выложена сборка …` от запуска по расписанию; `Для отката хранятся: …` — одна прошлая сборка.

- [ ] **Step 10: Записать итог**

Обновить заметку о выкладке в памяти агента (`pion-server-deploy`): ручной деплой через git plumbing больше не используется; выкладка — `deploy.yml` → `server-build` → `deploy.php` по расписанию; команды из `docs/deploy.md`; резервная копия `/pay/` из шага 3.
