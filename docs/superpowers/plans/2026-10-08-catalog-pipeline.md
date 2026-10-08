# Конвейер каталога — план (этап 3Б)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сборка умеет брать каталог с сервера (`/pay/catalog-export.php`) за переключателем, состояние выкладки знает выложенную версию каталога, сторож пишет в Telegram, когда изменения из админки не доходят до сайта или ночное обслуживание сбоит, уборка фото ждёт фактической выкладки, а PHP-проверки идут и на настоящей SQLite 3.26. Переключатель остаётся выключенным: сайт после слияния собирается из снимка, как сейчас; включают его на этапе 3В.

**Architecture:** Решение «собирать или нет и из чего» вынесено в проверяемый скрипт `scripts/catalog-source.mjs`; `deploy.yml` только склеивает шаги: прошлая сборка из ветки `server-build` → решение → тесты на закоммиченном снимке → подмена снимка выгрузкой с сервера → сборка. `build-info.json` несёт версию каталога, `deploy.php` переносит её в `state.json` (контракт, который читает админка), помечает откатанные пары «код + каталог» плохими и раз в 15 минут проверяет каталог (отставание выкладки, свежесть копии базы, итог обслуживания). Обслуживание пишет итог в файл и двигает фото, только когда выложенная сборка уже не ссылается на них.

**Tech Stack:** GitHub Actions, Node 20 ESM, vitest, PHP 8.2/8.3 (`tests/php/run.php`), SQLite 3.26 (сервер; контейнер `almalinux:8` в CI).

## Global Constraints

- `master` — это боевой сайт. Работа — в ветке `catalog-pipeline` (от `master`); ветку можно отправлять (Task 9 требует запуска CI на ветке), слияние — только после итоговой проверки.
- Коммиты — с `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com"` и последней строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Всё, что видит салон и владелец (сообщения в Telegram, тексты админки), — по-русски, без технических слов там, где их можно избежать; служебные сообщения сборки — по-русски.
- Никаких новых npm-зависимостей. SQL — не новее SQLite 3.26. Код `/pay/` — PHP 8.2 и 8.3.
- **Переключатель по умолчанию выключен**: пока переменная репозитория `CATALOG_SOURCE` не равна `server`, сборка по коммиту идёт из закоммиченного снимка точно как сейчас, а запуск по расписанию ничего не собирает. Слияние этого плана не меняет сайт.
- **Никогда не собирать из устаревшего снимка, когда источник — сервер**: если `CATALOG_SOURCE=server`, а выгрузка недоступна, запуск по расписанию тихо заканчивается без сборки, а запуск по коммиту — ошибкой (красный), без публикации.
- `catalogChangedAt` в `state.json` пишется ровно в виде `catalog_iso` (`Y-m-d\TH:i:sP`, как `export.changedAt`) или пустой строкой/отсутствует; админка принимает только этот вид.
- Не менять: платёжную часть (`server-pay/lib.php`, `init.php`, `notify.php`, `uds*.php`, `posiflora.php`, `sync-showcase.php`), `data/catalog/*.json`, `data/catalog-meta.json`, `data/site.json`, `data/catalog-export.json`.
- Проверки: `npm test`, `/c/php82/php.exe tests/php/run.php`, `npm run build` — без ошибок; в выводе PHP — без предупреждений.

## Не входит (этап 3В)

Раздел «Новинки» в выгрузке и его фото, загрузка каталога в базу на сервере, учётки сотрудников, задания расписания (`maintenance-cli.php`), включение `CATALOG_SOURCE=server`, удаление `EXTRA_ITEMS`, `REMOTE_ADDR`, проверка с телефона, инструкция для салона.

## Файлы

| Файл | Что меняется |
|---|---|
| `scripts/apply-catalog-export.mjs`, `scripts/lib/catalog-export.mjs` | Разбор флагов, `--previous-count`, таймаут, атомарная запись, вид ошибки |
| `scripts/pack-build.mjs` | `build-info.json` несёт каталог |
| `server-pay/deploy.php`, `server-pay/deploy-lib.php` | Каталог в `state.json`; откат помечает пару «код + каталог»; сторож каталога |
| `server-pay/admin/lib/status.php` | NUL-байт, пустая версия |
| `scripts/catalog-source.mjs` (+ тест) | Решение «собирать ли и из чего», подготовка выгрузки |
| `.github/workflows/deploy.yml`, `.github/workflows/keepalive.yml` | Расписание, переключатель, `allow_shrink`; не дать GitHub выключить расписание |
| `server-pay/catalog/maintenance.php`, `maintenance-cli.php` | Уборка ждёт выкладки; итог обслуживания в файл |
| `server-pay/admin/lib/pages-products.php`, `server-pay/catalog/products.php`, `server-pay/catalog/sections.php` | Букет в продаже без фото и раздел без фото плитки — нельзя |
| `scripts/compare-builds.mjs` (+ тест) | Заголовки, описания, canonical, h1, главная, плитки, `api/catalog`, CSV; код выхода |
| `.github/workflows/ci.yml` | PHP-проверки на SQLite 3.26 (`almalinux:8`) |
| `docs/deploy.md`, `docs/catalog.md`, `docs/known-follow-ups.md` | Как этим пользоваться |

---

### Task 1: `apply-catalog-export.mjs` — готов к выгрузке с сервера

**Files:**
- Modify: `scripts/apply-catalog-export.mjs`
- Modify: `scripts/lib/catalog-export.mjs` (текст защиты от потери)
- Modify: `scripts/apply-catalog-export.test.mjs`

**Interfaces:**
- Consumes: `validateExport`, `formatExport` (2В).
- Produces:
  - `applyCatalogExport({source, out?, allowShrink?, check?, previousCount?}): Promise<{ok, kind?, errors, products?, sections?, version?, changedAt?}>` — `kind`: `'unreachable'` (не скачать/не прочитать/не JSON), `'invalid'` (не прошла проверку), `'write'` (не записать); `previousCount` (число) — если задан, защита от потери сравнивает с ним, а не с файлом по `out`;
  - CLI: `--previous-count N`; `--out` без значения, флаг с одним дефисом, неизвестный флаг, `--check` вместе с `--out` — код 2;
  - HTTP: таймаут 30 с; не JSON — понятная ошибка;
  - запись: через `<out>.part` и переименование;
  - битый прошлый файл по `out` — предупреждение в stderr («прошлый файл … не читается — число букетов сравнить не с чем»), не молчание.

- [ ] **Step 1: Проверки (пока падают)**

Append to `scripts/apply-catalog-export.test.mjs`:

```js
describe('готовность к выгрузке с сервера', () => {
  it('--previous-count важнее файла по --out', async () => {
    const out = path.join(tmp(), 'catalog-export.json');
    const stopped = await applyCatalogExport({ source: FIXTURE, out, previousCount: 10 });
    expect(stopped).toMatchObject({ ok: false, kind: 'invalid' });
    expect(stopped.errors.join('\n')).toContain('allow_shrink');
    expect((await applyCatalogExport({ source: FIXTURE, out, previousCount: 6 })).ok).toBe(true);
  });

  it('не скачалось — вид ошибки unreachable', async () => {
    const r = await applyCatalogExport({ source: path.join(tmp(), 'missing.json'), out: path.join(tmp(), 'x.json') });
    expect(r).toMatchObject({ ok: false, kind: 'unreachable' });
  });

  it('не JSON — понятная ошибка', async () => {
    const dir = tmp();
    writeFileSync(path.join(dir, 'page.json'), '<!doctype html><title>503</title>');
    const r = await applyCatalogExport({ source: path.join(dir, 'page.json'), out: path.join(dir, 'x.json') });
    expect(r.kind).toBe('unreachable');
    expect(r.errors[0]).toContain('не JSON');
  });

  it('запись атомарная: временный файл не остаётся', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    expect((await applyCatalogExport({ source: FIXTURE, out })).ok).toBe(true);
    expect(existsSync(`${out}.part`)).toBe(false);
  });

  it('не записать — вид ошибки write', async () => {
    const r = await applyCatalogExport({ source: FIXTURE, out: path.join(tmp(), 'нет-папки', 'x.json') });
    expect(r).toMatchObject({ ok: false, kind: 'write' });
    expect(r.errors[0]).toMatch(/^Не удалось записать/);
  });

  it('битый прошлый файл — предупреждение, а не молчание', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    writeFileSync(out, '<<<<<<< HEAD');
    const r = spawnSync(process.execPath, [SCRIPT, FIXTURE, '--out', out], { encoding: 'utf8' });
    expect(r.status).toBe(0);
    expect(r.stderr).toContain('не читается');
  });

  const bad = [
    ['--out без значения', ['--check', '--out']],
    ['--out, за которым флаг', [FIXTURE, '--out', '--allow-shrink']],
    ['одиночный дефис', ['-h']],
    ['неизвестный флаг', [FIXTURE, '--force']],
    ['--check вместе с --out', ['--check', FIXTURE, '--out', 'x.json']],
    ['--previous-count не число', [FIXTURE, '--previous-count', 'много']],
  ];
  for (const [name, args] of bad) {
    it(`неверный вызов: ${name} — код 2`, () => {
      const r = spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8', cwd: tmp() });
      expect(r.status).toBe(2);
      expect(r.stderr).toContain('Как вызывать');
    });
  }
});
```

Run: `npx vitest run scripts/apply-catalog-export.test.mjs`
Expected: FAIL (нет `kind`, нет `previousCount`, неверные вызовы проходят).

- [ ] **Step 2: Реализация**

In `scripts/apply-catalog-export.mjs`:

1. Import `renameSync` from `node:fs`.
2. Replace `load` with:

```js
async function load(source) {
  if (/^https?:\/\//.test(source)) {
    // Сервер может зависнуть — сборка не должна ждать его до своего таймаута.
    const res = await fetch(source, { signal: AbortSignal.timeout(30_000), headers: { 'User-Agent': 'pion-build' } });
    if (!res.ok) throw new Error(`сервер ответил ${res.status}`);
    return parseJson(await res.text());
  }
  return parseJson(readFileSync(source, 'utf8'));
}

function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    throw new Error('прислано не JSON');
  }
}
```

3. Replace `previousCount(file)` with a function returning `{count, warning}`:

```js
/** Сколько букетов в файле, который лежит сейчас. Нет файла — сравнивать не с чем; битый — тоже, но об этом говорим. */
function previousFromFile(file) {
  if (!existsSync(file)) return { count: null, warning: null };
  try {
    const products = JSON.parse(readFileSync(file, 'utf8')).products;
    if (Array.isArray(products)) return { count: products.length, warning: null };
  } catch {
    // ниже — предупреждение
  }
  return { count: null, warning: `прошлый файл ${file} не читается — число букетов сравнить не с чем` };
}
```

4. Replace `applyCatalogExport` with:

```js
export async function applyCatalogExport({ source, out = DEFAULT_FILE, allowShrink = false, check = false, previousCount = null }) {
  let exp;
  try {
    exp = await load(source);
  } catch (error) {
    return { ok: false, kind: 'unreachable', errors: [`Не удалось прочитать выгрузку ${source}: ${error.message}`] };
  }
  let previous = { count: null, warning: null };
  if (!check) previous = previousCount !== null ? { count: previousCount, warning: null } : previousFromFile(out);
  const errors = validateExport(exp, { previousCount: previous.count, allowShrink });
  if (errors.length) return { ok: false, kind: 'invalid', errors, warning: previous.warning };
  const text = formatExport(exp);
  if (!check) {
    try {
      writeFileSync(`${out}.part`, text);
      renameSync(`${out}.part`, out);
    } catch (error) {
      return { ok: false, kind: 'write', errors: [`Не удалось записать ${out}: ${error.message}`] };
    }
  }
  const written = JSON.parse(text);
  return {
    ok: true,
    errors: [],
    warning: previous.warning,
    products: exp.products.length,
    sections: exp.sections.length,
    version: written.version,
    changedAt: written.changedAt,
  };
}
```

5. Replace the CLI argument loop with strict parsing:

```js
  const args = process.argv.slice(2);
  const positional = [];
  let out = null;
  let check = false;
  let allowShrink = false;
  let previousCount = null;
  let bad = false;
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--check') check = true;
    else if (arg === '--allow-shrink') allowShrink = true;
    else if (arg === '--out' || arg === '--previous-count') {
      const value = args[i + 1];
      if (value === undefined || value.startsWith('-')) bad = true;
      else if (arg === '--out') out = path.resolve(value);
      else if (/^\d+$/.test(value)) previousCount = Number(value);
      else bad = true;
      i++;
    } else if (arg.startsWith('-')) bad = true;
    else positional.push(arg);
  }
  if (check && out !== null) bad = true;
  const source = positional[0] ?? (check ? DEFAULT_FILE : undefined);
  if (bad || !source || positional.length > 1) {
    console.error(USAGE);
    process.exit(2);
  }
  const r = await applyCatalogExport({ source, out: out ?? DEFAULT_FILE, allowShrink, check, previousCount });
  if (r.warning) console.error(`Внимание: ${r.warning}.`);
```

(остальное — как было; `USAGE` дополнить строкой `--previous-count N — сравнить число букетов с прошлой сборкой, а не с файлом`.)

In `scripts/lib/catalog-export.mjs`, shrink message: replace `'Если салон и правда столько удалил, запустите сборку с allow_shrink.'` with `'Если салон и правда столько удалил, запустите сборку вручную с allow_shrink (в командной строке — флаг --allow-shrink).'`.

- [ ] **Step 3: Убедиться, что проходят**

Run: `npm test`
Expected: PASS (включая старые проверки `apply-catalog-export.test.mjs` и `catalog-export.test.mjs`).

- [ ] **Step 4: Коммит**

```bash
git add scripts/apply-catalog-export.mjs scripts/apply-catalog-export.test.mjs scripts/lib/catalog-export.mjs
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): apply-catalog-export готов к выгрузке с сервера — previous-count, таймаут, атомарная запись

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Каталог в `build-info.json` и в `state.json`

**Files:**
- Modify: `scripts/pack-build.mjs`, `scripts/pack-build.test.mjs`
- Modify: `server-pay/deploy.php` (`deploy_fetch_release`)
- Modify: `server-pay/admin/lib/status.php` (`admin_deployed_catalog`)
- Test: `tests/php/catalog_contract_test.php`

**Interfaces:**
- Consumes: `deploy_state_after_success`, `deploy_state_save`, `deploy_state_load` (`deploy-lib.php`); `admin_deployed_catalog` (2Б/3А); `catalog_iso` (`catalog/db.php`).
- Produces:
  - `pack({..., catalogFile})` → `info.catalog = {version, changedAt, products}`; бросает, если версия не 64 hex или `changedAt` не `null` и не вида `YYYY-MM-DDTHH:MM:SS±HH:MM`;
  - `deploy_fetch_release` возвращает также `catalogVersion` (string), `catalogChangedAt` (string, `''` если нет), `catalogProducts` (int) — только если в `build-info.json` есть `catalog`; иначе этих ключей нет;
  - `admin_deployed_catalog`: пустая версия — `null`; любая ошибка разбора даты (включая NUL-байт, `ValueError`) — `changedAt = ''`.

- [ ] **Step 1: Проверки (пока падают)**

Append to `scripts/pack-build.test.mjs` a case (по образцу соседних, которые собирают `pack()` во временную папку): `pack({..., catalogFile})` с файлом-образцом `tests/php/fixtures/catalog-export-small.json` — в `info.catalog` `{ version: '4e8ad616…' (полная), changedAt: '2026-10-05T14:32:10+05:00', products: 5 }`; с копией образца, где `changedAt: '2026-10-05T14:32:10.000Z'`, — `pack` бросает ошибку с текстом `changedAt`. (Если существующие проверки `pack` вызывают его без `catalogFile` — для них `catalog` не пишется; это тоже проверить.)

Create `tests/php/catalog_contract_test.php`:

```php
<?php
/**
 * Контракт между выкладкой и админкой: deploy.php пишет выложенную версию
 * каталога в state.json, админка читает её оттуда — в том же виде.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/deploy-lib.php';
require_once __DIR__ . '/../../server-pay/admin/lib/status.php';

t_case('state.json: выложенный каталог доходит до админки', function (): void {
    $home = t_tmpdir();
    $changed = catalog_iso(t_now());
    $release = ['sha' => str_repeat('a', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('c', 64),
        'catalogVersion' => str_repeat('d', 64), 'catalogChangedAt' => $changed, 'catalogProducts' => 487];
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(), $release, 1000));
    t_equal(admin_deployed_catalog($home), ['version' => str_repeat('d', 64), 'changedAt' => $changed], 'админка видит ту же версию и время');
});

t_case('state.json: сборка без каталога и мусор', function (): void {
    $home = t_tmpdir();
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(),
        ['sha' => str_repeat('a', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('c', 64)], 1000));
    t_equal(admin_deployed_catalog($home), null, 'сборка без каталога — выложенное неизвестно');
    foreach (['', "2026-10-05T14:05:00+05:00\0"] as $i => $junk) {
        file_put_contents($home . '/state.json', json_encode(['current' => [
            'catalogVersion' => $i === 0 ? '' : 'v1', 'catalogChangedAt' => $junk,
        ]]));
        $got = admin_deployed_catalog($home);
        t_true($i === 0 ? $got === null : $got['changedAt'] === '', $i === 0 ? 'пустая версия — неизвестно' : 'NUL-байт — дата неизвестна, без падения');
    }
});
```

Run: `/c/php82/php.exe tests/php/run.php` and `npx vitest run scripts/pack-build.test.mjs`
Expected: падения: нет `catalog` в `build-info`; пустая версия возвращается как есть; NUL-байт — `ValueError`.

- [ ] **Step 2: Реализация**

`scripts/pack-build.mjs`:
- add to `pack` parameters `catalogFile = null`; after archives are described:

```js
  if (catalogFile) {
    const exp = JSON.parse(readFileSync(catalogFile, 'utf8'));
    // Админка понимает время только в виде catalog_iso (2026-10-05T14:32:10+05:00): другой вид
    // спрятал бы отметки «на сайте» — лучше остановить сборку здесь.
    if (!/^[0-9a-f]{64}$/.test(exp.version ?? '')) throw new Error('в выгрузке каталога нет версии');
    if (exp.changedAt !== null && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(exp.changedAt ?? '')) {
      throw new Error(`changedAt выгрузки не в виде catalog_iso: ${exp.changedAt}`);
    }
    info.catalog = { version: exp.version, changedAt: exp.changedAt, products: exp.products.length };
  }
```

(declare `info` with `let`/`const` object built before this block and written after it); CLI passes `catalogFile: path.join(root, 'data', 'catalog-export.json')`; the log line adds `, каталог ${info.catalog.version.slice(0, 12)}`.

`server-pay/deploy.php`, in `deploy_fetch_release`, replace the final `return [...]` with:

```php
    $release = [
        'sha' => $sha,
        'commit' => (string)($info['commit'] ?? ''),
        'paySha256' => (string)($info['pay']['sha256'] ?? ''),
    ];
    // Версия выложенного каталога — по ней админка показывает «на сайте» / «ждёт выкладки».
    if (is_array($info['catalog'] ?? null) && is_string($info['catalog']['version'] ?? null)) {
        $release['catalogVersion'] = $info['catalog']['version'];
        $release['catalogChangedAt'] = is_string($info['catalog']['changedAt'] ?? null) ? $info['catalog']['changedAt'] : '';
        $release['catalogProducts'] = (int)($info['catalog']['products'] ?? 0);
    }
    return $release;
```

(обновить `@return` в докблоке.)

`server-pay/admin/lib/status.php`, `admin_deployed_catalog`: treat `''` version as unknown and catch `Throwable` around both parse steps:

```php
    if (!is_array($current) || !is_string($current['catalogVersion'] ?? null) || $current['catalogVersion'] === '') {
        return null;
    }
    $changed = $current['catalogChangedAt'] ?? '';
    // Мусор вместо даты не должен ронять журнал и карточки: такую отметку считаем неизвестной.
    // Throwable, а не Exception: NUL-байт в строке даёт ValueError ещё на проверке вида.
    try {
        if (!is_string($changed) || ($changed !== '' && (DateTimeImmutable::createFromFormat(DATE_ATOM, $changed) === false
            || new DateTimeImmutable($changed) === null))) {
            $changed = '';
        }
    } catch (Throwable) {
        $changed = '';
    }
    return ['version' => $current['catalogVersion'], 'changedAt' => $changed];
```

(подогнать под нынешнюю структуру функции из 3А — сохранить обе проверки: вид `DATE_ATOM` и разбор конструктором; смысл — любая ошибка → `''`.)

- [ ] **Step 3: Убедиться, что проходят**

Run: `npm test && /c/php82/php.exe tests/php/run.php`
Expected: PASS; `не прошло: 0`, без предупреждений.

Run: `npm run build && node scripts/pack-build.mjs && node -e "console.log(require('./build/build-info.json').catalog)"`
Expected: `{ version: '5a11ba5e…', changedAt: null, products: 487 }`. Папку `build/` не коммитить.

- [ ] **Step 4: Коммит**

```bash
git add scripts/pack-build.mjs scripts/pack-build.test.mjs server-pay/deploy.php server-pay/admin/lib/status.php tests/php/catalog_contract_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(deploy): версия каталога в build-info и state.json — контракт с админкой

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Решение «собирать ли и из чего» и `deploy.yml`

**Files:**
- Create: `scripts/catalog-source.mjs`, `scripts/catalog-source.test.mjs`
- Modify: `.github/workflows/deploy.yml`
- Create: `.github/workflows/keepalive.yml`

**Interfaces:**
- Consumes: `applyCatalogExport` (Task 1); `build-info.json` с `catalog` (Task 2).
- Produces:
  - `decideBuild({event, source, commit, previous, exportVersion}): {build: boolean, fail: boolean, catalog: 'snapshot'|'server', reason: string}`;
  - CLI `node scripts/catalog-source.mjs`: env `GITHUB_EVENT_NAME`, `CATALOG_SOURCE`, `GITHUB_SHA`, `ALLOW_SHRINK` (`'true'`), `PREVIOUS_BUILD_INFO` (путь, файла может не быть), `CATALOG_OUT` (куда положить выгрузку), `CATALOG_URL` (по умолчанию `https://pionperm.ru/pay/catalog-export.php`), `GITHUB_OUTPUT`; пишет в `GITHUB_OUTPUT` `build=true|false` и `catalog=snapshot|server`; код выхода 1, когда `fail`.

Правила `decideBuild`:
1. `source !== 'server'` (переключатель выключен): `schedule` → не собирать («источник — снимок, по расписанию собирать нечего»); иначе собирать из снимка.
2. `source === 'server'`, `exportVersion === null` (сервер не ответил): `schedule` → не собирать, без ошибки; иначе — **ошибка** (`fail: true`): без выгрузки код не выкладываем — собирать из снимка нельзя, он устарел.
3. `source === 'server'`, `event === 'schedule'`, есть `previous`, `previous.commit === commit` и `previous.catalog?.version === exportVersion` → не собирать («ничего не изменилось»).
4. Иначе — собирать из выгрузки сервера.

Невалидная выгрузка (`kind: 'invalid'`) или ошибка записи — всегда **ошибка** (красный запуск, без публикации), при любом событии: салон должен узнать, что его правки не выкладываются (сторож в Task 5 напишет в Telegram через 90 минут).

- [ ] **Step 1: Проверки (пока падают)**

Create `scripts/catalog-source.test.mjs`:

```js
import { describe, expect, it } from 'vitest';
import { decideBuild } from './catalog-source.mjs';

const prev = { commit: 'c1', catalog: { version: 'v1', products: 487 } };

describe('decideBuild', () => {
  it('переключатель выключен: по коммиту — из снимка, по расписанию — ничего', () => {
    expect(decideBuild({ event: 'push', source: '', commit: 'c2', previous: prev, exportVersion: null }))
      .toMatchObject({ build: true, fail: false, catalog: 'snapshot' });
    expect(decideBuild({ event: 'schedule', source: '', commit: 'c1', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: false });
  });

  it('сервер не ответил: по расписанию — тихо, по коммиту — ошибка, из снимка не собираем', () => {
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: false });
    expect(decideBuild({ event: 'push', source: 'server', commit: 'c2', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: true });
  });

  it('по расписанию без изменений — не собирать; изменился каталог или код — собирать из выгрузки', () => {
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: 'v1' }))
      .toMatchObject({ build: false, fail: false });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: 'v2' }))
      .toMatchObject({ build: true, catalog: 'server' });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c2', previous: prev, exportVersion: 'v1' }))
      .toMatchObject({ build: true, catalog: 'server' });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: null, exportVersion: 'v1' }))
      .toMatchObject({ build: true, catalog: 'server' });
  });

  it('по коммиту и вручную — всегда собирать из выгрузки', () => {
    for (const event of ['push', 'workflow_dispatch']) {
      expect(decideBuild({ event, source: 'server', commit: 'c1', previous: prev, exportVersion: 'v1' }))
        .toMatchObject({ build: true, catalog: 'server' });
    }
  });
});
```

Add a CLI test in the same file (spawnSync with env): with `CATALOG_SOURCE=server`, `CATALOG_URL` = путь к образцу `tests/php/fixtures/catalog-export-small.json` (скрипт принимает и путь к файлу — тот же `applyCatalogExport`), `PREVIOUS_BUILD_INFO` = несуществующий путь, `GITHUB_EVENT_NAME=schedule`, `CATALOG_OUT` и `GITHUB_OUTPUT` во временной папке — код 0, в `GITHUB_OUTPUT` строки `build=true` и `catalog=server`, файл `CATALOG_OUT` записан; с `CATALOG_URL` на файл с испорченной выгрузкой (цена 0 у букета в продаже) — код 1 и `build=false`.

Run: `npx vitest run scripts/catalog-source.test.mjs`
Expected: FAIL — модуля нет.

- [ ] **Step 2: `scripts/catalog-source.mjs`**

```js
/**
 * Решение сборки: собирать ли сайт и из какого каталога.
 *
 * Пока переменная репозитория CATALOG_SOURCE не равна «server», сайт
 * собирается из закоммиченного снимка data/catalog-export.json — как до
 * этапа 3, — а запуск по расписанию ничего не делает. С «server» каталог
 * берётся с /pay/catalog-export.php: по расписанию — только когда изменился
 * он или код. Если источник — сервер, а выгрузки нет, из снимка не собираем
 * никогда: снимок устарел, и выкладка вернула бы на сайт старый каталог.
 *
 * Запускается из .github/workflows/deploy.yml; см. docs/deploy.md.
 */
import { appendFileSync, existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyCatalogExport } from './apply-catalog-export.mjs';

export function decideBuild({ event, source, commit, previous, exportVersion }) {
  if (source !== 'server') {
    return event === 'schedule'
      ? { build: false, fail: false, catalog: 'snapshot', reason: 'источник — снимок, по расписанию собирать нечего' }
      : { build: true, fail: false, catalog: 'snapshot', reason: 'источник — снимок в репозитории' };
  }
  if (exportVersion === null) {
    return event === 'schedule'
      ? { build: false, fail: false, catalog: 'server', reason: 'сервер не ответил — попробуем через 15 минут' }
      : { build: false, fail: true, catalog: 'server', reason: 'сервер не отдал выгрузку — из устаревшего снимка не собираем' };
  }
  if (event === 'schedule' && previous && previous.commit === commit && previous.catalog?.version === exportVersion) {
    return { build: false, fail: false, catalog: 'server', reason: 'ни код, ни каталог не изменились' };
  }
  return { build: true, fail: false, catalog: 'server', reason: 'каталог с сервера' };
}

function readPrevious(file) {
  if (!file || !existsSync(file)) return null;
  try {
    return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const env = process.env;
  const event = env.GITHUB_EVENT_NAME ?? 'push';
  const source = env.CATALOG_SOURCE ?? '';
  const commit = env.GITHUB_SHA ?? '';
  const previous = readPrevious(env.PREVIOUS_BUILD_INFO);
  let exportVersion = null;
  let fail = false;
  if (source === 'server') {
    const r = await applyCatalogExport({
      source: env.CATALOG_URL || 'https://pionperm.ru/pay/catalog-export.php',
      out: env.CATALOG_OUT,
      allowShrink: env.ALLOW_SHRINK === 'true',
      previousCount: previous?.catalog?.products ?? null,
    });
    if (r.warning) console.error(`Внимание: ${r.warning}.`);
    if (r.ok) {
      exportVersion = r.version;
      console.log(`Выгрузка с сервера: разделов ${r.sections}, букетов ${r.products}, версия ${r.version.slice(0, 12)}.`);
    } else if (r.kind === 'unreachable') {
      console.log(r.errors[0]);
    } else {
      console.error('Выгрузка не принята:');
      for (const error of r.errors.slice(0, 30)) console.error(`- ${error}`);
      fail = true;
    }
  }
  const decision = fail
    ? { build: false, fail: true, catalog: 'server', reason: 'выгрузка не прошла проверку' }
    : decideBuild({ event, source, commit, previous, exportVersion });
  console.log(`Решение: ${decision.build ? 'собирать' : 'не собирать'} — ${decision.reason}.`);
  if (env.GITHUB_OUTPUT) {
    appendFileSync(env.GITHUB_OUTPUT, `build=${decision.build}\ncatalog=${decision.catalog}\n`);
  }
  process.exit(decision.fail ? 1 : 0);
}
```

- [ ] **Step 3: `deploy.yml`**

Change `.github/workflows/deploy.yml`:

1. `on:` — add schedule and the manual option:

```yaml
on:
  push:
    branches: [master]
  # Каталог из админки: раз в 15 минут проверяем, не изменился ли он.
  # Пока переменная CATALOG_SOURCE не «server», такой запуск ничего не делает.
  schedule:
    - cron: '*/15 * * * *'
  workflow_dispatch:
    inputs:
      allow_shrink:
        description: 'Букетов стало намного меньше — так и задумано (салон удалил много букетов)'
        type: boolean
        default: false
```

2. **Очередь запусков.** Remove the workflow-level `concurrency:` block. Add a light first job `gate` **without** concurrency, which lets a scheduled run through only when the switch is on:

```yaml
  # Запуск по расписанию при выключенном переключателе ничего не делает — и не
  # должен даже вставать в очередь сборок: иначе он вытеснил бы из неё
  # ожидающую сборку по коммиту, и коммит не выложился бы.
  gate:
    if: github.ref == 'refs/heads/master'
    runs-on: ubuntu-latest
    outputs:
      go: ${{ steps.gate.outputs.go }}
    steps:
      - id: gate
        env:
          EVENT: ${{ github.event_name }}
          CATALOG_SOURCE: ${{ vars.CATALOG_SOURCE }}
        run: |
          if [ "$EVENT" = schedule ] && [ "$CATALOG_SOURCE" != server ]; then
            echo "go=false" >> "$GITHUB_OUTPUT"; echo "Источник — снимок: по расписанию собирать нечего."
          else
            echo "go=true" >> "$GITHUB_OUTPUT"
          fi
```

`jobs.build` gets `needs: gate`, `if: needs.gate.outputs.go == 'true'` (вместо прежнего `if` с веткой — ветку проверяет `gate`) and **job-level** concurrency:

```yaml
    # Одна сборка за раз. Новый коммит отменяет идущую сборку (в нём есть всё);
    # запуск по расписанию идущую сборку не отменяет, а ждёт: иначе он мог бы
    # снять сборку коммита и решить, что собирать нечего.
    concurrency:
      group: deploy-build
      cancel-in-progress: ${{ github.event_name == 'push' }}
```

`jobs.publish` gets its own serialized group so that two publications never race for `server-build`:

```yaml
    concurrency:
      group: deploy-publish
      cancel-in-progress: false
```

Ожидающая сборка, вытесненная из очереди более новым запуском, не теряется: новый запуск собирает свежий `master` (`github.sha` расписания — последний коммит), а при `CATALOG_SOURCE=server` `decideBuild` соберёт, если коммит отличается от прошлой сборки (правило 3). Если `master` сломан при включённом переключателе, запуски по расписанию будут красными раз в 15 минут, пока его не починят, — это ожидаемо и описывается в `docs/deploy.md`.

3. In `jobs.build` add `outputs: { publish: ${{ steps.source.outputs.build }} }` and replace the steps up to (not including) `npm run build` with:

```yaml
      - uses: actions/checkout@v4
        with:
          persist-credentials: false

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      # Прошлая сборка: из неё — коммит и версия каталога, с которыми сравнивать.
      - name: Прошлая сборка
        run: |
          git fetch -q --depth=1 origin server-build \
            && git show FETCH_HEAD:build-info.json > "$RUNNER_TEMP/previous-build-info.json" \
            || echo "Прошлой сборки нет — сравнивать не с чем."

      - name: Каталог — собирать ли и из чего
        id: source
        env:
          CATALOG_SOURCE: ${{ vars.CATALOG_SOURCE }}
          ALLOW_SHRINK: ${{ inputs.allow_shrink }}
          PREVIOUS_BUILD_INFO: ${{ runner.temp }}/previous-build-info.json
          CATALOG_OUT: ${{ runner.temp }}/catalog-export.json
        run: node scripts/catalog-source.mjs

      - uses: shivammathur/setup-php@v2
        if: steps.source.outputs.build == 'true'
        with:
          php-version: '8.2'
          coverage: none

      - if: steps.source.outputs.build == 'true'
        run: npm ci
      # Проверки — на закоммиченном снимке: они сверяют снимок с файлами Tilda.
      - if: steps.source.outputs.build == 'true'
        run: npm test
      - if: steps.source.outputs.build == 'true'
        run: find server-pay wp-theme -name '*.php' -print0 | xargs -0 -n1 php -l
      - if: steps.source.outputs.build == 'true'
        run: php tests/php/run.php

      # Только теперь подменяем снимок выгрузкой с сервера; prebuild проверит её ещё раз.
      - name: Каталог с сервера в сборку
        if: steps.source.outputs.build == 'true' && steps.source.outputs.catalog == 'server'
        run: cp "$RUNNER_TEMP/catalog-export.json" data/catalog-export.json
```

and add `if: steps.source.outputs.build == 'true'` to every remaining step of `build` (`npm run build`, `pack-build`, `check-server-build`, `upload-artifact`).

4. `jobs.publish`: `if: needs.build.outputs.publish == 'true'`.

5. Update the header comment of the workflow: расписание, переключатель `CATALOG_SOURCE`, `allow_shrink`, «из устаревшего снимка не собираем», очередь (`gate`, `deploy-build`, `deploy-publish`).

Create `.github/workflows/keepalive.yml`:

```yaml
name: Расписание не засыпает

# GitHub выключает запуски по расписанию в публичном репозитории после 60 дней
# без коммитов. Раз в месяц включаем их заново — и эту задачу, и «Сборку и выкладку».
on:
  schedule:
    - cron: '17 4 1 * *'
  workflow_dispatch:

permissions:
  actions: write

jobs:
  keepalive:
    runs-on: ubuntu-latest
    steps:
      - env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          for wf in deploy.yml keepalive.yml; do
            gh api -X PUT "repos/${GITHUB_REPOSITORY}/actions/workflows/$wf/enable"
          done
```

- [ ] **Step 4: Проверки**

Run: `npx vitest run scripts/catalog-source.test.mjs && npm test`
Expected: PASS.

Проверить YAML: `node -e "const y=require('fs').readFileSync('.github/workflows/deploy.yml','utf8'); if(!/steps\.source\.outputs\.build == 'true'/.test(y)) process.exit(1)"` и, если на машине есть `npx --yes @action-validator/cli` — **не ставить**: новых зависимостей нет. Достаточно внимательно перечитать отступы; ошибку в YAML покажет первый запуск на `master` — поэтому в отчёте перечислить все шаги `build` с их условиями `if`.

- [ ] **Step 5: Коммит**

```bash
git add scripts/catalog-source.mjs scripts/catalog-source.test.mjs .github/workflows/deploy.yml .github/workflows/keepalive.yml
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(deploy): каталог с сервера за переключателем CATALOG_SOURCE, расписание, allow_shrink

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Откат помечает пару «код + каталог»

**Files:**
- Modify: `server-pay/deploy-lib.php` (`deploy_state_after_rollback`, новая `deploy_pair_key`)
- Modify: `server-pay/deploy.php` (`deploy_run`)
- Test: `tests/php/deploy_state_test.php`, `tests/php/deploy_cli_test.php`

**Interfaces:**
- Consumes: `deploy_mark_bad`, `deploy_state_after_rollback` (этап 1), release с `commit` и `catalogVersion` (Task 2).
- Produces:
  - `deploy_pair_key(array $release): string` — `commit . '|' . (catalogVersion ?? '')`;
  - `state['badPairs']` — пары «код + каталог» откатанных сборок (последние 20);
  - `deploy_state_after_rollback` дополнительно помечает пару текущей сборки;
  - `deploy_run`: сборка, у которой пара плохая, не выкладывается — её sha помечается плохим с причиной «повторяет откатанную сборку (код …, каталог …)».

При пересборке того же коммита с тем же каталогом (кнопка «Run workflow») получается новая sha ветки `server-build`; раньше она выложила бы откатанное снова — теперь нет. Новый каталог при том же коде выкладывается (салон поправил данные) — если откат был из-за ошибки в коде, исправление кода идёт через `git revert` в `master`, как и сейчас (`docs/deploy.md`).

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/deploy_state_test.php` (стиль файла — проверки на верхнем уровне):

```php
$r1 = ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => str_repeat('0', 64), 'catalogVersion' => str_repeat('c', 64)];
$r2 = ['sha' => str_repeat('2', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('0', 64), 'catalogVersion' => str_repeat('d', 64)];
$s = deploy_state_after_success(deploy_state_after_success(deploy_empty_state(), $r1, 1), $r2, 2);
$s = deploy_state_after_rollback($s, 3);
t_true(isset($s['badPairs'][deploy_pair_key($r2)]), 'откат помечает пару «код + каталог» откатанной сборки');
t_equal(deploy_pair_key(['commit' => 'x']), 'x|', 'сборка без каталога — пара по коду');
```

In `tests/php/deploy_cli_test.php` add a case by the pattern of the existing rollback/`bad` cases: после отката новая sha в `server-build` с тем же `commit` и `catalog.version` не выкладывается (в журнале — «повторяет откатанную»), а с другой `catalog.version` — выкладывается. Если готового способа подложить `build-info.json` с полем `catalog` в этих проверках нет — сделать по образцу того, как там подкладываются сборки, и описать в отчёте.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: падения — нет `deploy_pair_key`, нет `badPairs`.

- [ ] **Step 2: Реализация**

In `server-pay/deploy-lib.php`:

```php
/**
 * Пара «код + каталог» сборки. После отката той же пары быть не должно: иначе
 * пересборка того же коммита с тем же каталогом (новая sha в server-build)
 * снова выложила бы то, что откатили.
 */
function deploy_pair_key(array $release): string
{
    return (string)($release['commit'] ?? '') . '|' . (string)($release['catalogVersion'] ?? '');
}
```

In `deploy_empty_state()` add `'badPairs' => []`. In `deploy_state_after_rollback`, before `array_shift($state['history'])`, add:

```php
    $state['badPairs'][deploy_pair_key($state['current'])] = 'откат вручную';
    $state['badPairs'] = array_slice($state['badPairs'], -20, null, true);
```

Update the state docblock (`badPairs — пары «код + каталог» откатанных сборок`).

In `server-pay/deploy.php`, `deploy_run`, right after `$release = deploy_fetch_release($home, $sha);` add:

```php
        $pair = deploy_pair_key($release);
        if (isset($state['badPairs'][$pair])) {
            $why = 'повторяет откатанную сборку (код ' . substr($release['commit'], 0, 7) . ', каталог '
                . substr((string)($release['catalogVersion'] ?? '—'), 0, 7) . ')';
            deploy_log($home, "сборка $short не выложена: $why");
            if (!$dryRun) {
                deploy_finish($home, deploy_state_idle(deploy_mark_bad($state, $sha, $why)), $now);
            }
            return 0;
        }
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/deploy-lib.php server-pay/deploy.php tests/php/deploy_state_test.php tests/php/deploy_cli_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(deploy): откат помечает пару «код + каталог» — пересборка не вернёт откатанное

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Сторож каталога — отставание, копии базы, обслуживание

**Files:**
- Modify: `server-pay/deploy-lib.php` (`deploy_pending_alerts`, `deploy_alert_text`, новая `deploy_catalog_watch`)
- Modify: `server-pay/deploy.php` (`deploy_finish` и вызовы)
- Test: `tests/php/deploy_lib_test.php`

**Interfaces:**
- Consumes: `catalog_db_path`, `catalog_db_open`, `catalog_meta`, `catalog_home` (`server-pay/catalog/db.php`); файл итога обслуживания `catalog_home()/maintenance.json` (Task 6: `{"ok": bool, "at": "<catalog_iso>", "message": "..."}`); копии `catalog_home()/backups/catalog-*.sqlite`.
- Produces:
  - `deploy_catalog_watch(string $catalogHome, string $dbFile): ?array` — `null`, если базы нет; иначе `['version' => string, 'changedAt' => int, 'backupAt' => ?int, 'maintenance' => ?array{ok: bool, at: int, message: string}]` (ошибки чтения — `null` и запись в журнал вызывающим);
  - `deploy_pending_alerts(array $state, int $now, ?array $watch = null): list<string>` — кроме `fatal`/`transient`/`lag` ещё `catalog`, `backup`, `maintenance`:
    - `catalog`: `watch.version !== state.current.catalogVersion` и `now - watch.changedAt >= DEPLOY_LAG_ALERT_AFTER` (90 мин), и сейчас нет сбоя выкладки (о нём и так пишем);
    - `backup`: `backupAt === null` или старше `DEPLOY_BACKUP_STALE_AFTER = 2 * 86400`;
    - `maintenance`: `maintenance !== null` и `ok === false`;
    - каждый — не чаще `DEPLOY_ALERT_COOLDOWN` (3 часа);
  - `deploy_alert_text` для новых видов:
    - `catalog`: «Каталог pionperm.ru: изменения из админки больше 90 минут не на сайте. Проверьте GitHub Actions («Сборка и выкладка»).»
    - `backup`: «Каталог pionperm.ru: свежей копии базы нет больше двух суток — проверьте задание обслуживания.»
    - `maintenance`: «Каталог pionperm.ru: ночное обслуживание не удалось — <message>.»
  - `deploy_finish(string $home, array $state, int $now, ?array $watch = null)`; `deploy.php` собирает `$watch` при каждом запуске (до любого `deploy_finish`) и передаёт его.

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/deploy_lib_test.php` (по стилю файла):

```php
// --- Сторож каталога -------------------------------------------------------
$base = deploy_state_after_success(deploy_empty_state(),
    ['sha' => str_repeat('1', 40), 'commit' => str_repeat('a', 40), 'paySha256' => str_repeat('0', 64), 'catalogVersion' => 'v1'], 1000);
$now = 1_000_000;
$fresh = ['version' => 'v1', 'changedAt' => $now - 60, 'backupAt' => $now - 3600, 'maintenance' => ['ok' => true, 'at' => $now - 3600, 'message' => '']];
t_equal(deploy_pending_alerts($base, $now, $fresh), [], 'всё выложено, копия свежая, обслуживание в порядке — молчим');
t_equal(deploy_pending_alerts($base, $now, ['version' => 'v2', 'changedAt' => $now - 60] + $fresh), [], 'правка 1 минуту назад — ещё рано');
t_equal(deploy_pending_alerts($base, $now, ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh), ['catalog'], 'правка не выложена 90 минут — пишем');
t_equal(deploy_pending_alerts($base, $now, ['backupAt' => $now - 2 * 86400 - 1] + $fresh), ['backup'], 'копии нет больше двух суток');
t_equal(deploy_pending_alerts($base, $now, ['backupAt' => null] + $fresh), ['backup'], 'копий нет совсем');
t_equal(deploy_pending_alerts($base, $now, ['maintenance' => ['ok' => false, 'at' => $now, 'message' => 'диск полон']] + $fresh), ['maintenance'], 'обслуживание упало');
t_equal(deploy_pending_alerts(deploy_mark_alerted($base, 'catalog', $now - 60), $now, ['version' => 'v2', 'changedAt' => $now - 5400] + $fresh), [], 'не чаще раза в 3 часа');
t_equal(deploy_pending_alerts($base, $now, null), [], 'базы нет — о каталоге молчим');
t_true(str_contains(deploy_alert_text('maintenance', $base + ['watch' => ['maintenance' => ['message' => 'диск полон']]]), 'ночное обслуживание'), 'текст про обслуживание');
```

(Как передать сообщение обслуживания в текст — решить в реализации: например, `deploy_alert_text(string $kind, array $state, ?array $watch = null)`; тогда последнюю проверку переписать под эту сигнатуру и описать в отчёте.)

Add a case for `deploy_catalog_watch` with a temp catalog home: база (`t_catalog_db`, `catalog_touch`), одна копия в `backups/`, `maintenance.json` — функция возвращает версию, время изменения, время копии и итог обслуживания; без базы — `null`.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: падения — нет третьего параметра, нет новых видов.

- [ ] **Step 2: Реализация**

In `server-pay/deploy-lib.php`:
- add `const DEPLOY_BACKUP_STALE_AFTER = 172800;` next to the other alert constants (с комментарием «Копия базы делается раз в сутки — двое суток без неё значит, что обслуживание не идёт»);
- `deploy_catalog_watch(string $catalogHome, string $dbFile): ?array` — `require_once __DIR__ . '/catalog/db.php'` внутри (deploy-lib подключается и из `check-server-build.php`, которому каталог не нужен); если `!is_file($dbFile)` — `null`; иначе `catalog_meta(catalog_db_open($dbFile))` → `version` и `changed_at` (в секунды через `strtotime`; пустое — `0`); `backupAt` — `max(filemtime)` по `glob($catalogHome . '/backups/catalog-*.sqlite')` или `null`; `maintenance` — разобранный `maintenance.json` (`ok` bool, `at` — `strtotime`, `message` string) или `null`;
- extend `deploy_pending_alerts` with `?array $watch = null`: после существующей логики (сбой выкладки возвращает раньше — сохранить) добавить проверки `catalog`, `backup`, `maintenance` по правилам выше, каждая — с `deploy_alert_due`; результат — список видов;
- extend `deploy_alert_text` with the three texts.

In `server-pay/deploy.php`:
- `deploy_finish(string $home, array $state, int $now, ?array $watch = null)` передаёт `$watch` в `deploy_pending_alerts` и в `deploy_alert_text`;
- in the main block (before `deploy_run`) compute the watch once:

```php
require_once __DIR__ . '/catalog/db.php';
$watch = null;
try {
    $watch = deploy_catalog_watch(catalog_home(), catalog_db_path());
} catch (Throwable $e) {
    deploy_log($home, 'не прочитать базу каталога для сторожа: ' . $e->getMessage());
}
```

and pass `$watch` into `deploy_run(...)` → every `deploy_finish(...)` call. (`catalog_home()`/`catalog_db_path()` учитывают `PION_CATALOG_HOME` — проверки `deploy_cli_test.php` должны выставлять его во временную папку, чтобы не читать настоящую; проверить и описать.)

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`, без предупреждений.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/deploy-lib.php server-pay/deploy.php tests/php/deploy_lib_test.php tests/php/deploy_cli_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(deploy): сторож каталога — отставание выкладки, копии базы, итог обслуживания

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Обслуживание — уборка ждёт выкладки, итог в файл

**Files:**
- Modify: `server-pay/catalog/maintenance.php` (`catalog_photos_sweep`)
- Modify: `server-pay/catalog/maintenance-cli.php`
- Test: `tests/php/catalog_maintenance_test.php`

**Interfaces:**
- Consumes: `catalog_photos_sweep` (2Б), `state.json` с `current.catalogVersion` / `catalogChangedAt` (Task 2), `catalog_meta` (2А).
- Produces:
  - `catalog_photos_sweep(PDO $db, string $webroot, string $candidatesFile, DateTimeImmutable $now, ?Closure $published = null): array` — `$published(string $dbVersion): bool` отвечает, выложен ли на сайте каталог именно этой версии; версия базы читается в одной транзакции чтения со ссылками на фото; фото-кандидат уходит в корзину, только если прошли сутки **и** `$published($dbVersion)`; `null` — прежнее поведение (только сутки);
  - `catalog_deploy_covers(?array $current): Closure` — `true`, только если `current.catalogVersion` — непустая строка, равная версии базы; нет состояния — всегда `false` (ничего не двигаем). **Поправка после проверки Task 6:** правило «или `catalogChangedAt >= $since`» убрано. Фото, на которое снова сослались и снова убрали между двумя ночными уборками, сохраняет старый `$since`, и сборка из середины (ещё с фото) считалась бы «выложенной без фото». Цена — фото ждёт лишнюю ночь, если вечером были правки, а выкладка до ночи не дошла;
  - `maintenance-cli.php`: читает `state.json` из `PION_DEPLOY_HOME` или `dirname($webroot, 2) . '/pion-deploy'` (как `admin/lib/app.php`), передаёт `$published`; итог пишет атомарно в `catalog_home()/maintenance.json` (`{"ok": true|false, "at": catalog_iso(now), "message": "..."}`) и строкой в `catalog_home()/maintenance.log`.

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/catalog_maintenance_test.php`:

```php
t_case('уборка ждёт, пока сайт выложен без фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-eeeeeeee.webp";
    t_put_files($webroot, [ltrim($rel, '/') => 'webp']);
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), fn (int $since): bool => false);
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'), fn (int $since): bool => false);
    t_equal([$r['moved'], is_file($webroot . $rel)], [0, true], 'сутки прошли, но сайт ещё не выложен — фото на месте');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+26 hours'), fn (int $since): bool => true);
    t_equal([$r['moved'], is_file($webroot . $rel)], [1, false], 'сайт выложен — в корзину');
});

t_case('выложено ли: по версии и по времени', function (): void {
    $covers = catalog_deploy_covers(['catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:00:00+05:00'], 'v1');
    t_true($covers(PHP_INT_MAX), 'версии совпадают — выложено всё');
    $covers = catalog_deploy_covers(['catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:00:00+05:00'], 'v2');
    t_true($covers(strtotime('2026-10-05T13:59:00+05:00')), 'правка раньше выложенной — на сайте');
    t_true(!$covers(strtotime('2026-10-05T14:01:00+05:00')), 'правка позже — ещё нет');
    t_true(!catalog_deploy_covers(null, 'v1')(0), 'состояния нет — ничего не двигаем');
});
```

And extend the existing `'maintenance-cli.php'` case: после успешного запуска в `$home/maintenance.json` — `ok: true` и время; с испорченной базой (например, файл базы — не SQLite) — код 1, `ok: false` и непустое `message`; `maintenance.log` получает по строке на запуск.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: падения — нет пятого параметра, нет `catalog_deploy_covers`, нет файла итога.

- [ ] **Step 2: Реализация**

In `server-pay/catalog/maintenance.php`:
- add the `?Closure $published = null` parameter; where a candidate becomes due (`$time - $since >= CATALOG_PHOTO_GRACE`), also require `$published === null || $published($since)`; a candidate that waits only for the deploy stays a candidate with its original `$since`;
- add:

```php
/**
 * Выложено ли на сайте всё, что было в базе к моменту $since. state.json пишет
 * deploy.php: версия выложенного каталога и время его последней правки. Пока
 * состояния нет (выкладка ещё не знает каталог) — считаем, что не выложено:
 * лучше подержать фото лишний день, чем показать на сайте пустую картинку.
 */
function catalog_deploy_covers(?array $current, string $dbVersion): Closure
{
    $version = is_array($current) ? ($current['catalogVersion'] ?? null) : null;
    $changed = is_array($current) && is_string($current['catalogChangedAt'] ?? null) ? strtotime($current['catalogChangedAt']) : false;
    return static function (int $since) use ($version, $dbVersion, $changed): bool {
        if (!is_string($version) || $version === '') {
            return false;
        }
        return $version === $dbVersion || ($changed !== false && $changed >= $since);
    };
}
```

- update the doc comments (`catalog_photos_sweep`, file header) — убрать оговорку «перед тем как включить расписание … должна ещё и ждать», теперь ждёт.

In `server-pay/catalog/maintenance-cli.php`:
- after `$webroot`: `$deployHome = rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/');`, read `state.json` (`json_decode`, `current` or `null`), `$db = catalog_db_open($file)`, `$published = catalog_deploy_covers($current, (string)(catalog_meta($db)['version'] ?? ''))`, pass to the sweep;
- wrap the work so that both success and failure write `maintenance.json` atomically (`.part` + `rename`) and append a line to `maintenance.log` (`<catalog_iso> ok|ошибка: <текст>`); exit codes as before (0/1).

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/catalog/maintenance.php server-pay/catalog/maintenance-cli.php tests/php/catalog_maintenance_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): уборка фото ждёт выкладки, итог обслуживания — в файл для сторожа

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Админка — без фото на сайт не попасть

**Files:**
- Modify: `server-pay/admin/lib/pages-products.php` (`admin_product_save`)
- Modify: `server-pay/catalog/products.php` (`catalog_unhide`)
- Modify: `server-pay/catalog/sections.php` (`catalog_update_section`)
- Test: `tests/php/catalog_ready_test.php`

**Interfaces:**
- Consumes: проверки 3А (`catalog_unhide` с ценой, публикация с фото).
- Produces:
  - «Сохранить» у букета **в продаже** без фото — 422 «Добавьте хотя бы одно фото — у букета в продаже оно должно быть.»;
  - `catalog_unhide` без фото — `CatalogError` «Сначала добавьте фото в карточке, потом возвращайте букет в продажу.» (проверка цены — как в 3А, первой);
  - `catalog_update_section`: если после правки раздел видим, а фото плитки пустое, — `CatalogError` «Чтобы показать раздел в каталоге, сначала добавьте фото плитки.»

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/catalog_ready_test.php` three cases:
1. букет в продаже с фото → POST `action=save` без `images` → 422 с текстом; статус и фото в базе прежние;
2. снятый букет с ценой 4400 и `images = '[]'` (через SQL) → `catalog_unhide` бросает `CatalogError` с «Сначала добавьте фото»; с фото — возвращается;
3. скрытый раздел без фото плитки → `catalog_update_section(..., ['visible' => true])` бросает с «сначала добавьте фото плитки»; с `tileImage` и `visible` в одной правке — проходит; видимый раздел с фото → правка `tileImage => ''` при `visible` — бросает.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: три падения.

- [ ] **Step 2: Реализация**

- `admin_product_save`: рядом с проверкой 3А для публикации добавить: `if ($action !== 'publish' && $p['status'] === 'active' && $fields['images'] === []) { throw new CatalogError('Добавьте хотя бы одно фото — у букета в продаже оно должно быть.'); }`.
- `catalog_unhide`: в замыкание проверки после цены — `if ((json_decode((string)$p['images'], true) ?: []) === []) { throw new CatalogError('Сначала добавьте фото в карточке, потом возвращайте букет в продажу.'); }`.
- `catalog_update_section`: перед `UPDATE` вычислить итоговые `visible` и `tile_image` (из переданных полей или текущей строки) и, если `visible = 1` и `tile_image = ''`, бросить `CatalogError` с текстом выше.

Существующие проверки, которые показывают раздел без фото плитки или сохраняют букет в продаже без фото, поправить по смыслу (добавить фото), перечислить в отчёте.

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/admin/lib/pages-products.php server-pay/catalog/products.php server-pay/catalog/sections.php tests/php/
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): без фото букет в продаже и раздел в каталоге — нельзя

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `compare-builds.mjs` — сравнивает всё, что важно при переключении

**Files:**
- Modify: `scripts/compare-builds.mjs`
- Create: `scripts/compare-builds.test.mjs`

**Interfaces:**
- Consumes: сборки `out/` (две папки).
- Produces:
  - `compareBuilds(before: string, after: string): {lines: string[], differences: number}` — экспортируемая функция; CLI печатает `lines` и выходит с кодом `1`, если `differences > 0`, `0` — если отличий нет; `--report` — всегда `0`;
  - для каждой общей страницы `index.html` сравниваются: `<title>`, `<meta name="description">`, `<link rel="canonical">`, первый `<h1>`, список ссылок внутри `<main>` (адрес и текст) — так видны «Новинки» на главной и плитки каталога; плюс прежние ItemList и Product из JSON-LD;
  - `api/catalog/*.json` — по разобранному содержимому; `feed/products.xml` и `feed/products.csv` — без даты;
  - список страниц строится один раз (сейчас — внутри цикла, около 70 с на сравнение).

- [ ] **Step 1: Проверки (пока падают)**

Create `scripts/compare-builds.test.mjs`: собрать во временной папке две крошечные «сборки» (`sitemap.xml`, `index.html` с `<title>`, `<main>` со ссылками, JSON-LD ItemList; `bukety/index.html`; `api/catalog/bukety.json`; `feed/products.xml` и `.csv`) и проверить:
- одинаковые — `differences === 0`;
- другой `<title>` на одной странице — `differences > 0`, в `lines` есть адрес страницы и оба заголовка;
- другая ссылка в `<main>` главной (букет в «Новинках») — отличие найдено;
- другой `api/catalog/bukety.json` — отличие найдено;
- другой CSV — отличие найдено;
- дата в `products.xml` не считается отличием.

Run: `npx vitest run scripts/compare-builds.test.mjs`
Expected: FAIL — функции нет.

- [ ] **Step 2: Реализация**

Переписать `scripts/compare-builds.mjs` вокруг экспортируемой `compareBuilds(before, after)` (логика печати — в CLI-части; страницы — один раз в начале; каждая найденная разница увеличивает `differences` и добавляет строку). Извлечение — регулярными выражениями, как сейчас JSON-LD (зависимостей не добавлять): `<title>([^<]*)</title>`, `<meta name="description" content="([^"]*)"`, `<link rel="canonical" href="([^"]*)"`, `<h1[^>]*>([\s\S]*?)</h1>` (теги внутри убрать), ссылки в `<main…>…</main>`: `<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)</a>`. Сохранить прежний вывод по карте сайта, страницам, спискам букетов и фиду. Комментарий в шапке — дополнить: «код выхода 1 — сборки различаются (для этапа 3В: сравнение сборки из снимка со сборкой из выгрузки сервера)».

- [ ] **Step 3: Проверить на настоящей сборке**

Run: `npm run build && node scripts/compare-builds.mjs out out`
Expected: `differences 0`, код 0, время — секунды, не минуты (записать в отчёт).

- [ ] **Step 4: Коммит**

```bash
git add scripts/compare-builds.mjs scripts/compare-builds.test.mjs
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(scripts): compare-builds сравнивает заголовки, ссылки, api и CSV; код выхода

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: PHP-проверки на настоящей SQLite 3.26

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `tests/php/run.php`.
- Produces: задача `php-sqlite326` в `ci.yml` — контейнер `almalinux:8` (та же ОС, что на хостинге), PHP 8.2 из модуля `php:8.2`, системная SQLite 3.26; шаг, который проверяет, что версия SQLite действительно `3.26.*`; прогон `php tests/php/run.php`.

- [ ] **Step 1: Задача в CI**

Add to `.github/workflows/ci.yml` (на уровне `jobs:`):

```yaml
  # Хостинг — AlmaLinux 8 с SQLite 3.26: свежая SQLite на ubuntu-latest не
  # заметит SQL новее 3.26. Здесь те же проверки — на той же ОС и той же SQLite.
  php-sqlite326:
    runs-on: ubuntu-latest
    container: almalinux:8
    steps:
      - run: |
          dnf -y -q module enable php:8.2
          dnf -y -q install php-cli php-pdo php-gd php-mbstring git tar gzip
      - uses: actions/checkout@v4
        with:
          persist-credentials: false
      - name: SQLite — та же, что на хостинге
        run: |
          php -r 'echo SQLite3::version()["versionString"], PHP_EOL;' | tee /dev/stderr | grep -q '^3\.26\.'
      - run: php tests/php/run.php
```

- [ ] **Step 2: Прогон на ветке**

Отправить ветку (`git push`) и дождаться запуска «Проверка сборки» по ветке `catalog-pipeline` (без `gh` на машине — через публичный API: `curl -s "https://api.github.com/repos/kidw3st/pion/actions/runs?branch=catalog-pipeline&per_page=3"`, затем `…/runs/<id>/jobs`). Если задача падает:
- нет пакета или модуля — подобрать пакеты (например, `php-json`, `php-process`, `php-xml`), не меняя смысла;
- падают конкретные PHP-проверки — это **находки**: SQL или поведение, которых нет на SQLite 3.26 / PHP из AlmaLinux. Каждую — в отчёт (проверка, сообщение); код `/pay/` исправить так, чтобы проверка проходила и на 3.26, и на свежей SQLite;
- проверке нужен WebP в GD, а в контейнере его нет — сообщить (на хостинге WebP есть); не отключать проверки молча.

Итерации — отдельными коммитами с понятными сообщениями; в отчёте — ссылка на успешный запуск и выдержка вывода (версия SQLite, итог `Проверок: N, не прошло: 0`).

- [ ] **Step 3: Коммит** (последний — после зелёного запуска)

```bash
git add .github/workflows/ci.yml
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "ci: PHP-проверки на AlmaLinux 8 с SQLite 3.26 — как на хостинге

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push
```

---

### Task 10: Документация конвейера

**Files:**
- Modify: `docs/deploy.md`
- Modify: `docs/catalog.md`
- Modify: `docs/known-follow-ups.md`

**Interfaces:**
- Consumes: Tasks 1–9.
- Produces: описание для разработчика и отметки в списках.

- [ ] **Step 1: `docs/deploy.md`**

Add a section «Каталог в сборке (этап 3)»:
- переменная репозитория `CATALOG_SOURCE` (Settings → Secrets and variables → Actions → Variables): пусто — снимок, `server` — выгрузка с `/pay/catalog-export.php`;
- расписание раз в 15 минут; что делает запуск по расписанию и по коммиту в каждом режиме (таблица из правил `decideBuild`);
- «из устаревшего снимка не собираем»: запуск по коммиту при недоступной выгрузке — красный;
- `allow_shrink` — ручной запуск «Run workflow» с галочкой, когда салон действительно удалил много букетов;
- откат и пара «код + каталог»: что выкладывается после отката; ошибка в коде — по-прежнему `git revert` в `master`;
- сторож: виды сообщений `catalog`, `backup`, `maintenance` и когда они приходят;
- `keepalive.yml` — зачем.

- [ ] **Step 2: `docs/catalog.md`**

- в разделе обслуживания: итог в `maintenance.json` / `maintenance.log`, уборка фото ждёт выкладки (по `state.json`), без состояния выкладки ничего не двигает;
- в «Админке»: букет в продаже и раздел в каталоге — только с фото (Task 7).

- [ ] **Step 3: `docs/known-follow-ups.md`**

Отметить `- [x]` с «— сделано в 3Б (`docs/superpowers/plans/2026-10-08-catalog-pipeline.md`)» пункты, закрытые Tasks 1–9: `deploy.yml`, защита от потери (`--previous-count`), доработки `apply-catalog-export.mjs`, `compare-builds.mjs`, уборка фото ждёт выкладки, тревоги обслуживания, сторож выкладки, проверка SQLite 3.26, NUL-байт, формат `catalogChangedAt` (контракт и проверка в `pack-build`), «тест снимка требует фото в `public/`» и «тесты, привязанные к снимку» — закрыты порядком шагов в `deploy.yml` (тесты идут до подмены снимка), «букет в продаже без фото», «раздел без фото плитки». Из этапа 1: «как помечать плохие сборки» — пара «код + каталог». Каждую отметку сверить с кодом; что не сделано — оставить открытым с пометкой.

- [ ] **Step 4: Проверки, коммит, отправка**

Run: `npm test && /c/php82/php.exe tests/php/run.php && npm run build`
Expected: всё проходит.

```bash
git add docs/deploy.md docs/catalog.md docs/known-follow-ups.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "docs: конвейер каталога — переключатель, расписание, сторож, отметки в списках

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push
```

## После слияния в `master`

`CATALOG_SOURCE` не задана — сайт собирается как раньше. Проверить:
- первый запуск «Сборка и выкладка» по коммиту — зелёный, публикация есть, в `build-info.json` ветки `server-build` есть `catalog` с версией снимка;
- запуски по расписанию появляются раз в 15 минут и заканчиваются без сборки («источник — снимок»);
- на сервере после выкладки `php deploy.php --status` и `state.json` → `current.catalogVersion` = версия снимка; админка (когда база появится на 3В) увидит её.
