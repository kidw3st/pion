# Сайт из выгрузки каталога — план (этап 2В)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сайт собирается из выгрузки каталога в формате админки (`data/catalog-export.json`). Сюда входят: разделы из данных, страницы снятых с продажи букетов, букет в нескольких разделах, переадресации и «Новинки» из раздела. Пока каталог не переехал в базу, выгрузку собирает скрипт из нынешних файлов, и сайт остаётся прежним.

**Architecture:** Выгрузка (тот же JSON, что отдаёт `server-pay/catalog/export.php`) лежит одним файлом `data/catalog-export.json`. Перед сборкой её проверяет `scripts/apply-catalog-export.mjs`. На этапе 3 этот же скрипт будет брать выгрузку с сервера. Страницы и служебные файлы читают выгрузку через одни и те же чистые функции `scripts/lib/catalog-view.mjs`, у страниц для этого есть типизированная обёртка `src/lib/catalog.ts`. Пока идёт этап 2, файл собирает `scripts/make-catalog-export.mjs` из `data/catalog/*.json` и `data/catalog-meta.json`. Что сайт остался тем же, проверяет сверка «туда-обратно» и сравнение двух сборок. Переадресации на сервере обрабатывают правило `.htaccess` и `pay/catalog-redirect.php`.

**Tech Stack:** Next.js 14 (static export), TypeScript, Node 20 ESM-скрипты, vitest, PHP 8.2/8.3 (проверки `tests/php/run.php`), Apache `.htaccess`.

## Global Constraints

- `master` — это боевой сайт. Работа идёт в ветке `catalog-site` от `master`. Ветку можно отправлять на GitHub, слияние — только после итоговой проверки.
- Коммиты делаются с переменными `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com"`, последняя строка сообщения — `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Всё, что видит покупатель, пишется по-русски и без технических слов.
- Новых npm-зависимостей нет.
- Не менять: `data/catalog/*.json`, `data/catalog-meta.json`, `data/site.json` (это источники), платёжную часть (`server-pay/lib.php`, `init.php`, `notify.php`, `uds*.php`, `posiflora.php`, `sync-showcase.php`), `server-pay/catalog/` и `server-pay/admin/`, фото в `public/images/`.
- Сайт после этапа 2В выглядит и работает как сейчас. Допустимы только такие изменения:
  - 19 «Цветочных коробок» с ценой 0 ₽ (в Tilda без цены и выключены) сняты с продажи: в разделе их нет, страница остаётся с пометкой «Сейчас нет в продаже», без кнопки «В корзину»;
  - пустой раздел показывает «Сейчас здесь пусто» и ссылку на каталог;
  - в служебных JSON для ИИ-ассистентов (`api/catalog`, `api/index.json`, `md/`) у разделов без обложки (`pions`, `roses`, `mixflower`) заголовок — название раздела, а не адрес; `api/tilda-map.json` ведёт все букеты на их страницы;
  - новые `api/redirects.json` и правило переадресаций.
- Формат выгрузки — ровно как у `server-pay/catalog/export.php`: ключи объектов в том же порядке, разделы по `slug`, букеты по `uid`, переадресации по `from`, версия — sha256 от `JSON.stringify({sections, tiles, products, redirects})`.
- Правила в `public/.htaccess` не трогают `/pay/`, `/blog/`, `/api/`, `/images/`, `/md/`, `/_next/`, `/feed/`, `/fonts/`, `/.well-known/`.
- Проверки: `npm test`, `/c/php82/php.exe tests/php/run.php`, `npm run build` — без ошибок. В выводе PHP-проверок не должно быть предупреждений.

## Отличия от спецификации (решено при составлении плана)

1. **Один файл вместо трёх.** В спецификации (раздел 4, шаг 4) сказано, что выгрузка раскладывается в `data/catalog/<раздел>.json`, `data/catalog-meta.json` и `data/redirects.json`. Вместо этого сайт читает выгрузку как есть, одним файлом `data/catalog-export.json`, а представления (раздел, страница букета, плитки) строят функции `catalog-view.mjs`. Так нет второго формата, который мог бы разойтись с выгрузкой, и «тот же каталог» проверяется по одной версии sha256. Файлы Tilda остаются источником снимка до этапа 3.
2. **`make-catalog-export.mjs` появляется уже в 2В** (в спецификации он относится к переносу, этап 3). Сайт нужно переключить на выгрузку, не трогая каталог, а этот скрипт и даёт выгрузку из нынешних файлов. Раздела «Новинки» в снимке нет: его фото должны лечь на сервер в `images/catalog/novinki/`, а это этап 3. Главная до тех пор берёт «Новинки» из `site.json`.
3. **Цена 0 ₽ допустима у снятых с продажи.** У букета в продаже цена должна быть целой и больше нуля, у снятого — целой и не меньше нуля. Это нужно для 19 коробок без цены. Их страница цену не показывает, а в разметке для поисковиков у них нет предложения (`offers`).
4. **Сообщение в корзине о снятом букете** («уберите из корзины») переносится на этап 3. Для него нужно менять платёжную часть, а снятые букеты с ценой появятся только с админкой.

## Не входит в этот план (этап 3)

Раздел «Новинки» в выгрузке и перенос его фото. Удаление `site.newProducts` и `EXTRA_ITEMS`. Выгрузка с сервера в `deploy.yml`. Сообщение о снятом букете при оформлении заказа. Загрузка каталога в базу. Решение по ценам 19 коробок. Удаление `data/catalog/*.json` и `make-catalog-export.mjs` после переключения.

## Файлы

| Файл | За что отвечает |
|---|---|
| `scripts/lib/catalog-export.mjs` (+ тест) | Формат выгрузки: версия, канонический вид, проверки, запись файла |
| `tests/php/fixtures/catalog-export-small.json` | Выгрузка-образец; её проверяют и vitest, и PHP |
| `tests/php/catalog_export_js_test.php` | Та же версия на PHP и загрузка образца «туда-обратно» |
| `scripts/apply-catalog-export.mjs` (+ тест) | Проверка выгрузки и запись `data/catalog-export.json`; `--check` для prebuild |
| `scripts/make-catalog-export.mjs` (+ тест) | Снимок каталога из нынешних файлов |
| `data/catalog-export.json` | Снимок, который читает сайт |
| `tests/php/catalog_snapshot_test.php` | Снимок загружается в базу админки и выгружается тем же |
| `scripts/lib/catalog-view.mjs` (+ тест) | Как сайт видит выгрузку: раздел, страница букета, плитки, «Новинки», карты адресов |
| `scripts/catalog-roundtrip.test.mjs` | Сверка «туда-обратно»: снимок даёт тот же каталог, что старые файлы |
| `src/lib/catalog.ts` (+ тест) | Каталог для страниц, только на сервере сборки |
| `src/app/**`, `src/components/CategoryGrid`, `ProductCard`, `src/lib/seo.ts`, `content.ts`, `types.ts` | Страницы на выгрузке |
| `scripts/build-agent-assets.mjs`, `scripts/build-feed.mjs` | Служебные файлы и фид из выгрузки |
| `server-pay/catalog-redirect-lib.php`, `server-pay/catalog-redirect.php`, `server-pay/.htaccess`, `public/.htaccess` | Переадресации каталога на сервере |
| `scripts/compare-builds.mjs` | Сравнение двух сборок: тот же ли сайт |
| `docs/catalog.md`, `docs/known-follow-ups.md`, спецификация | Инструкция и задачи этапа 3 |

---

### Task 1: Формат выгрузки — версия и проверки

**Files:**
- Create: `scripts/lib/catalog-export.mjs`
- Create: `tests/php/fixtures/catalog-export-small.json`
- Test: `scripts/lib/catalog-export.test.mjs`
- Test: `tests/php/catalog_export_js_test.php`

**Interfaces:**
- Consumes: `server-pay/catalog/export.php` (`catalog_export_version`, `catalog_export_data`, `CATALOG_JSON`), `server-pay/catalog/import.php` (`catalog_import`), `tests/php/catalog_fixture.php` (`t_case`, `t_now`, `t_catalog_db`).
- Produces:
  - `RESERVED_SLUGS: string[]` — тот же список, что `CATALOG_RESERVED_SLUGS` в `server-pay/catalog/slug.php`
  - `SHRINK_LIMIT = 0.7`
  - `canonicalParts(exp): {sections, tiles, products, redirects}` — ключи в каноническом порядке
  - `exportVersion(exp): string` — sha256, hex
  - `formatExport(exp): string` — текст файла: `{version, changedAt, sections, tiles, products, redirects}`, отступ 2, `\n` в конце
  - `validateExport(exp, {previousCount?: number|null, allowShrink?: boolean}): string[]` — ошибки по-русски, `[]` — всё в порядке
  - образец `tests/php/fixtures/catalog-export-small.json` с версией `4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc`

- [ ] **Step 1: Образец выгрузки**

Create `tests/php/fixtures/catalog-export-small.json` (версия посчитана при составлении плана на JS и сверена с `catalog_export_version` на PHP):

```json
{
  "version": "4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc",
  "changedAt": "2026-10-05T14:32:10+05:00",
  "sections": [
    {
      "slug": "bukety",
      "label": "Букеты",
      "tileImage": "/images/site/catalog-tiles/tile-1.webp",
      "visible": true,
      "coverTitle": "Букеты",
      "coverSub": "Стильные и уникальные букеты",
      "covers": [
        "/images/site/category-covers/bukety-0.webp"
      ],
      "heading": "БУКЕТЫ",
      "headingSub": "",
      "hasNotFound": true,
      "seoTitle": null,
      "seoDescription": null,
      "products": [
        "100000000003",
        "100000000001",
        "100000000002"
      ]
    },
    {
      "slug": "novinki",
      "label": "Новинки",
      "tileImage": "",
      "visible": false,
      "coverTitle": "",
      "coverSub": "",
      "covers": [],
      "heading": "",
      "headingSub": "",
      "hasNotFound": false,
      "seoTitle": "Новинки салона «Пион»",
      "seoDescription": "Свежие букеты — «в\nдве строки» & слэш / и \"кавычки\"",
      "products": [
        "100000000003",
        "100000000002",
        "100000000004"
      ]
    },
    {
      "slug": "roses",
      "label": "Розы",
      "tileImage": "",
      "visible": false,
      "coverTitle": "Лучшее для дома\nи офиса",
      "coverSub": "",
      "covers": [],
      "heading": "",
      "headingSub": "",
      "hasNotFound": false,
      "seoTitle": null,
      "seoDescription": null,
      "products": [
        "100000000005"
      ]
    }
  ],
  "tiles": [
    {
      "type": "link",
      "label": "Цветы",
      "href": "/flowers",
      "image": "/images/site/catalog-tiles/tile-0.webp"
    },
    {
      "type": "section",
      "slug": "bukety"
    },
    {
      "type": "popup",
      "label": "Создать уникальный букет",
      "href": "#popup:individual",
      "image": "/images/site/catalog-tiles/tile-6.webp"
    }
  ],
  "products": [
    {
      "uid": "100000000001",
      "slug": "buket-a",
      "title": "Букет «А»",
      "description": "Состав букета: роза, эвкалипт",
      "price": 4400,
      "images": [
        "/images/catalog/bukety/buket-a-100000000001.webp"
      ],
      "mainSection": "bukety",
      "status": "active"
    },
    {
      "uid": "100000000002",
      "slug": "buket-b",
      "title": "Букет «Б»",
      "description": "",
      "price": 6380,
      "images": [
        "/images/catalog/bukety/buket-b-100000000002.webp",
        "/images/catalog/bukety/buket-b-100000000002-2.webp"
      ],
      "mainSection": "bukety",
      "status": "active"
    },
    {
      "uid": "100000000003",
      "slug": "buket-v",
      "title": "Букет «В»",
      "description": "Снят с продажи",
      "price": 5100,
      "images": [
        "/images/catalog/bukety/buket-v-100000000003.webp"
      ],
      "mainSection": "bukety",
      "status": "hidden"
    },
    {
      "uid": "100000000004",
      "slug": "pion",
      "title": "Пион",
      "description": "Привозим пионы даже в октябре!",
      "price": 1600,
      "images": [
        "/images/catalog/novinki/pion-100000000004-abcdef12.webp"
      ],
      "mainSection": "novinki",
      "status": "active"
    },
    {
      "uid": "100000000005",
      "slug": "pion",
      "title": "Пион",
      "description": "Пион сорта Сара Бернар",
      "price": 540,
      "images": [
        "/images/catalog/roses/pion-100000000005.webp"
      ],
      "mainSection": "roses",
      "status": "active"
    }
  ],
  "redirects": [
    {
      "from": "/korobki/buket-a/",
      "to": "/bukety/buket-a/"
    }
  ]
}
```

- [ ] **Step 2: Проверки (пока падают)**

Create `scripts/lib/catalog-export.test.mjs`:

```js
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  RESERVED_SLUGS,
  canonicalParts,
  exportVersion,
  formatExport,
  validateExport,
} from './catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const FIXTURE = path.join(root, 'tests/php/fixtures/catalog-export-small.json');
const VERSION = '4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc';

/** Образец без версии: правки в тестах не упираются в «версия не сходится». */
function sample() {
  const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
  delete exp.version;
  return exp;
}

describe('версия выгрузки', () => {
  it('совпадает с той, что считает PHP (tests/php/catalog_export_js_test.php)', () => {
    const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    expect(exportVersion(exp)).toBe(VERSION);
    expect(exp.version).toBe(VERSION);
  });

  it('не зависит от порядка ключей в присланном JSON', () => {
    const exp = sample();
    const shuffled = {
      ...exp,
      products: exp.products.map((p) => Object.fromEntries(Object.entries(p).reverse())),
      sections: exp.sections.map((s) => Object.fromEntries(Object.entries(s).reverse())),
    };
    expect(exportVersion(shuffled)).toBe(VERSION);
    expect(Object.keys(canonicalParts(shuffled).products[0])).toEqual([
      'uid', 'slug', 'title', 'description', 'price', 'images', 'mainSection', 'status',
    ]);
  });

  it('formatExport пишет версию, время и части в каноническом порядке', () => {
    const text = formatExport(sample());
    expect(text.endsWith('}\n')).toBe(true);
    const parsed = JSON.parse(text);
    expect(Object.keys(parsed)).toEqual(['version', 'changedAt', 'sections', 'tiles', 'products', 'redirects']);
    expect(parsed.version).toBe(VERSION);
    expect(parsed.changedAt).toBe('2026-10-05T14:32:10+05:00');
  });
});

describe('validateExport', () => {
  it('образец в порядке', () => {
    expect(validateExport(JSON.parse(readFileSync(FIXTURE, 'utf8')))).toEqual([]);
  });

  it('версия не сходится с содержимым — файл повреждён', () => {
    const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    exp.products[0].price = 4500;
    expect(validateExport(exp).join('\n')).toContain('Версия выгрузки не сходится');
  });

  it('нет части выгрузки', () => {
    const exp = sample();
    delete exp.tiles;
    expect(validateExport(exp)).toEqual(['В выгрузке нет части tiles.']);
  });

  const cases = [
    ['uid повторяется', (e) => { e.products[1].uid = '100000000001'; }, 'встречается дважды'],
    ['главного раздела нет', (e) => { e.products[0].mainSection = 'korobki'; }, 'главного раздела "korobki" нет'],
    ['букета нет в его главном разделе', (e) => { e.products[3].mainSection = 'roses'; }, 'его нет в его главном разделе roses'],
    ['два букета на одном адресе', (e) => { e.products[1].slug = 'buket-a'; }, 'Адрес /bukety/buket-a/ у двух букетов'],
    ['цена 0 у букета в продаже', (e) => { e.products[0].price = 0; }, 'цена 0'],
    ['цена дробная', (e) => { e.products[0].price = 4400.5; }, 'цена 4400.5'],
    ['статус черновика', (e) => { e.products[0].status = 'draft'; }, 'статус "draft"'],
    ['раздел на занятом адресе', (e) => { e.sections[2].slug = 'catalog'; e.products[4].mainSection = 'catalog'; }, 'совпадает с другой страницей сайта'],
    ['в разделе неизвестный букет', (e) => { e.sections[2].products.push('100000000009'); }, 'букета 100000000009 в выгрузке нет'],
    ['букеты не по порядку uid', (e) => { e.products.reverse(); }, 'не по порядку uid'],
    ['видимый раздел без плитки', (e) => { e.sections[2].visible = true; }, 'его плитки в сетке нет'],
    ['плитка скрытого раздела', (e) => { e.tiles.push({ type: 'section', slug: 'roses' }); }, 'Плитка скрытого раздела roses'],
    ['цепочка переадресаций', (e) => { e.redirects.push({ from: '/korobki/buket-z/', to: '/korobki/buket-a/' }); }, 'Цепочка переадресаций через /korobki/buket-a/'],
    ['переадресация с живой страницы', (e) => { e.redirects = [{ from: '/bukety/buket-a/', to: '/bukety/' }]; }, 'там живая страница букета'],
    ['чужой адрес в переадресации', (e) => { e.redirects = [{ from: '/korobki/x/', to: '//evil.example/' }]; }, 'Неправильная переадресация'],
    ['фото не из /images/', (e) => { e.products[0].images = ['https://evil.example/a.webp']; }, 'неправильные пути к фото'],
  ];
  for (const [name, mutate, message] of cases) {
    it(name, () => {
      const exp = sample();
      mutate(exp);
      expect(validateExport(exp).join('\n')).toContain(message);
    });
  }

  it('у снятого с продажи цена 0 допустима', () => {
    const exp = sample();
    exp.products[2].price = 0;
    expect(validateExport(exp)).toEqual([]);
  });

  it('защита от массовой потери: меньше 70% прошлого — стоп, allowShrink — можно', () => {
    expect(validateExport(sample(), { previousCount: 10 }).join('\n')).toContain('allow_shrink');
    expect(validateExport(sample(), { previousCount: 10, allowShrink: true })).toEqual([]);
    expect(validateExport(sample(), { previousCount: 7 })).toEqual([]);
  });
});

describe('занятые адреса', () => {
  it('тот же список, что CATALOG_RESERVED_SLUGS в server-pay/catalog/slug.php', () => {
    const php = readFileSync(path.join(root, 'server-pay/catalog/slug.php'), 'utf8');
    const body = php.match(/const CATALOG_RESERVED_SLUGS = \[([\s\S]*?)\];/)?.[1] ?? '';
    const list = [...body.matchAll(/'([^']+)'/g)].map((m) => m[1]);
    expect(list.length).toBeGreaterThan(10);
    expect(RESERVED_SLUGS).toEqual(list);
  });
});
```

Create `tests/php/catalog_export_js_test.php`:

```php
<?php
/**
 * Выгрузка, собранная на JS (образец tests/php/fixtures/catalog-export-small.json),
 * для PHP та же самая: версия совпадает, база загружает её и выгружает обратно
 * слово в слово. Тот же файл проверяет vitest (scripts/lib/catalog-export.test.mjs),
 * поэтому канонический JSON двух сторон не разъедется.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/import.php';

function t_small_export(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/catalog-export-small.json'), true, 64, JSON_THROW_ON_ERROR);
}

t_case('выгрузка-образец: версия на PHP та же, что на JS', function (): void {
    $e = t_small_export();
    t_equal(catalog_export_version($e), '4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc', 'тот же sha256, что в vitest');
    t_equal($e['version'], catalog_export_version($e), 'версия в файле сходится с содержимым');
});

t_case('выгрузка-образец: база загружает и выгружает её обратно', function (): void {
    $e = t_small_export();
    $db = t_catalog_db();
    catalog_import($db, $e, t_now());
    $want = ['sections' => $e['sections'], 'tiles' => $e['tiles'], 'products' => $e['products'], 'redirects' => $e['redirects']];
    t_equal(json_encode(catalog_export_data($db), CATALOG_JSON), json_encode($want, CATALOG_JSON), 'выгрузка из базы — тот же JSON');
});
```

- [ ] **Step 3: Убедиться, что падают**

Run: `npx vitest run scripts/lib/catalog-export.test.mjs`
Expected: FAIL — `Failed to load url ./catalog-export.mjs` (или «Cannot find module»).

Run: `/c/php82/php.exe tests/php/run.php`
Expected: PHP-часть уже проходит: образец сверен при составлении плана. Если она падает — остановиться и сообщить: значит, формат PHP изменился после составления плана.

- [ ] **Step 4: Реализация**

Create `scripts/lib/catalog-export.mjs`:

```js
/**
 * Выгрузка каталога — формат из спецификации (раздел 3), тот же, что отдаёт
 * server-pay/catalog/export.php. Здесь то, что нужно сборке сайта: версия
 * (sha256 канонического JSON), проверки перед сборкой и запись файла.
 *
 * Версия совпадает с PHP байт в байт. JSON.stringify пишет кириллицу и слэши
 * как есть, так же как json_encode с JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES. Ключи объектов идут в том же порядке, что в
 * catalog_export_data(). Сверка — tests/php/catalog_export_js_test.php
 * считает на PHP версию того же файла-образца.
 */
import { createHash } from 'node:crypto';

/**
 * Адреса сайта, которые раздел занять не может. Тот же список —
 * CATALOG_RESERVED_SLUGS в server-pay/catalog/slug.php (проверка в тесте).
 */
export const RESERVED_SLUGS = [
  'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000', 'blog', 'pay', 'api', 'images', 'md',
  'fonts', '_next', 'tproduct', 'tstore', 'feed',
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds', 'stock', 'policy',
  'doza_endorfina', 'flowers', 'indoorflowers',
];

/** Если букетов стало меньше этой доли от прошлой сборки, сборка останавливается. */
export const SHRINK_LIMIT = 0.7;

const SLUG = /^[a-z0-9][a-z0-9-]*$/;
const UID = /^[0-9]{6,20}$/;
// Как CATALOG_SITE_IMAGE в PHP: только /images/, без «..» и без корзины _deleted.
const IMAGE = /^\/images\/(?!.*\.\.)(?!(?:.*\/)?_deleted\/)[A-Za-z0-9/._-]+\.webp$/;
// Адрес страницы этого сайта: /раздел/ или /раздел/букет/.
const PATH = /^\/[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\/$/;
const STATUSES = ['active', 'hidden'];

const sectionOut = (s) => ({
  slug: s.slug,
  label: s.label,
  tileImage: s.tileImage,
  visible: s.visible,
  coverTitle: s.coverTitle,
  coverSub: s.coverSub,
  covers: s.covers,
  heading: s.heading,
  headingSub: s.headingSub,
  hasNotFound: s.hasNotFound,
  seoTitle: s.seoTitle,
  seoDescription: s.seoDescription,
  products: s.products,
});
const tileOut = (t) =>
  t.type === 'section'
    ? { type: t.type, slug: t.slug }
    : { type: t.type, label: t.label, href: t.href, image: t.image };
const productOut = (p) => ({
  uid: p.uid,
  slug: p.slug,
  title: p.title,
  description: p.description,
  price: p.price,
  images: p.images,
  mainSection: p.mainSection,
  status: p.status,
});
const redirectOut = (r) => ({ from: r.from, to: r.to });

/** Четыре части выгрузки с ключами в каноническом порядке — по ним считается версия. */
export function canonicalParts(exp) {
  return {
    sections: exp.sections.map(sectionOut),
    tiles: exp.tiles.map(tileOut),
    products: exp.products.map(productOut),
    redirects: exp.redirects.map(redirectOut),
  };
}

/** sha256 канонического JSON; version и changedAt в неё не входят. */
export function exportVersion(exp) {
  return createHash('sha256').update(JSON.stringify(canonicalParts(exp)), 'utf8').digest('hex');
}

/** Текст файла data/catalog-export.json: версия, время и части в каноническом порядке. */
export function formatExport(exp) {
  const parts = canonicalParts(exp);
  return `${JSON.stringify({ version: exportVersion(exp), changedAt: exp.changedAt ?? null, ...parts }, null, 2)}\n`;
}

const isStr = (v) => typeof v === 'string';
const ascending = (list, key) => list.every((x, i) => i === 0 || list[i - 1][key] < x[key]);

/**
 * Проверка выгрузки перед сборкой (спецификация, раздел 4, шаг 3). Пустой
 * список — выгрузку можно собирать; иначе ошибки по-русски, по одной на
 * строку.
 *
 * @param {any} exp
 * @param {{previousCount?: number|null, allowShrink?: boolean}} [opts]
 * @returns {string[]}
 */
export function validateExport(exp, { previousCount = null, allowShrink = false } = {}) {
  if (exp === null || typeof exp !== 'object' || Array.isArray(exp)) return ['Выгрузка — не объект JSON.'];
  const errors = [];
  for (const part of ['sections', 'tiles', 'products', 'redirects']) {
    if (!Array.isArray(exp[part])) errors.push(`В выгрузке нет части ${part}.`);
  }
  if (errors.length) return errors;
  if (exp.changedAt !== undefined && exp.changedAt !== null && !isStr(exp.changedAt)) {
    errors.push('changedAt — не строка.');
  }

  // Разделы.
  const sections = new Map();
  for (const s of exp.sections) {
    if (!s || !isStr(s.slug) || !SLUG.test(s.slug)) {
      errors.push(`Раздел с неправильным адресом: ${JSON.stringify(s?.slug)}.`);
      continue;
    }
    if (sections.has(s.slug)) errors.push(`Раздел ${s.slug} встречается дважды.`);
    if (RESERVED_SLUGS.includes(s.slug)) errors.push(`Раздел ${s.slug} совпадает с другой страницей сайта.`);
    if (!isStr(s.label) || s.label.trim() === '') errors.push(`У раздела ${s.slug} нет названия.`);
    for (const field of ['tileImage', 'coverTitle', 'coverSub', 'heading', 'headingSub']) {
      if (!isStr(s[field])) errors.push(`Раздел ${s.slug}: ${field} — не строка.`);
    }
    for (const field of ['seoTitle', 'seoDescription']) {
      if (s[field] !== null && !isStr(s[field])) errors.push(`Раздел ${s.slug}: ${field} — не строка и не null.`);
    }
    if (typeof s.visible !== 'boolean' || typeof s.hasNotFound !== 'boolean') {
      errors.push(`Раздел ${s.slug}: visible и hasNotFound должны быть true или false.`);
    }
    if (!Array.isArray(s.covers) || !s.covers.every((c) => isStr(c) && IMAGE.test(c))) {
      errors.push(`Раздел ${s.slug}: неправильные фото обложки.`);
    }
    if (isStr(s.tileImage) && s.tileImage !== '' && !IMAGE.test(s.tileImage)) {
      errors.push(`Раздел ${s.slug}: неправильное фото плитки.`);
    }
    if (!Array.isArray(s.products) || !s.products.every(isStr)) {
      errors.push(`Раздел ${s.slug}: список букетов — не список uid.`);
      continue;
    }
    if (new Set(s.products).size !== s.products.length) errors.push(`Раздел ${s.slug}: один букет записан дважды.`);
    sections.set(s.slug, s);
  }
  if (!ascending(exp.sections.filter((s) => s && isStr(s.slug)), 'slug')) {
    errors.push('Разделы не по алфавиту адресов — выгрузка не в каноническом виде.');
  }

  // Букеты.
  const products = new Map();
  const addresses = new Set();
  for (const p of exp.products) {
    if (!p || !isStr(p.uid) || !UID.test(p.uid)) {
      errors.push(`Букет с неправильным uid: ${JSON.stringify(p?.uid)}.`);
      continue;
    }
    const name = `Букет ${p.uid}`;
    if (products.has(p.uid)) errors.push(`${name} встречается дважды.`);
    products.set(p.uid, p);
    if (!isStr(p.slug) || !SLUG.test(p.slug)) errors.push(`${name}: неправильный адрес ${JSON.stringify(p.slug)}.`);
    if (!isStr(p.title) || p.title.trim() === '') errors.push(`${name}: нет названия.`);
    if (!isStr(p.description)) errors.push(`${name}: состав — не строка.`);
    if (!STATUSES.includes(p.status)) {
      errors.push(`${name}: статус ${JSON.stringify(p.status)} — в выгрузке бывают только active и hidden.`);
    }
    if (!Number.isInteger(p.price) || p.price < 0 || (p.status === 'active' && p.price === 0)) {
      errors.push(`${name}: цена ${JSON.stringify(p.price)} — нужна целая и больше нуля (у снятого с продажи можно 0).`);
    }
    if (!Array.isArray(p.images) || !p.images.every((i) => isStr(i) && IMAGE.test(i))) {
      errors.push(`${name}: неправильные пути к фото.`);
    }
    const main = sections.get(p.mainSection);
    if (!main) errors.push(`${name}: главного раздела ${JSON.stringify(p.mainSection)} нет.`);
    else if (!main.products.includes(p.uid)) errors.push(`${name}: его нет в его главном разделе ${p.mainSection}.`);
    const address = `/${p.mainSection}/${p.slug}/`;
    if (addresses.has(address)) errors.push(`Адрес ${address} у двух букетов.`);
    addresses.add(address);
  }
  if (!ascending(exp.products.filter((p) => p && isStr(p.uid)), 'uid')) {
    errors.push('Букеты не по порядку uid — выгрузка не в каноническом виде.');
  }
  for (const s of sections.values()) {
    for (const uid of s.products) {
      if (!products.has(uid)) errors.push(`Раздел ${s.slug}: букета ${uid} в выгрузке нет.`);
    }
  }

  // Плитки сетки каталога.
  const inGrid = new Set();
  for (const t of exp.tiles) {
    if (t?.type === 'section') {
      const s = sections.get(t.slug);
      if (!s) errors.push(`Плитка ведёт в раздел ${JSON.stringify(t.slug)}, которого нет.`);
      else if (!s.visible) errors.push(`Плитка скрытого раздела ${t.slug} попала в сетку.`);
      if (inGrid.has(t.slug)) errors.push(`У раздела ${t.slug} две плитки.`);
      inGrid.add(t.slug);
    } else if (t?.type === 'link' || t?.type === 'popup') {
      if (!isStr(t.label) || t.label.trim() === '' || !isStr(t.image) || !IMAGE.test(t.image)) {
        errors.push(`Плитка ${JSON.stringify(t.label)}: нет подписи или фото.`);
      }
      const hrefOk = isStr(t.href) && (t.type === 'popup' ? t.href.startsWith('#') : t.href.startsWith('/'));
      if (!hrefOk) errors.push(`Плитка ${JSON.stringify(t.label)}: неправильная ссылка ${JSON.stringify(t.href)}.`);
    } else {
      errors.push(`Плитка неизвестного вида: ${JSON.stringify(t?.type)}.`);
    }
  }
  for (const s of sections.values()) {
    if (s.visible && !inGrid.has(s.slug)) errors.push(`Раздел ${s.slug} показывается в каталоге, но его плитки в сетке нет.`);
  }

  // Переадресации.
  const froms = new Set();
  for (const r of exp.redirects) {
    if (!r || !isStr(r.from) || !isStr(r.to) || !PATH.test(r.from) || !PATH.test(r.to)) {
      errors.push(`Неправильная переадресация ${JSON.stringify(r)}.`);
      continue;
    }
    if (froms.has(r.from)) errors.push(`Переадресация с ${r.from} записана дважды.`);
    froms.add(r.from);
    if (r.from === r.to) errors.push(`Переадресация ${r.from} ведёт сама на себя.`);
    if (addresses.has(r.from)) errors.push(`С адреса ${r.from} стоит переадресация, но там живая страница букета.`);
  }
  for (const r of exp.redirects) {
    if (r && froms.has(r.to)) errors.push(`Цепочка переадресаций через ${r.to}.`);
  }
  if (!ascending(exp.redirects.filter((r) => r && isStr(r.from)), 'from')) {
    errors.push('Переадресации не по порядку — выгрузка не в каноническом виде.');
  }

  // Версию сверяем, только когда сама выгрузка цела: иначе посчитать её нельзя.
  if (errors.length === 0 && exp.version !== undefined && exp.version !== exportVersion(exp)) {
    errors.push('Версия выгрузки не сходится с содержимым — файл повреждён.');
  }

  // Защита от массовой потери: салон редко удаляет треть каталога разом.
  if (previousCount !== null && previousCount > 0 && !allowShrink && exp.products.length < previousCount * SHRINK_LIMIT) {
    const percent = Math.round((1 - exp.products.length / previousCount) * 100);
    errors.push(
      `Букетов ${exp.products.length} вместо ${previousCount} — меньше на ${percent}%. ` +
        'Если салон и правда столько удалил, запустите сборку с allow_shrink.',
    );
  }
  return errors;
}
```

- [ ] **Step 5: Убедиться, что проходят**

Run: `npx vitest run scripts/lib/catalog-export.test.mjs`
Expected: PASS, все проверки.

Run: `npm test && /c/php82/php.exe tests/php/run.php`
Expected: vitest — все PASS; PHP — `не прошло: 0`.

- [ ] **Step 6: Коммит**

```bash
git add scripts/lib/catalog-export.mjs scripts/lib/catalog-export.test.mjs tests/php/fixtures/catalog-export-small.json tests/php/catalog_export_js_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): формат выгрузки на стороне сайта — версия как в PHP и проверки перед сборкой

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Скрипт `apply-catalog-export.mjs`

**Files:**
- Create: `scripts/apply-catalog-export.mjs`
- Test: `scripts/apply-catalog-export.test.mjs`

**Interfaces:**
- Consumes: `validateExport`, `formatExport` (Task 1).
- Produces:
  - `applyCatalogExport({source: string, out?: string, allowShrink?: boolean, check?: boolean}): Promise<{ok: boolean, errors: string[], products?: number, sections?: number, version?: string}>`
  - CLI `node scripts/apply-catalog-export.mjs <файл|https://…> [--out файл] [--allow-shrink]` и `--check [файл]`. Выход: 0 — принято, 1 — выгрузка не принята, 2 — неверный вызов. Файл по умолчанию — `data/catalog-export.json`.

- [ ] **Step 1: Проверки (пока падают)**

Create `scripts/apply-catalog-export.test.mjs`:

```js
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { applyCatalogExport } from './apply-catalog-export.mjs';
import { formatExport } from './lib/catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(root, 'scripts/apply-catalog-export.mjs');
const FIXTURE = path.join(root, 'tests/php/fixtures/catalog-export-small.json');
const tmp = () => mkdtempSync(path.join(os.tmpdir(), 'pion-apply-'));

describe('applyCatalogExport', () => {
  it('принимает выгрузку и пишет её в каноническом виде', async () => {
    const out = path.join(tmp(), 'catalog-export.json');
    const r = await applyCatalogExport({ source: FIXTURE, out });
    expect(r).toMatchObject({ ok: true, errors: [], products: 5, sections: 3 });
    expect(readFileSync(out, 'utf8')).toBe(formatExport(JSON.parse(readFileSync(FIXTURE, 'utf8'))));
  });

  it('плохая выгрузка — не принята, файл не тронут', async () => {
    const dir = tmp();
    const bad = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    bad.products[1].uid = bad.products[0].uid;
    writeFileSync(path.join(dir, 'bad.json'), JSON.stringify(bad));
    const out = path.join(dir, 'catalog-export.json');
    const r = await applyCatalogExport({ source: path.join(dir, 'bad.json'), out });
    expect(r.ok).toBe(false);
    expect(r.errors.join('\n')).toContain('встречается дважды');
    expect(existsSync(out)).toBe(false);
  });

  it('букетов на 30% меньше, чем в лежащем файле, — стоп; allowShrink — можно', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    writeFileSync(out, JSON.stringify({ products: Array.from({ length: 10 }, (_, i) => ({ uid: String(i) })) }));
    const stopped = await applyCatalogExport({ source: FIXTURE, out });
    expect(stopped.ok).toBe(false);
    expect(stopped.errors.join('\n')).toContain('allow_shrink');
    expect((await applyCatalogExport({ source: FIXTURE, out, allowShrink: true })).ok).toBe(true);
  });

  it('--check проверяет на месте и ничего не пишет', async () => {
    const out = path.join(tmp(), 'never.json');
    const r = await applyCatalogExport({ source: FIXTURE, out, check: true });
    expect(r.ok).toBe(true);
    expect(existsSync(out)).toBe(false);
  });

  it('файла нет — понятная ошибка', async () => {
    const r = await applyCatalogExport({ source: path.join(tmp(), 'missing.json'), out: path.join(tmp(), 'x.json') });
    expect(r.ok).toBe(false);
    expect(r.errors[0]).toMatch(/^Не удалось прочитать выгрузку/);
  });
});

describe('запуск из командной строки', () => {
  const run = (...args) => spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8' });

  it('--check на хорошем файле — код 0', () => {
    const r = run('--check', FIXTURE);
    expect(r.status).toBe(0);
    expect(r.stdout).toContain('в порядке');
  });

  it('плохой файл — код 1 и список ошибок', () => {
    const dir = tmp();
    const bad = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    bad.products[0].price = 0;
    writeFileSync(path.join(dir, 'bad.json'), JSON.stringify(bad));
    const r = run('--check', path.join(dir, 'bad.json'));
    expect(r.status).toBe(1);
    expect(r.stderr).toContain('Выгрузка не принята');
  });

  it('без аргументов — код 2 и подсказка', () => {
    const r = run();
    expect(r.status).toBe(2);
    expect(r.stderr).toContain('Как вызывать');
  });
});
```

- [ ] **Step 2: Убедиться, что падают**

Run: `npx vitest run scripts/apply-catalog-export.test.mjs`
Expected: FAIL — модуль `./apply-catalog-export.mjs` не найден.

- [ ] **Step 3: Реализация**

Create `scripts/apply-catalog-export.mjs`:

```js
/**
 * Проверка выгрузки каталога и запись её для сборки сайта.
 *
 *   node scripts/apply-catalog-export.mjs <файл или https://…> [--out data/catalog-export.json] [--allow-shrink]
 *   node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]
 *
 * Первая форма — для сборки: проверить и записать. На этапе 3 сюда придёт
 * выгрузка с сервера. Защита от массовой потери сравнивает число букетов с
 * файлом, который сейчас лежит по адресу --out. Вторая форма проверяет файл
 * на месте и ничего не меняет — так делает prebuild.
 *
 * Код выхода: 0 — принято, 1 — выгрузка не принята, 2 — неверный вызов.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatExport, validateExport } from './lib/catalog-export.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DEFAULT_FILE = path.join(ROOT, 'data', 'catalog-export.json');
const USAGE = [
  'Как вызывать:',
  '  node scripts/apply-catalog-export.mjs <файл или https://адрес> [--out data/catalog-export.json] [--allow-shrink]',
  '  node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]',
].join('\n');

async function load(source) {
  if (/^https?:\/\//.test(source)) {
    const res = await fetch(source);
    if (!res.ok) throw new Error(`сервер ответил ${res.status}`);
    return JSON.parse(await res.text());
  }
  return JSON.parse(readFileSync(source, 'utf8'));
}

/** Сколько букетов в файле, который лежит сейчас; null — файла нет или он не читается. */
function previousCount(file) {
  if (!existsSync(file)) return null;
  try {
    const products = JSON.parse(readFileSync(file, 'utf8')).products;
    return Array.isArray(products) ? products.length : null;
  } catch {
    return null;
  }
}

export async function applyCatalogExport({ source, out = DEFAULT_FILE, allowShrink = false, check = false }) {
  let exp;
  try {
    exp = await load(source);
  } catch (error) {
    return { ok: false, errors: [`Не удалось прочитать выгрузку ${source}: ${error.message}`] };
  }
  const errors = validateExport(exp, { previousCount: check ? null : previousCount(out), allowShrink });
  if (errors.length) return { ok: false, errors };
  const text = formatExport(exp);
  if (!check) writeFileSync(out, text);
  return {
    ok: true,
    errors: [],
    products: exp.products.length,
    sections: exp.sections.length,
    version: JSON.parse(text).version,
  };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const positional = [];
  let out = DEFAULT_FILE;
  let check = false;
  let allowShrink = false;
  let bad = false;
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--check') check = true;
    else if (args[i] === '--allow-shrink') allowShrink = true;
    else if (args[i] === '--out' && args[i + 1]) out = path.resolve(args[++i]);
    else if (args[i].startsWith('--')) bad = true;
    else positional.push(args[i]);
  }
  const source = positional[0] ?? (check ? DEFAULT_FILE : undefined);
  if (bad || !source || positional.length > 1) {
    console.error(USAGE);
    process.exit(2);
  }
  const r = await applyCatalogExport({ source, out, allowShrink, check });
  if (!r.ok) {
    console.error('Выгрузка не принята:');
    for (const error of r.errors.slice(0, 30)) console.error(`- ${error}`);
    if (r.errors.length > 30) console.error(`…и ещё ${r.errors.length - 30}.`);
    process.exit(1);
  }
  const summary = `разделов ${r.sections}, букетов ${r.products}, версия ${r.version.slice(0, 12)}`;
  console.log(check ? `Выгрузка в порядке: ${summary}.` : `Записано в ${path.relative(ROOT, out)}: ${summary}.`);
}
```

- [ ] **Step 4: Убедиться, что проходят**

Run: `npx vitest run scripts/apply-catalog-export.test.mjs && npm test`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add scripts/apply-catalog-export.mjs scripts/apply-catalog-export.test.mjs
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): apply-catalog-export — проверка выгрузки и запись для сборки

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Снимок каталога из нынешних файлов

**Files:**
- Create: `scripts/make-catalog-export.mjs`
- Create: `data/catalog-export.json` (генерируется скриптом)
- Modify: `package.json` (скрипт `catalog:snapshot`)
- Test: `scripts/make-catalog-export.test.mjs`
- Test: `tests/php/catalog_snapshot_test.php`

**Interfaces:**
- Consumes: `exportVersion`, `formatExport`, `validateExport` (Task 1); `dedupeProducts` (`scripts/lib/dedupeProducts.mjs`).
- Produces:
  - `SECTION_LABELS: Record<string, string>` — 13 названий разделов (раньше это был `CATEGORY_LABELS`)
  - `SECTION_SEO: Record<string, {title, description}>` — три раздела с заголовками для поиска, написанными вручную (раньше это был `PAGE_SEO`)
  - `makeCatalogExport(root?: string): object` — выгрузка с полем `version`
  - `data/catalog-export.json` — 13 разделов, 12 плиток, 487 букетов, из них 19 сняты с продажи, переадресаций нет, `changedAt: null`
  - `npm run catalog:snapshot`

- [ ] **Step 1: Проверки (пока падают)**

Create `scripts/make-catalog-export.test.mjs`:

```js
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { SECTION_LABELS, SECTION_SEO, makeCatalogExport } from './make-catalog-export.mjs';
import { exportVersion, formatExport, validateExport } from './lib/catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const snapshot = JSON.parse(readFileSync(path.join(root, 'data/catalog-export.json'), 'utf8'));

describe('снимок каталога data/catalog-export.json', () => {
  it('не устарел: совпадает с тем, что собирается из data/catalog/*.json (иначе — npm run catalog:snapshot)', () => {
    expect(snapshot).toEqual(JSON.parse(formatExport(makeCatalogExport(root))));
  });

  it('проходит проверку перед сборкой, версия сходится', () => {
    expect(validateExport(snapshot)).toEqual([]);
    expect(snapshot.version).toBe(exportVersion(snapshot));
    expect(snapshot.changedAt).toBeNull();
    expect(snapshot.redirects).toEqual([]);
  });

  it('13 разделов, 12 плиток, 487 букетов; без цены — только 19 коробок, и они сняты', () => {
    expect(snapshot.sections.map((s) => s.slug)).toEqual(Object.keys(SECTION_LABELS).sort());
    expect(snapshot.tiles).toHaveLength(12);
    expect(snapshot.products).toHaveLength(487);
    const hidden = snapshot.products.filter((p) => p.status === 'hidden');
    expect(hidden).toHaveLength(19);
    expect(hidden.every((p) => p.price === 0 && p.mainSection === 'korobki')).toBe(true);
    expect(snapshot.products.filter((p) => p.status === 'active').every((p) => p.price > 0)).toBe(true);
  });

  it('в каталоге видны те 8 разделов, у которых есть плитка', () => {
    expect(snapshot.sections.filter((s) => s.visible).map((s) => s.slug).sort()).toEqual(
      ['balloons', 'bukety', 'chocolate', 'flame', 'korobki', 'korziny', 'luchshee', 'wedding'],
    );
  });

  it('заголовки для поиска написаны вручную только у трёх сезонных разделов', () => {
    const custom = snapshot.sections.filter((s) => s.seoTitle !== null).map((s) => s.slug);
    expect(custom.sort()).toEqual(['new-year-2025', 'valentinesday', 'wedding']);
    expect(snapshot.sections.find((s) => s.slug === 'wedding').seoTitle).toBe(SECTION_SEO.wedding.title);
  });
});
```

Create `tests/php/catalog_snapshot_test.php`:

```php
<?php
/**
 * Снимок каталога data/catalog-export.json (его читает сайт) годится и для
 * базы админки: версия на PHP та же, загрузка в пустую базу проходит, база
 * выгружает его обратно слово в слово. Это вторая проверка переноса из
 * спецификации — заранее, до этапа 3.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/import.php';

t_case('снимок каталога: база загружает и выгружает его тем же', function (): void {
    $e = json_decode((string)file_get_contents(__DIR__ . '/../../data/catalog-export.json'), true, 64, JSON_THROW_ON_ERROR);
    t_equal(catalog_export_version($e), $e['version'], 'версия на PHP та же, что посчитал JS');
    $db = t_catalog_db();
    $r = catalog_import($db, $e, t_now());
    t_equal([$r['sections'], $r['products']], [13, 487], 'загружены все разделы и букеты');
    $want = ['sections' => $e['sections'], 'tiles' => $e['tiles'], 'products' => $e['products'], 'redirects' => $e['redirects']];
    t_true(json_encode(catalog_export_data($db), CATALOG_JSON) === json_encode($want, CATALOG_JSON), 'выгрузка из базы — тот же JSON');
});
```

- [ ] **Step 2: Убедиться, что падают**

Run: `npx vitest run scripts/make-catalog-export.test.mjs`
Expected: FAIL — нет `data/catalog-export.json` (ENOENT) или модуля `./make-catalog-export.mjs`.

- [ ] **Step 3: Реализация**

Create `scripts/make-catalog-export.mjs`:

```js
/**
 * Снимок каталога из нынешних файлов репозитория в формате выгрузки админки
 * (спецификация: раздел 3 и «Перенос нынешнего каталога»).
 *
 *   npm run catalog:snapshot   (= node scripts/make-catalog-export.mjs --out data/catalog-export.json)
 *
 * С этапа 2В сайт читает только data/catalog-export.json. Пока каталог не
 * переехал в базу (этап 3), источник снимка — data/catalog/*.json (выгрузка
 * из Tilda) и data/catalog-meta.json. После их правки снимок нужно собрать
 * заново, иначе scripts/make-catalog-export.test.mjs упадёт и напомнит.
 *
 * Правила переноса:
 * - 13 разделов. Названия — те, что были в коде сайта (CATEGORY_LABELS),
 *   обложки и тексты — из catalog-meta.json, пустые поля — пустые строки;
 * - у трёх сезонных разделов свои заголовки для поиска (были в PAGE_SEO);
 * - 12 плиток в нынешнем порядке; у раздела без плитки — «не показывать в каталоге»;
 * - букеты — после dedupeProducts, в порядке файлов; главный раздел — тот, где
 *   букет лежит. Букет с ценой 0 ₽ (19 «Цветочных коробок»: в Tilda они без
 *   цены и выключены) снят с продажи — страница остаётся, заказать нельзя;
 * - раздела «Новинки» здесь нет: он и его фото появятся при переносе в базу
 *   (этап 3), а до тех пор главная берёт «Новинки» из data/site.json.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { dedupeProducts } from './lib/dedupeProducts.mjs';
import { exportVersion, formatExport, validateExport } from './lib/catalog-export.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Названия разделов — были CATEGORY_LABELS в src/lib/content.ts. */
export const SECTION_LABELS = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
  valentinesday: 'Букеты и боксы к 14 февраля',
  'new-year-2025': 'Новогодняя коллекция',
};

/**
 * Заголовки для поиска, написанные вручную, — были в PAGE_SEO
 * (src/app/[slug]/page.tsx). Сезонные разделы раньше были отдельными
 * страницами. «Букет невесты» и «свадебный букет» — один товар, но первое
 * ищут втрое чаще, поэтому оно в заголовке.
 */
export const SECTION_SEO = {
  wedding: {
    title: 'Букет невесты и свадебные букеты в Перми | «Пион»',
    description:
      'Букет невесты, бутоньерка жениха и цветы для церемонии от салона «Пион» в Перми. Собираем в день свадьбы, привозим ко времени сбора невесты.',
  },
  valentinesday: {
    title: 'Букеты на 14 февраля в Перми | Салон «Пион»',
    description:
      'Букеты и подарочные боксы к 14 февраля от салона «Пион» в Перми. Композиции из свежих цветов, доставка по городу и самовывоз со скидкой 5%.',
  },
  'new-year-2025': {
    title: 'Новогодние букеты 2025 в Перми | Салон «Пион»',
    description:
      'Новогодняя коллекция 2025 салона цветов «Пион» в Перми: еловые композиции, зимние букеты и подарочные боксы с доставкой по городу.',
  },
};

const slugOfHref = (href) => href.replace(/^\//, '').replace(/\/$/, '');

export function makeCatalogExport(root = ROOT) {
  const read = (rel) => JSON.parse(readFileSync(path.join(root, rel), 'utf8'));
  const meta = read('data/catalog-meta.json');

  const tileImages = new Map();
  const tiles = meta.tiles.map((t) => {
    if (t.href.startsWith('#')) return { type: 'popup', label: t.label, href: t.href, image: t.image };
    const slug = slugOfHref(t.href);
    if (Object.hasOwn(SECTION_LABELS, slug)) {
      tileImages.set(slug, t.image);
      return { type: 'section', slug };
    }
    return { type: 'link', label: t.label, href: t.href, image: t.image };
  });

  const sections = [];
  const products = [];
  for (const slug of Object.keys(SECTION_LABELS).sort()) {
    const list = dedupeProducts(read(`data/catalog/${slug}.json`));
    const m = meta.categories[slug] ?? {};
    const seo = SECTION_SEO[slug] ?? null;
    sections.push({
      slug,
      label: SECTION_LABELS[slug],
      tileImage: tileImages.get(slug) ?? '',
      visible: tileImages.has(slug),
      coverTitle: m.title ?? '',
      coverSub: m.sub ?? '',
      covers: m.covers ?? [],
      heading: m.heading ?? '',
      headingSub: m.headingSub ?? '',
      hasNotFound: m.hasNotFound ?? false,
      seoTitle: seo?.title ?? null,
      seoDescription: seo?.description ?? null,
      products: list.map((p) => String(p.uid)),
    });
    for (const p of list) {
      products.push({
        uid: String(p.uid),
        slug: p.slug,
        title: p.title,
        description: p.description ?? '',
        price: p.price,
        images: p.images,
        mainSection: slug,
        status: p.price > 0 ? 'active' : 'hidden',
      });
    }
  }
  products.sort((a, b) => (a.uid < b.uid ? -1 : a.uid > b.uid ? 1 : 0));

  const exp = { changedAt: null, sections, tiles, products, redirects: [] };
  return { version: exportVersion(exp), ...exp };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const at = process.argv.indexOf('--out');
  const exp = makeCatalogExport();
  const errors = validateExport(exp);
  if (errors.length) {
    console.error('Снимок не прошёл проверку:');
    for (const error of errors) console.error(`- ${error}`);
    process.exit(1);
  }
  if (at > 0 && process.argv[at + 1]) {
    writeFileSync(path.resolve(process.argv[at + 1]), formatExport(exp));
    console.log(`Снимок каталога: разделов ${exp.sections.length}, букетов ${exp.products.length}, версия ${exp.version.slice(0, 12)}.`);
  } else {
    process.stdout.write(formatExport(exp));
  }
}
```

In `package.json`, add to `"scripts"` after `"pack"`:

```json
    "catalog:snapshot": "node scripts/make-catalog-export.mjs --out data/catalog-export.json",
```

Run: `npm run catalog:snapshot`
Expected: `Снимок каталога: разделов 13, букетов 487, версия …` и файл `data/catalog-export.json` (около 250 КБ).

- [ ] **Step 4: Убедиться, что проходят**

Run: `npx vitest run scripts/make-catalog-export.test.mjs && npm test && /c/php82/php.exe tests/php/run.php`
Expected: vitest — PASS; PHP — `не прошло: 0` (в том числе «снимок каталога: база загружает и выгружает его тем же»).

- [ ] **Step 5: Коммит**

```bash
git add scripts/make-catalog-export.mjs scripts/make-catalog-export.test.mjs data/catalog-export.json package.json tests/php/catalog_snapshot_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): снимок каталога в формате выгрузки из нынешних файлов

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Как сайт читает выгрузку

**Files:**
- Create: `scripts/lib/catalog-view.mjs`
- Create: `src/lib/catalog.ts`
- Modify: `src/lib/types.ts` (поля `mainSection`, `status` у `Product`)
- Test: `scripts/lib/catalog-view.test.mjs`
- Test: `scripts/catalog-roundtrip.test.mjs`
- Test: `src/lib/catalog.test.ts`

**Interfaces:**
- Consumes: снимок `data/catalog-export.json` и образец (Tasks 1, 3); `RESERVED_SLUGS` (Task 1); `PAGE_SLUGS` из `src/lib/content.ts`.
- Produces (`scripts/lib/catalog-view.mjs`, чистые функции над выгрузкой `exp`):
  - `NEW_SECTION = 'novinki'`
  - `productPath(section, slug): string` — `/${section}/${slug}/`
  - `findSection(exp, slug): section|null`
  - `sectionProducts(exp, slug): product[]` — букеты в продаже в порядке раздела
  - `findProduct(exp, section, slug): product|null` — по главному разделу; в продаже и снятые
  - `productPages(exp): {slug, product}[]` — все страницы букетов, по разделам в их порядке
  - `relatedProducts(exp, product, limit = 4): product[]`
  - `ambiguousTitles(exp): Set<string>`
  - `catalogTiles(exp): {label, href, image}[]`
  - `sectionMeta(section): {title, sub, covers, heading, headingSub, hasNotFound}` — пустая строка становится `null`
  - `newProducts(exp, limit = 3): product[] | null`
- Produces (`src/lib/catalog.ts`, только для серверной части сборки):
  - типы `CatalogSection`, `CatalogProduct` (`Product` + `mainSection: string`, `status: 'active' | 'hidden'`), `CatalogExport`
  - `getSectionSlugs(): string[]`, `getSection(slug): CatalogSection | null`, `getSectionLabel(slug): string`
  - `getCatalog(slug): CatalogProduct[] | null`, `getProduct(section, slug): CatalogProduct | null`
  - `getRelatedProducts(product, limit = 4): CatalogProduct[]`, `getAllProductParams(): {slug, product}[]`
  - `getAmbiguousTitles(): Set<string>`, `getCatalogTiles(): CatalogTile[]`, `getCategoryMeta(slug): CategoryMeta | null`
  - `getNewProducts(limit = 3): CatalogProduct[] | null`
- `Product` (`src/lib/types.ts`) получает необязательные поля `mainSection?: string`, `status?: 'active' | 'hidden'`.

Страницы на новый модуль переводят Tasks 5–6. До тех пор `content.ts` не трогается, и сборка остаётся рабочей.

- [ ] **Step 1: Проверки (пока падают)**

Create `scripts/lib/catalog-view.test.mjs`:

```js
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  newProducts,
  productPages,
  productPath,
  relatedProducts,
  sectionMeta,
  sectionProducts,
} from './catalog-view.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const load = () => JSON.parse(readFileSync(path.join(root, 'tests/php/fixtures/catalog-export-small.json'), 'utf8'));
const uids = (list) => list.map((p) => p.uid);

describe('catalog-view на выгрузке-образце', () => {
  it('в разделе — только букеты в продаже, в порядке раздела', () => {
    expect(uids(sectionProducts(load(), 'bukety'))).toEqual(['100000000001', '100000000002']);
    expect(uids(sectionProducts(load(), 'novinki'))).toEqual(['100000000002', '100000000004']);
    expect(sectionProducts(load(), 'nope')).toEqual([]);
  });

  it('страница букета — по главному разделу; у снятого она тоже есть', () => {
    expect(findProduct(load(), 'bukety', 'buket-v')?.status).toBe('hidden');
    expect(findProduct(load(), 'novinki', 'buket-b')).toBeNull();
    expect(productPath('bukety', 'buket-a')).toBe('/bukety/buket-a/');
  });

  it('все страницы букетов: по одной на букет, по главному разделу', () => {
    expect(productPages(load())).toEqual([
      { slug: 'bukety', product: 'buket-v' },
      { slug: 'bukety', product: 'buket-a' },
      { slug: 'bukety', product: 'buket-b' },
      { slug: 'novinki', product: 'pion' },
      { slug: 'roses', product: 'pion' },
    ]);
  });

  it('похожие — букеты в продаже из главного раздела, без самого букета', () => {
    const exp = load();
    expect(uids(relatedProducts(exp, findProduct(exp, 'bukety', 'buket-a')))).toEqual(['100000000002']);
    expect(uids(relatedProducts(exp, findProduct(exp, 'bukety', 'buket-v')))).toEqual(['100000000001', '100000000002']);
  });

  it('одноимённые — по главным разделам, а не по разделам вообще', () => {
    expect(ambiguousTitles(load())).toEqual(new Set(['пион']));
  });

  it('плитки сетки: у раздела — его название, адрес и фото плитки', () => {
    expect(catalogTiles(load())).toEqual([
      { label: 'Цветы', href: '/flowers', image: '/images/site/catalog-tiles/tile-0.webp' },
      { label: 'Букеты', href: '/bukety', image: '/images/site/catalog-tiles/tile-1.webp' },
      { label: 'Создать уникальный букет', href: '#popup:individual', image: '/images/site/catalog-tiles/tile-6.webp' },
    ]);
  });

  it('оформление раздела: пустые поля — null, как было в catalog-meta.json', () => {
    expect(sectionMeta(findSection(load(), 'roses'))).toEqual({
      title: 'Лучшее для дома\nи офиса',
      sub: null,
      covers: [],
      heading: null,
      headingSub: null,
      hasNotFound: false,
    });
  });

  it('«Новинки»: первые букеты в продаже; снятые пропускаются; нет раздела — null; пусто — []', () => {
    expect(uids(newProducts(load()))).toEqual(['100000000002', '100000000004']);
    expect(uids(newProducts(load(), 1))).toEqual(['100000000002']);
    const without = load();
    without.sections = without.sections.filter((s) => s.slug !== 'novinki');
    expect(newProducts(without)).toBeNull();
    const empty = load();
    empty.sections.find((s) => s.slug === 'novinki').products = ['100000000003'];
    expect(newProducts(empty)).toEqual([]);
  });
});
```

Create `scripts/catalog-roundtrip.test.mjs`:

```js
/**
 * Сверка «туда-обратно» (спецификация, «Перенос нынешнего каталога», п. 2):
 * снимок data/catalog-export.json, прочитанный так, как его читает сайт,
 * даёт тот же каталог, что старые файлы Tilda, прочитанные по-старому:
 * те же разделы, букеты в том же порядке, те же цены, фото и адреса.
 * Единственное отличие — 19 коробок с ценой 0 ₽: их нет в разделе, но
 * страница у них осталась.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { dedupeProducts } from './lib/dedupeProducts.mjs';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  productPages,
  sectionMeta,
  sectionProducts,
} from './lib/catalog-view.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel) => JSON.parse(readFileSync(path.join(root, rel), 'utf8'));
const exp = read('data/catalog-export.json');
const meta = read('data/catalog-meta.json');

/** Названия разделов из кода сайта до этапа 2В (CATEGORY_LABELS). */
const LABELS_BEFORE = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
  valentinesday: 'Букеты и боксы к 14 февраля',
  'new-year-2025': 'Новогодняя коллекция',
};
const before = Object.fromEntries(
  Object.keys(LABELS_BEFORE).map((slug) => [slug, dedupeProducts(read(`data/catalog/${slug}.json`))]),
);
const card = (p) => ({ uid: String(p.uid), slug: p.slug, title: p.title, description: p.description, price: p.price, images: p.images });

describe('снимок даёт тот же каталог, что старые файлы', () => {
  it('те же разделы и названия', () => {
    expect(Object.fromEntries(exp.sections.map((s) => [s.slug, s.label]))).toEqual(LABELS_BEFORE);
  });

  for (const slug of Object.keys(LABELS_BEFORE)) {
    it(`раздел ${slug}: те же букеты в том же порядке (без коробок за 0 ₽)`, () => {
      expect(sectionProducts(exp, slug).map(card)).toEqual(before[slug].filter((p) => p.price > 0).map(card));
    });
  }

  it('те же страницы букетов — все 487, включая снятые коробки', () => {
    const was = Object.entries(before).flatMap(([slug, list]) => list.map((p) => `/${slug}/${p.slug}/`)).sort();
    const now = productPages(exp).map((p) => `/${p.slug}/${p.product}/`).sort();
    expect(now).toEqual(was);
    expect(now).toHaveLength(487);
  });

  it('на странице каждого букета — те же название, состав, цена и фото', () => {
    for (const [slug, list] of Object.entries(before)) {
      for (const p of list) expect(card(findProduct(exp, slug, p.slug))).toEqual(card(p));
    }
  });

  it('те же плитки сетки каталога', () => {
    expect(catalogTiles(exp)).toEqual(meta.tiles);
  });

  it('то же оформление разделов (пустая строка и null — одно и то же)', () => {
    const norm = (m) => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, v === '' ? null : v]));
    for (const slug of Object.keys(LABELS_BEFORE)) {
      expect(norm(sectionMeta(findSection(exp, slug)))).toEqual(norm(meta.categories[slug]));
    }
  });

  it('те же одноимённые букеты', () => {
    const seen = new Map();
    for (const [slug, list] of Object.entries(before)) {
      for (const p of list) {
        const key = p.title.trim().toLowerCase();
        seen.set(key, new Set([...(seen.get(key) ?? []), slug]));
      }
    }
    const was = new Set([...seen].filter(([, s]) => s.size > 1).map(([t]) => t));
    expect(ambiguousTitles(exp)).toEqual(was);
  });
});
```

Create `src/lib/catalog.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { PAGE_SLUGS } from './content';
import { RESERVED_SLUGS } from '../../scripts/lib/catalog-export.mjs';
import {
  getAllProductParams,
  getCatalog,
  getCatalogTiles,
  getCategoryMeta,
  getNewProducts,
  getProduct,
  getSectionLabel,
  getSectionSlugs,
} from './catalog';

describe('каталог для страниц', () => {
  it('страницы сайта входят в занятые адреса — раздел их не займёт', () => {
    for (const slug of [...PAGE_SLUGS, 'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000']) {
      expect(RESERVED_SLUGS).toContain(slug);
    }
  });

  it('разделы и плитки из снимка', () => {
    expect(getSectionSlugs()).toHaveLength(13);
    expect(getSectionLabel('korobki')).toBe('Коробки с цветами');
    expect(getCatalogTiles()).toHaveLength(12);
    expect(getCategoryMeta('bukety')?.heading).toBe('БУКЕТЫ');
    expect(getCategoryMeta('nope')).toBeNull();
    expect(getCatalog('nope')).toBeNull();
  });

  it('в разделе нет снятых; у снятой коробки страница есть', () => {
    expect(getCatalog('korobki')!.every((p) => p.status === 'active' && p.price > 0)).toBe(true);
    const box = getAllProductParams().find((p) => p.slug === 'korobki' && getProduct('korobki', p.product)?.status === 'hidden');
    expect(box).toBeDefined();
    expect(getAllProductParams()).toHaveLength(487);
  });

  it('раздела «Новинки» в снимке ещё нет — главная берёт их из site.json', () => {
    expect(getNewProducts()).toBeNull();
  });
});
```

- [ ] **Step 2: Убедиться, что падают**

Run: `npx vitest run scripts/lib/catalog-view.test.mjs scripts/catalog-roundtrip.test.mjs src/lib/catalog.test.ts`
Expected: FAIL — нет модулей `./catalog-view.mjs`, `./lib/catalog-view.mjs`, `./catalog`.

- [ ] **Step 3: Реализация**

Create `scripts/lib/catalog-view.mjs`:

```js
/**
 * Как сайт видит выгрузку каталога: что стоит в разделе, какая страница у
 * букета, что попадает в «Новинки», плитки сетки. Это чистые функции над
 * объектом выгрузки. Их используют и страницы (src/lib/catalog.ts), и
 * служебные файлы сборки (build-agent-assets.mjs, build-feed.mjs), чтобы
 * эти части не разошлись.
 *
 * Правила из спецификации (раздел 6): в разделе показываются только букеты в
 * продаже, в порядке раздела. Страница есть у букетов в продаже и у снятых,
 * адрес строится по главному разделу — в каком бы разделе букет ни стоял.
 */

/** Раздел, первые букеты в продаже из которого стоят на главной. */
export const NEW_SECTION = 'novinki';

/** Постоянный адрес страницы букета. Та же форма, что productPath в src/lib/seo.ts. */
export function productPath(section, slug) {
  return `/${section}/${slug}/`;
}

const indexes = new WeakMap();
function byUid(exp) {
  let index = indexes.get(exp);
  if (!index) {
    index = new Map(exp.products.map((p) => [p.uid, p]));
    indexes.set(exp, index);
  }
  return index;
}

export function findSection(exp, slug) {
  return exp.sections.find((s) => s.slug === slug) ?? null;
}

/** Букеты в продаже из раздела, в его порядке. Снятые в разделах не показываются. */
export function sectionProducts(exp, slug) {
  const section = findSection(exp, slug);
  if (!section) return [];
  const index = byUid(exp);
  return section.products.map((uid) => index.get(uid)).filter((p) => p !== undefined && p.status === 'active');
}

/** Букет по адресу страницы — главный раздел и slug. Снятые тоже: страница у них есть. */
export function findProduct(exp, section, slug) {
  return exp.products.find((p) => p.mainSection === section && p.slug === slug) ?? null;
}

/** Все страницы букетов — в продаже и снятые — по разделам и в их порядке. */
export function productPages(exp) {
  const index = byUid(exp);
  const pages = [];
  for (const section of exp.sections) {
    for (const uid of section.products) {
      const p = index.get(uid);
      if (p && p.mainSection === section.slug) pages.push({ slug: section.slug, product: p.slug });
    }
  }
  return pages;
}

/** Похожие букеты — в продаже, из главного раздела, без самого букета. */
export function relatedProducts(exp, product, limit = 4) {
  return sectionProducts(exp, product.mainSection).filter((p) => p.uid !== product.uid).slice(0, limit);
}

/**
 * Названия, под которыми продаются букеты из разных главных разделов: их
 * страницы получают в заголовок название раздела, иначе поисковик видит две
 * одинаковые. Счёт идёт по букетам: букет, стоящий в двух разделах, сам себе
 * не тёзка.
 */
export function ambiguousTitles(exp) {
  const seen = new Map();
  for (const p of exp.products) {
    const key = p.title.trim().toLowerCase();
    if (!seen.has(key)) seen.set(key, new Set());
    seen.get(key).add(p.mainSection);
  }
  return new Set([...seen].filter(([, sections]) => sections.size > 1).map(([title]) => title));
}

/** Плитки сетки каталога: у раздела — его название, адрес и фото плитки. */
export function catalogTiles(exp) {
  return exp.tiles.flatMap((t) => {
    if (t.type !== 'section') return [{ label: t.label, href: t.href, image: t.image }];
    const section = findSection(exp, t.slug);
    return section ? [{ label: section.label, href: `/${section.slug}`, image: section.tileImage }] : [];
  });
}

const orNull = (value) => (value === '' ? null : value);

/** Оформление страницы раздела в прежнем виде CategoryMeta: пустое поле — null. */
export function sectionMeta(section) {
  return {
    title: orNull(section.coverTitle),
    sub: orNull(section.coverSub),
    covers: section.covers,
    heading: orNull(section.heading),
    headingSub: orNull(section.headingSub),
    hasNotFound: section.hasNotFound,
  };
}

/**
 * «Новинки» на главной — первые букеты в продаже из раздела novinki.
 * null — такого раздела в выгрузке нет (до переноса каталога в базу);
 * пустой список — раздел есть, но в продаже в нём ничего, и блока нет.
 */
export function newProducts(exp, limit = 3) {
  if (!findSection(exp, NEW_SECTION)) return null;
  return sectionProducts(exp, NEW_SECTION).slice(0, limit);
}
```

In `src/lib/types.ts`, inside `interface Product`, after the `published?: boolean;` field add:

```ts
  /**
   * Раздел, по которому строится адрес страницы (`/<раздел>/<slug>/`).
   * Есть у букетов каталога, нет у витрины из CRM и карточек главной из
   * site.json.
   */
  mainSection?: string;
  /** В продаже или снят: у снятого страница есть, но в разделах его нет и заказать нельзя. */
  status?: 'active' | 'hidden';
```

Create `src/lib/catalog.ts`:

```ts
/**
 * Каталог для страниц сайта — из снимка выгрузки data/catalog-export.json
 * (формат админки, спецификация, раздел 3). Правила показа — в
 * scripts/lib/catalog-view.mjs, их же используют служебные файлы сборки.
 *
 * Это отдельный модуль, а не content.ts: content.ts импортируют клиентские
 * компоненты (шапка, подвал, корзина), и тогда весь каталог попал бы в код,
 * который скачивает браузер.
 */
import exportJson from '../../data/catalog-export.json';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  newProducts,
  productPages,
  relatedProducts,
  sectionMeta,
  sectionProducts,
} from '../../scripts/lib/catalog-view.mjs';
import { PAGE_SLUGS } from './content';
import type { CatalogTile, CategoryMeta, Product } from './types';

export interface CatalogSection {
  slug: string;
  label: string;
  tileImage: string;
  visible: boolean;
  coverTitle: string;
  coverSub: string;
  covers: string[];
  heading: string;
  headingSub: string;
  hasNotFound: boolean;
  seoTitle: string | null;
  seoDescription: string | null;
  products: string[];
}

export interface CatalogProduct extends Product {
  mainSection: string;
  status: 'active' | 'hidden';
}

export interface CatalogExport {
  version: string;
  changedAt: string | null;
  sections: CatalogSection[];
  tiles: ({ type: 'section'; slug: string } | { type: 'link' | 'popup'; label: string; href: string; image: string })[];
  products: CatalogProduct[];
  redirects: { from: string; to: string }[];
}

const data = exportJson as unknown as CatalogExport;

// Вторая линия защиты (первая — проверка выгрузки в prebuild): раздел с
// адресом обычной страницы сайта перекрыл бы её, поэтому сборка
// останавливается.
for (const section of data.sections) {
  if ((PAGE_SLUGS as readonly string[]).includes(section.slug)) {
    throw new Error(`Раздел «${section.label}» (${section.slug}) совпадает со страницей сайта — сборка остановлена.`);
  }
}

export function getSectionSlugs(): string[] {
  return data.sections.map((s) => s.slug);
}

export function getSection(slug: string): CatalogSection | null {
  return findSection(data, slug) as CatalogSection | null;
}

export function getSectionLabel(slug: string): string {
  return getSection(slug)?.label ?? slug;
}

/** Букеты в продаже из раздела, в его порядке; null — такого раздела нет. */
export function getCatalog(slug: string): CatalogProduct[] | null {
  return getSection(slug) ? (sectionProducts(data, slug) as CatalogProduct[]) : null;
}

/** Букет по адресу страницы: главный раздел и slug. Снятые тоже. */
export function getProduct(section: string, slug: string): CatalogProduct | null {
  return findProduct(data, section, slug) as CatalogProduct | null;
}

export function getRelatedProducts(product: CatalogProduct, limit = 4): CatalogProduct[] {
  return relatedProducts(data, product, limit) as CatalogProduct[];
}

/** Все страницы букетов — для статической генерации и карты сайта. */
export function getAllProductParams(): { slug: string; product: string }[] {
  return productPages(data) as { slug: string; product: string }[];
}

export function getAmbiguousTitles(): Set<string> {
  return ambiguousTitles(data) as Set<string>;
}

export function getCatalogTiles(): CatalogTile[] {
  return catalogTiles(data) as CatalogTile[];
}

export function getCategoryMeta(slug: string): CategoryMeta | null {
  const section = getSection(slug);
  return section ? (sectionMeta(section) as CategoryMeta) : null;
}

/** «Новинки» для главной; null — раздела ещё нет (до этапа 3). */
export function getNewProducts(limit = 3): CatalogProduct[] | null {
  return newProducts(data, limit) as CatalogProduct[] | null;
}
```

- [ ] **Step 4: Убедиться, что проходят**

Run: `npx vitest run scripts/lib/catalog-view.test.mjs scripts/catalog-roundtrip.test.mjs src/lib/catalog.test.ts && npm test`
Expected: PASS.

Run: `npx tsc --noEmit`
Expected: без ошибок. Если TypeScript не принимает импорт `.mjs` без типов, сделать так же, как `src/lib/dedupeProducts.ts`: импорт через приведение типов. Ошибки не глушить.

- [ ] **Step 5: Коммит**

```bash
git add scripts/lib/catalog-view.mjs scripts/lib/catalog-view.test.mjs scripts/catalog-roundtrip.test.mjs src/lib/catalog.ts src/lib/catalog.test.ts src/lib/types.ts
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): чтение каталога из выгрузки и сверка «туда-обратно» со старыми файлами

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Разделы, сетка, подборки и «Новинки» из выгрузки

**Files:**
- Modify: `src/app/[slug]/page.tsx`
- Modify: `src/app/sitemap.ts`
- Modify: `src/app/catalog/page.tsx`
- Modify: `src/app/bukety-do-5000/page.tsx`
- Modify: `src/app/page.tsx`
- Modify: `src/components/CategoryGrid/CategoryGrid.tsx`, `src/components/CategoryGrid/CategoryGrid.module.css`
- Modify: `src/components/ProductCard/ProductCard.tsx`
- Modify: `src/lib/seo.ts` (`productListJsonLd`)
- Test: `src/lib/seo.test.ts`

**Interfaces:**
- Consumes: `getSectionSlugs`, `getSection`, `getCatalog`, `getCategoryMeta`, `getCatalogTiles`, `getAllProductParams`, `getNewProducts` (Task 4); `PAGE_SLUGS`, `getPage`, `getSite` (`content.ts`).
- Produces:
  - `productListJsonLd(products: {uid, title, description, price, images, slug, mainSection}[]): object` — второго аргумента больше нет, адрес строится по `mainSection`
  - `ProductCard({product, isNew?, href?})` — с `href` название ведёт на страницу букета
  - `CategoryGrid`: карточка ведёт на `productPath(p.mainSection ?? category, p.slug)`; у пустого раздела — «Сейчас здесь пусто» и ссылка на каталог

`content.ts` и страница букета в этой задаче не меняются: их переводит Task 6.

- [ ] **Step 1: Проверка (пока падает)**

Create `src/lib/seo.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { productListJsonLd } from './seo';

const p = (uid: string, mainSection: string, slug: string) => ({
  uid, title: `Букет ${uid}`, description: '', price: 4400, images: [], slug, mainSection,
});

describe('productListJsonLd', () => {
  it('ссылки ведут на страницу букета по его главному разделу, в каком бы разделе он ни стоял', () => {
    const list = productListJsonLd([p('1', 'bukety', 'a'), p('2', 'roses', 'b')]) as {
      itemListElement: { item: { url: string; offers: { url: string } } }[];
    };
    expect(list.itemListElement.map((i) => i.item.url)).toEqual([
      'https://pionperm.ru/bukety/a/',
      'https://pionperm.ru/roses/b/',
    ]);
    expect(list.itemListElement[1].item.offers.url).toBe('https://pionperm.ru/roses/b/');
  });
});
```

Run: `npx vitest run src/lib/seo.test.ts`
Expected: FAIL — адрес второй ссылки строится по второму аргументу (`undefined`), а не по `mainSection`.

Если `absoluteUrl` в тестах даёт не `https://pionperm.ru`, а другой адрес, сначала посмотреть `src/lib/seo.ts`: адрес берётся из `NEXT_PUBLIC_SITE_URL`. Тогда сравнивать с `absoluteUrl('/bukety/a/')`, не подставляя адрес руками.

- [ ] **Step 2: `seo.ts` — список товаров по главному разделу**

In `src/lib/seo.ts` replace the whole `productListJsonLd` function with:

```ts
export function productListJsonLd(
  products: {
    uid: string;
    title: string;
    description: string;
    price: number;
    images: string[];
    slug: string;
    mainSection: string;
  }[],
) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    itemListElement: products.map((p, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      item: {
        '@type': 'Product',
        name: p.title,
        description: p.description || undefined,
        image: p.images[0] ? absoluteUrl(p.images[0]) : undefined,
        // Ссылка на страницу самого товара, а не на раздел: иначе поисковик
        // видит список из ста предложений по одному адресу. Адрес — по главному
        // разделу букета, даже если список собран в другом разделе.
        url: absoluteUrl(productPath(p.mainSection, p.slug)),
        offers: {
          '@type': 'Offer',
          price: p.price,
          priceCurrency: 'RUB',
          url: absoluteUrl(productPath(p.mainSection, p.slug)),
        },
      },
    })),
  };
}
```

- [ ] **Step 3: Страница раздела**

In `src/app/[slug]/page.tsx`:

1. Replace the import block from `@/lib/content` with:

```tsx
import { PAGE_SLUGS, getPage } from '@/lib/content';
import { getCatalog, getCategoryMeta, getSection, getSectionSlugs } from '@/lib/catalog';
```

2. Replace `generateStaticParams` with:

```tsx
export function generateStaticParams() {
  return [...getSectionSlugs(), ...PAGE_SLUGS].map((slug) => ({ slug }));
}
```

3. Delete the `wedding`, `valentinesday` and `'new-year-2025'` entries from `PAGE_SEO`, together with the comment above `wedding` (`// «Букет невесты» и «свадебный букет» — …`). Эти заголовки теперь в данных раздела (`SECTION_SEO` в `make-catalog-export.mjs`). Комментарий над `PAGE_SEO` оставить.

4. In `generateMetadata`, replace the whole `if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) { … }` block with:

```tsx
  const section = getSection(slug);
  if (section) {
    // Заголовок и описание для поиска ведутся в карточке раздела; пустые —
    // по шаблону.
    const title = section.seoTitle || `${section.label} с доставкой в Перми | Салон «Пион»`;
    return {
      ...buildMetadata({
        title,
        description:
          section.seoDescription ||
          `${section.label} от салона «Пион» в Перми. Авторские композиции из свежих цветов, фото букета перед доставкой, самовывоз со скидкой 5%.`,
        path: `/${slug}/`,
      }),
      title: { absolute: title },
    };
  }
```

5. In `SlugPage`, replace the `if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {` line and the four lines after it (up to and including `const coverTitle = …`) with:

```tsx
  const section = getSection(slug);
  if (section) {
    const products = getCatalog(slug) ?? [];
    const meta = getCategoryMeta(slug);
    const label = section.label;
    const coverTitle = meta?.covers.length ? meta.title : null;
```

6. In the same branch, replace `productListJsonLd(products, `/${slug}/`)` with `productListJsonLd(products)`.

- [ ] **Step 4: Сетка разделов, карта сайта, подборка до 5000 ₽**

In `src/components/CategoryGrid/CategoryGrid.tsx`:

1. Replace `href={category ? productPath(category, p.slug) : null}` with:

```tsx
                  href={category ? productPath(p.mainSection ?? category, p.slug) : null}
```

2. Replace `<p className={styles.empty}>В этой категории сейчас нет товаров</p>` with:

```tsx
          <p className={styles.empty}>
            Сейчас здесь пусто. <Link href="/catalog">Посмотрите весь каталог</Link>
          </p>
```

3. Update the doc comment of the `category` prop to: `/** Раздел, в котором показаны товары. Задан — карточки ведут на страницы букетов (по их главному разделу); не задан — остаётся окно с описанием. */`

In `src/components/CategoryGrid/CategoryGrid.module.css`, after the `.empty { … }` rule add:

```css
.empty a {
  border-bottom: 1px solid var(--color-accent);
}
```

Replace `src/app/sitemap.ts` imports and the categories line:

```ts
import { PAGE_SLUGS } from '@/lib/content';
import { getAllProductParams, getSectionSlugs } from '@/lib/catalog';
```

```ts
    ...getSectionSlugs().map((slug) => ({ path: `/${slug}/`, priority: 0.8 })),
```

and `const products = (await getAllProductParams()).map(` → `const products = getAllProductParams().map(`. Комментарий над `getAllProductParams` в карте сайта оставить.

In `src/app/catalog/page.tsx` change the import to `import { getCatalogTiles } from '@/lib/catalog';`.

In `src/app/bukety-do-5000/page.tsx`:
- import `getCatalog` from `@/lib/catalog` instead of `@/lib/content`;
- `const bouquets = ((await getCatalog('bukety')) ?? [])` → `const bouquets = (getCatalog('bukety') ?? [])`;
- `productListJsonLd(bouquets, '/bukety/')` → `productListJsonLd(bouquets)`;
- над `bouquets` добавить комментарий `// Только букеты в продаже: снятые в разделах не показываются.`

- [ ] **Step 5: «Новинки» на главной**

In `src/components/ProductCard/ProductCard.tsx`:
- add `import Link from 'next/link';`
- change the signature to `export function ProductCard({ product, isNew = false, href }: { product: Product; isNew?: boolean; href?: string }) {`
- replace `<h3 className={styles.title}>{product.title}</h3>` with:

```tsx
      {/* У букета каталога есть своя страница — название ведёт на неё. У витрины из CRM своих страниц нет. */}
      <h3 className={styles.title}>{href ? <Link href={href}>{product.title}</Link> : product.title}</h3>
```

In `src/app/page.tsx`:
- add imports `import { getNewProducts } from '@/lib/catalog';` and `import { productPath } from '@/lib/seo';` (в файле уже есть `import { buildMetadata } from '@/lib/seo';` — объединить в одну строку `import { buildMetadata, productPath } from '@/lib/seo';`);
- replace the `featured` block (comment and `const featured = …`) with:

```tsx
  // «Новинки» — раздел каталога novinki, его ведёт салон: первые три букета в
  // продаже, со ссылками на их страницы. Пока каталог не перенесён в базу
  // (этап 3), такого раздела нет, и карточки берутся из site.json, как раньше.
  const fromCatalog = getNewProducts(3);
  const featured =
    fromCatalog ??
    site.newProducts.map((p) => ({
      uid: `new-${p.title}`,
      title: p.title,
      description: p.subtitle,
      price: p.price,
      images: p.image ? [p.image] : [],
      slug: '',
    }));
```

- wrap the whole `<section className={styles.newSection}> … </section>` in `{featured.length > 0 && ( … )}` (раздел есть, но в продаже в нём пусто — блока нет);
- replace `<ProductCard key={p.uid} product={p} isNew />` with:

```tsx
            <ProductCard
              key={p.uid}
              product={p}
              isNew
              href={fromCatalog && p.mainSection ? productPath(p.mainSection, p.slug) : undefined}
            />
```

- [ ] **Step 6: Проверки и сборка**

Run: `npx vitest run src/lib/seo.test.ts && npm test`
Expected: PASS.

Run: `npm run build`
Expected: сборка проходит, в `out/sitemap.xml` 515 адресов. Проверить: `node -e "console.log((require('fs').readFileSync('out/sitemap.xml','utf8').match(/<loc>/g)||[]).length)"` → `515`.

Run: `node -e "const h=require('fs').readFileSync('out/korobki/index.html','utf8');const m=[...h.matchAll(/<script type=\"application\/ld\+json\">([\s\S]*?)<\/script>/g)].map(x=>JSON.parse(x[1])).find(d=>d['@type']==='ItemList');console.log(m.itemListElement.length)"`
Expected: на 19 меньше, чем на `master`. В `data/catalog/korobki.json` после `dedupeProducts` — столько же, сколько у `sectionProducts(exp,'korobki')` + 19. Записать оба числа в отчёт.

- [ ] **Step 7: Коммит**

```bash
git add src/app/[slug]/page.tsx src/app/sitemap.ts src/app/catalog/page.tsx src/app/bukety-do-5000/page.tsx src/app/page.tsx src/components/CategoryGrid/CategoryGrid.tsx src/components/CategoryGrid/CategoryGrid.module.css src/components/ProductCard/ProductCard.tsx src/lib/seo.ts src/lib/seo.test.ts
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): разделы, сетка каталога, подборка и «Новинки» из выгрузки

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Страница букета — в продаже и снятый

**Files:**
- Modify: `src/app/[slug]/[product]/page.tsx`
- Modify: `src/app/[slug]/[product]/page.module.css`
- Modify: `src/lib/seo.ts` (`productJsonLd`)
- Modify: `src/lib/content.ts` (убрать старый каталог)
- Modify: `src/lib/seo.test.ts`

**Interfaces:**
- Consumes: `getProduct`, `getRelatedProducts`, `getAllProductParams`, `getAmbiguousTitles`, `getSectionLabel`, `CatalogProduct` (Task 4); `getSite` (`content.ts`).
- Produces:
  - `productJsonLd(product, category, slug, available = true)`: `available === false` даёт `availability: OutOfStock`; при цене ≤ 0 предложения (`offers`) нет совсем
  - `content.ts` экспортирует только `PAGE_SLUGS`, `PageSlug`, `getSite`, `getPage`

- [ ] **Step 1: Проверки (пока падают)**

Append to `src/lib/seo.test.ts`:

```ts
import { productJsonLd } from './seo';

describe('productJsonLd', () => {
  const product = { title: 'Букет «А»', description: 'Роза', price: 4400, images: ['/images/catalog/bukety/a.webp'] };

  it('в продаже — под заказ, как раньше', () => {
    const ld = productJsonLd(product, 'bukety', 'a') as { offers: { availability: string; price: number } };
    expect(ld.offers.availability).toBe('https://schema.org/PreOrder');
    expect(ld.offers.price).toBe(4400);
  });

  it('снят с продажи — нет в наличии', () => {
    const ld = productJsonLd(product, 'bukety', 'a', false) as { offers: { availability: string } };
    expect(ld.offers.availability).toBe('https://schema.org/OutOfStock');
  });

  it('без цены — без предложения: «0 ₽» поисковику не показываем', () => {
    const ld = productJsonLd({ ...product, price: 0 }, 'korobki', 'b', false) as { offers?: unknown };
    expect(ld.offers).toBeUndefined();
  });
});
```

(Импорт `productJsonLd` перенести в общую строку импорта в начале файла, если линтер против второго импорта из того же модуля.)

Run: `npx vitest run src/lib/seo.test.ts`
Expected: FAIL — у снятого `PreOrder`, у цены 0 есть `offers`.

- [ ] **Step 2: `productJsonLd`**

In `src/lib/seo.ts` replace `productJsonLd` with:

```ts
/**
 * Карточка товара для поисковика. `offers.url` ведёт на саму страницу
 * товара: до появления отдельных страниц все предложения указывали на
 * раздел, и поисковику нечего было показать по конкретному букету.
 *
 * Наличие у букета в продаже не указываем жёстко: букеты собирают под заказ
 * из того, что есть в это утро, поэтому честнее сказать «под заказ», чем
 * обещать склад. У снятого с продажи — «нет в наличии», страница остаётся.
 * Без цены (старые коробки из Tilda) предложения нет совсем.
 */
export function productJsonLd(
  product: { title: string; description: string; price: number; images: string[] },
  category: string,
  slug: string,
  available = true,
) {
  const url = absoluteUrl(productPath(category, slug));
  return {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.title,
    description: product.description || undefined,
    image: product.images.map((i) => absoluteUrl(i)),
    url,
    brand: { '@type': 'Brand', name: 'Пион' },
    offers:
      product.price > 0
        ? {
            '@type': 'Offer',
            price: product.price,
            priceCurrency: 'RUB',
            url,
            availability: available ? 'https://schema.org/PreOrder' : 'https://schema.org/OutOfStock',
            seller: { '@type': 'Organization', name: 'Салон цветов и подарков «Пион»' },
            areaServed: CITY,
          }
        : undefined,
  };
}
```

- [ ] **Step 3: Страница букета**

In `src/app/[slug]/[product]/page.tsx`:

1. Replace the import from `@/lib/content` with:

```tsx
import { getSite } from '@/lib/content';
import {
  getAllProductParams,
  getAmbiguousTitles,
  getProduct,
  getRelatedProducts,
  getSectionLabel,
} from '@/lib/catalog';
```

2. `generateStaticParams` returns `getAllProductParams()`. В комментарий над функцией добавить строку: страницы есть у букетов в продаже и у снятых.

3. Replace the body of `generateMetadata` (from `const product = await getProduct(` to the final `};` of the returned object) with:

```tsx
  const product = getProduct(params.slug, params.product);
  if (!product) return {};

  const composition = compositionLine(product.description);
  const onSale = product.status === 'active';
  // Одно и то же название бывает у разных букетов из разных разделов. Тогда в
  // заголовок добавляется раздел — иначе две страницы выглядят для поисковика
  // одинаково, и он показывает только одну из них.
  const label = getSectionLabel(product.mainSection);
  const short = product.title.trim().toLowerCase();
  // «Пион» в разделе «Пионы» подписывать нечем — вышло бы «Пион — пионы».
  // Достаточно, что подпись получит вторая страница пары: заголовки станут
  // разными, а этот останется коротким и читаемым.
  const needsLabel =
    getAmbiguousTitles().has(short) && !label.toLowerCase().includes(short) && !short.includes(label.toLowerCase());
  const name = needsLabel ? `${product.title} — ${label.toLowerCase()}` : product.title;
  const title = `${name} — купить в Перми | Салон «Пион»`;

  return {
    ...buildMetadata({
      title,
      description: [
        onSale
          ? `${product.title} за ${product.price.toLocaleString('ru-RU')} ₽ с доставкой по Перми.`
          : `${product.title} — сейчас нет в продаже, похожие букеты — в разделе «${label}».`,
        composition && `Состав: ${composition}.`,
        onSale ? 'Фото букета перед доставкой, самовывоз со скидкой 5%.' : 'Салон цветов «Пион», Пермь.',
      ]
        .filter(Boolean)
        .join(' '),
      path: productPath(product.mainSection, product.slug),
      image: product.images[0],
    }),
    title: { absolute: title },
  };
```

4. In `ProductPage`, replace the beginning up to `const composition = …` with:

```tsx
  const product = getProduct(params.slug, params.product);
  if (!product) notFound();

  const onSale = product.status === 'active';
  const related = getRelatedProducts(product);
  const site = getSite();
  const label = getSectionLabel(product.mainSection);
  const composition = compositionLine(product.description);
```

5. Breadcrumbs and JSON-LD use the product's main section:
   - в `breadcrumbJsonLd` элементы раздела и товара: `{ name: label, path: `/${product.mainSection}/` }`, `{ name: product.title, path: productPath(product.mainSection, product.slug) }`;
   - `<JsonLd data={productJsonLd(product, product.mainSection, product.slug, onSale)} />`;
   - в хлебных крошках `<Link href={`/${product.mainSection}`}>{label}</Link>`;
   - в похожих: `productPath(product.mainSection, p.slug)` и `href={`/${product.mainSection}`}` у ссылки «Весь раздел».

6. Replace the price paragraph and the `<AddToCart … />` + assurance block:

```tsx
          {product.price > 0 && <p className={styles.price}>{product.price.toLocaleString('ru-RU')} ₽</p>}
          {!onSale && <p className={styles.soldOut}>Сейчас нет в продаже</p>}
```

(on the place of the old `<p className={styles.price}>`), and

```tsx
          {onSale ? (
            <>
              <AddToCart product={product} />

              <p className={styles.assurance}>
                Соберём и пришлём фото букета перед доставкой — если что-то не понравится,
                переделаем.
              </p>
            </>
          ) : (
            <p className={styles.assurance}>Посмотрите похожие букеты ниже — их можно заказать.</p>
          )}
```

(on the place of the old `<AddToCart product={product} />` and the `assurance` paragraph after it).

In `src/app/[slug]/[product]/page.module.css`, after `.price { … }` add:

```css
/* Снятый с продажи букет: страница остаётся, заказать нельзя. */
.soldOut {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #9a4a3c;
}
```

- [ ] **Step 4: Убрать старый каталог из `content.ts`**

In `src/lib/content.ts` delete:
- the import of `dedupeProducts` and of `catalogMetaJson`, and `Product`, `CatalogTile`, `CategoryMeta` from the type import (keep `PageSection`, `SiteData`);
- the comment about `flowers`/`indoorflowers`/`valentinesday`, `CATEGORY_SLUGS`, `CategorySlug`, `CATEGORY_LABELS`;
- `getCatalogTiles`, `getCategoryMeta`, `ONLY_ACTIVE_IN_STORE` with its comment, `getCatalog`, `getProduct`, `getAmbiguousTitles`, `getRelatedProducts`, `getAllProductParams`.

Keep `PAGE_SLUGS`, `PageSlug`, `getSite`, `getPage`. В начало файла добавить комментарий:

```ts
/**
 * Данные сайта, кроме каталога: тексты страниц и настройки (site.json).
 * Каталог — в src/lib/catalog.ts: этот модуль импортируют клиентские
 * компоненты, а каталог в браузер не нужен.
 */
```

Run: `grep -rn "CATEGORY_SLUGS\|CATEGORY_LABELS\|getCatalogTiles\|getCategoryMeta" src`
Expected: пусто (`src/lib/dedupeProducts.ts` с тестом остаётся: тест проверяет общую с `make-catalog-export` логику).

- [ ] **Step 5: Проверки и сборка**

Run: `npm test && npm run build`
Expected: PASS; сборка проходит.

Run (снятая коробка):

```bash
node -e "const exp=require('./data/catalog-export.json');const p=exp.products.find(x=>x.status==='hidden');const h=require('fs').readFileSync('out/'+p.mainSection+'/'+p.slug+'/index.html','utf8');console.log(p.title, h.includes('Сейчас нет в продаже'), h.includes('Добавить в корзину'), h.includes('OutOfStock'), h.includes('\"offers\"'))"
```

Expected: `<название> true false false false` (пометка есть, кнопки нет; предложения нет — цена 0).

Run (букет в продаже): `node -e "const h=require('fs').readFileSync('out/bukety/buket-barhatnye-grani/index.html','utf8');console.log(h.includes('Добавить в корзину'), h.includes('PreOrder'), h.includes('6 380'))"`
Expected: `true true true` (цена может быть записана с неразрывным пробелом — тогда проверить `6 380` и сообщить, какой вариант сработал).

- [ ] **Step 6: Коммит**

```bash
git add src/app/[slug]/[product]/page.tsx src/app/[slug]/[product]/page.module.css src/lib/seo.ts src/lib/seo.test.ts src/lib/content.ts
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): страница букета из выгрузки — снятый с продажи остаётся с пометкой

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Служебные файлы сборки и фид из выгрузки

**Files:**
- Modify: `scripts/lib/catalog-view.mjs` (`tildaMap`, `redirectMap`)
- Modify: `scripts/lib/catalog-view.test.mjs`
- Modify: `scripts/build-agent-assets.mjs`
- Modify: `scripts/build-feed.mjs`
- Regenerate: `public/api/**`, `public/md/*.md`, `public/llms.txt` (они лежат в git)

**Interfaces:**
- Consumes: `catalogTiles`, `sectionProducts`, `productPath` (Task 4); `feedCategories`, `buildYml`, `buildCsv` (`scripts/lib/yml-feed.mjs`).
- Produces:
  - `tildaMap(exp): Record<uid, string>` — uid → страница букета (в продаже и снятые)
  - `redirectMap(exp): Record<string, string>` — `from` → `to`
  - новый файл сборки `public/api/redirects.json` (его читает `pay/catalog-redirect.php`, Task 8)

- [ ] **Step 1: Проверки (пока падают)**

Append to `scripts/lib/catalog-view.test.mjs` (добавить `redirectMap`, `tildaMap` в импорт):

```js
describe('карты адресов для сервера', () => {
  it('tilda-map: каждый букет — на его страницу по главному разделу, снятые тоже', () => {
    expect(tildaMap(load())).toEqual({
      100000000001: '/bukety/buket-a/',
      100000000002: '/bukety/buket-b/',
      100000000003: '/bukety/buket-v/',
      100000000004: '/novinki/pion/',
      100000000005: '/roses/pion/',
    });
  });

  it('redirects: откуда → куда', () => {
    expect(redirectMap(load())).toEqual({ '/korobki/buket-a/': '/bukety/buket-a/' });
  });
});
```

Run: `npx vitest run scripts/lib/catalog-view.test.mjs`
Expected: FAIL — `tildaMap is not a function`.

- [ ] **Step 2: Реализация в `catalog-view.mjs`**

Append to `scripts/lib/catalog-view.mjs`:

```js
/**
 * Старые адреса товаров Tilda (`…/tproduct/…-<uid>-…`) → страница букета.
 * Uid при переезде не менялся. Снятые ведут на свою страницу; удалённых в
 * выгрузке нет, их ведёт в раздел правило .htaccess.
 */
export function tildaMap(exp) {
  return Object.fromEntries(
    exp.products.filter((p) => /^[0-9]+$/.test(p.uid)).map((p) => [p.uid, productPath(p.mainSection, p.slug)]),
  );
}

/** Переадресации каталога для pay/catalog-redirect.php: откуда → куда. */
export function redirectMap(exp) {
  return Object.fromEntries(exp.redirects.map((r) => [r.from, r.to]));
}
```

- [ ] **Step 3: `build-agent-assets.mjs`**

In `scripts/build-agent-assets.mjs`:

1. Replace `import { dedupeProducts } from './lib/dedupeProducts.mjs';` with:

```js
import { catalogTiles, redirectMap, sectionProducts, tildaMap } from './lib/catalog-view.mjs';
```

2. Replace the two lines `const meta = await readJson('data/catalog-meta.json');` and `const catalogFiles = …` with:

```js
// Каталог — снимок выгрузки (тот же, что читают страницы): data/catalog-export.json.
const catalog = await readJson('data/catalog-export.json');
```

3. `categoryLines`: replace `meta.tiles` with `catalogTiles(catalog)`.

4. Replace the whole Tilda map block — from `const tildaMap = {};` to `await write('api/tilda-map.json', …);` — keeping the comment above it, with:

```js
await write('api/tilda-map.json', JSON.stringify(tildaMap(catalog), null, 0) + '\n');

// ------------------------------------------- переадресации каталога (сервер)
// Букет переехал в другой раздел или удалён — со старого адреса ведёт
// pay/catalog-redirect.php по этому списку. Список пишет админка.
await write('api/redirects.json', JSON.stringify(redirectMap(catalog), null, 0) + '\n');
```

5. Replace the categories loop — from `for (const file of catalogFiles) {` (the second one, under `// ----- static JSON for agents`) up to its closing `}` before `await write('api/index.json'` — with:

```js
for (const section of catalog.sections) {
  const { slug } = section;
  // Только букеты в продаже — те, что стоят в разделе на сайте и что можно заказать.
  const products = sectionProducts(catalog, slug);
  const title = section.coverTitle || section.label;
  categories.push({
    slug,
    title,
    url: `${SITE_URL}/${slug}/`,
    productCount: products.length,
  });
  await write(
    `api/catalog/${slug}.json`,
    JSON.stringify(
      {
        slug,
        title,
        url: `${SITE_URL}/${slug}/`,
        products: products.map((p) => ({
          id: p.uid,
          title: p.title,
          composition: p.description,
          priceRub: p.price,
          image: p.images[0] ? `${SITE_URL}${p.images[0]}` : null,
        })),
      },
      null,
      2,
    ) + '\n',
  );
}
```

(`const categories = [];` над циклом и комментарий `// Not an HTTP API — …` оставить. Комментарий про `ONLY_ACTIVE_IN_STORE` удалить вместе со старым циклом.)

- [ ] **Step 4: `build-feed.mjs`**

In `scripts/build-feed.mjs`:

1. Replace `import { dedupeProducts } from './lib/dedupeProducts.mjs';` with `import { catalogTiles, sectionProducts } from './lib/catalog-view.mjs';`; drop `readdirSync` from the `node:fs` import.

2. Replace the body of `buildFeed` up to `return {` with:

```js
  // Каталог — снимок выгрузки, тот же, что читают страницы.
  const catalog = readJson(path.join(root, 'data', 'catalog-export.json'));
  const sections = new Set(catalog.sections.map((s) => s.slug));
  const flowersPage = readJson(path.join(root, 'data', 'pages', 'flowers.json'));
  const flowerTiles = flowersPage.find((block) => block.kind === 'tiles')?.tiles ?? [];

  const categories = feedCategories({ catalogTiles: catalogTiles(catalog), flowerTiles, sections });
  // Те же товары, что видит покупатель: букеты в продаже, каждый один раз —
  // в категории своего главного раздела (букет может стоять в нескольких).
  const products = categories
    .filter((category) => category.section)
    .flatMap((category) =>
      sectionProducts(catalog, category.section)
        .filter((product) => product.mainSection === category.section)
        .map((product) => ({ ...product, section: category.section })),
    );
```

- [ ] **Step 5: Проверки, пересборка служебных файлов, сверка фида**

Before regenerating, save the current feed for comparison:

```bash
git stash push -- scripts/build-feed.mjs scripts/build-agent-assets.mjs
node scripts/build-feed.mjs && cp public/feed/products.xml /tmp/feed-before.xml
git stash pop
```

Run: `npm test`
Expected: PASS, включая `scripts/build-feed.test.mjs` без правок (те же категории, больше 300 предложений).

Run: `node scripts/build-feed.mjs && node scripts/build-agent-assets.mjs`

Run: `diff <(sed 's/date="[^"]*"//' /tmp/feed-before.xml) <(sed 's/date="[^"]*"//' public/feed/products.xml) && echo FEED-SAME`
Expected: `FEED-SAME` — фид не изменился: коробки без цены в него и раньше не попадали.

Run: `git status --short public/ && git diff --stat public/`
Expected — меняются только:
- `public/api/catalog/korobki.json`, `public/md/korobki.md`: минус 19 коробок с ценой 0;
- `public/api/catalog/pions.json`, `roses.json`, `mixflower.json`, `public/md/pions.md`, `roses.md`, `mixflower.md`: заголовок — название раздела вместо адреса;
- `public/api/index.json`: те же заголовки и `productCount` у `korobki`;
- `public/api/tilda-map.json`: порядок ключей; выключенные в Tilda товары ведут на свою страницу, а не в раздел;
- новый `public/api/redirects.json` с содержимым `{}`;
- `public/llms.txt` — без изменений.

Если меняется что-то ещё — остановиться и выяснить причину, прежде чем коммитить.

- [ ] **Step 6: Коммит**

```bash
git add scripts/lib/catalog-view.mjs scripts/lib/catalog-view.test.mjs scripts/build-agent-assets.mjs scripts/build-feed.mjs public/api public/md public/llms.txt
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): служебные файлы и фид из выгрузки, список переадресаций для сервера

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Переадресации каталога на сервере

**Files:**
- Create: `server-pay/catalog-redirect-lib.php`
- Create: `server-pay/catalog-redirect.php`
- Modify: `server-pay/.htaccess`
- Modify: `public/.htaccess`
- Test: `tests/php/site_redirect_test.php`
- Test: `scripts/site-htaccess.test.mjs`

**Interfaces:**
- Consumes: `public/api/redirects.json` из сборки (Task 7) — на сервере это `api/redirects.json` в корне сайта, `{"/откуда/": "/куда/"}`.
- Produces:
  - `catalog_redirect_target(string $uri, array $map): ?string`
  - точка входа `/pay/catalog-redirect.php`: 301 по списку или страница 404 с кодом 404
  - правило в `public/.htaccess`

- [ ] **Step 1: Проверки (пока падают)**

Create `tests/php/site_redirect_test.php`:

```php
<?php
/**
 * Переадресации каталога: адрес букета без страницы → куда вести по списку
 * api/redirects.json. Только адреса этого сайта — открытой переадресации
 * на чужой сайт быть не должно.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/catalog-redirect-lib.php';

$map = [
    '/korobki/buket-a/' => '/bukety/buket-a/',
    '/bukety/udalen/' => '/bukety/',
    '/bukety/chuzhoy/' => '//evil.example/',
    '/bukety/chuzhoy-2/' => 'https://evil.example/',
];

t_equal(catalog_redirect_target('/korobki/buket-a/', $map), '/bukety/buket-a/', 'букет переехал — на новый адрес');
t_equal(catalog_redirect_target('/korobki/buket-a', $map), '/bukety/buket-a/', 'без косой черты в конце — тоже');
t_equal(catalog_redirect_target('/korobki/buket-a/?utm_source=vk', $map), '/bukety/buket-a/', 'метки в адресе не мешают');
t_equal(catalog_redirect_target('/bukety/udalen/', $map), '/bukety/', 'удалённый — в раздел');
t_equal(catalog_redirect_target('/bukety/net-takogo/', $map), null, 'нет в списке — переадресации нет (будет 404)');
t_equal(catalog_redirect_target('/bukety/chuzhoy/', $map), null, 'адрес вида //сайт не пропускается');
t_equal(catalog_redirect_target('/bukety/chuzhoy-2/', $map), null, 'адрес с https:// не пропускается');
t_equal(catalog_redirect_target('/pay/catalog-redirect.php', $map), null, 'прямой заход на скрипт — не адрес букета');
t_equal(catalog_redirect_target('/a/b/c/', $map), null, 'три части — не адрес букета');
t_equal(catalog_redirect_target('', $map), null, 'пустой адрес');
```

Create `scripts/site-htaccess.test.mjs`:

```js
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const site = readFileSync(path.join(root, 'public/.htaccess'), 'utf8');
const pay = readFileSync(path.join(root, 'server-pay/.htaccess'), 'utf8');

describe('правило переадресаций каталога в .htaccess', () => {
  const rule = 'RewriteRule ^([a-z0-9_-]+)/([a-z0-9_-]+)/?$ /pay/catalog-redirect.php [L]';

  it('есть и срабатывает, только когда у адреса нет своей страницы', () => {
    const at = site.indexOf(rule);
    expect(at).toBeGreaterThan(0);
    expect(site.slice(0, at)).toMatch(/RewriteCond %\{DOCUMENT_ROOT\}\/\$1\/\$2\/index\.html !-f\s*$/);
  });

  it('не трогает служебные папки', () => {
    for (const dir of ['blog', 'pay', 'api', 'images', 'md', '_next', 'feed', 'fonts', '\\.well-known']) {
      expect(site).toContain(dir);
    }
    expect(site).toContain('RewriteCond %{REQUEST_URI} !^/(blog|pay|api|images|md|_next|feed|fonts|\\.well-known)/ [NC]');
  });

  it('стоит после правил для адресов Tilda и защиты от парсеров', () => {
    expect(site.indexOf(rule)).toBeGreaterThan(site.indexOf('tilda-redirect.php'));
    expect(site.indexOf(rule)).toBeGreaterThan(site.lastIndexOf('RewriteRule .* - [F,L]'));
  });

  it('библиотека переадресаций закрыта снаружи', () => {
    expect(pay).toContain('<Files "catalog-redirect-lib.php">');
  });
});
```

Run: `npx vitest run scripts/site-htaccess.test.mjs`
Expected: FAIL — правила нет.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '…/server-pay/catalog-redirect-lib.php'`.

- [ ] **Step 2: Реализация**

Create `server-pay/catalog-redirect-lib.php`:

```php
<?php
/**
 * Переадресации каталога: старые адреса букетов — на новые.
 *
 * Список ведёт админка (таблица redirects). Он приходит со сборкой сайта
 * в api/redirects.json в виде {"/откуда/": "/куда/"}. Правило в .htaccess
 * шлёт в catalog-redirect.php адреса вида /<раздел>/<букет>/, у которых в
 * сборке нет страницы: букет переехал в другой раздел или удалён.
 */

declare(strict_types=1);

/** Куда вести адрес $uri по списку $map; null — переадресации нет. */
function catalog_redirect_target(string $uri, array $map): ?string
{
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~^/[a-z0-9_-]+/[a-z0-9_-]+/?\z~', $path)) {
        return null;
    }
    $to = $map[rtrim($path, '/') . '/'] ?? null;
    // Только адрес этого же сайта: «//чужой.сайт» и «https://…» не пропускаем,
    // даже если они как-то попали в файл.
    if (!is_string($to) || !preg_match('~^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*/\z~', $to)) {
        return null;
    }
    return $to;
}
```

Create `server-pay/catalog-redirect.php`:

```php
<?php
/**
 * Адрес букета, у которого в сборке нет страницы: переадресация по
 * api/redirects.json или наша страница 404 с кодом 404. Сюда шлёт правило
 * в .htaccess сайта; логика — в catalog-redirect-lib.php.
 */

declare(strict_types=1);

require __DIR__ . '/catalog-redirect-lib.php';

$map = json_decode((string)@file_get_contents(__DIR__ . '/../api/redirects.json'), true);
$target = catalog_redirect_target((string)($_SERVER['REQUEST_URI'] ?? ''), is_array($map) ? $map : []);

if ($target !== null) {
    // 301: адрес сменился навсегда — поисковик перенесёт на новую страницу то,
    // что накопила старая.
    header('Location: ' . $target, true, 301);
    header('Cache-Control: max-age=3600');
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
if (@readfile(__DIR__ . '/../404.html') === false) {
    echo 'Страница не найдена';
}
```

In `server-pay/.htaccess`, after the `<Files "feed-img-lib.php"> … </Files>` block add:

```apache
<Files "catalog-redirect-lib.php">
  Require all denied
</Files>
```

In `public/.htaccess`, inside `<IfModule mod_rewrite.c>`, after the last anti-scraper rule (the block ending with `RewriteRule \.(jpe?g|png|webp|gif|svg)$ - [F,NC,L]`) and before `</IfModule>`, add:

```apache

  # --- Переадресации каталога ---------------------------------------------
  # Букет переехал в другой раздел или удалён — его старый адрес вида
  # /<раздел>/<букет>/ ведёт на новое место. Список приходит со сборкой
  # (api/redirects.json); отвечает /pay/catalog-redirect.php: нашёл — 301,
  # нет — наша страница 404 с кодом 404. Адреса, у которых страница есть,
  # и служебные папки правило не трогает.
  RewriteCond %{REQUEST_URI} !^/(blog|pay|api|images|md|_next|feed|fonts|\.well-known)/ [NC]
  RewriteCond %{DOCUMENT_ROOT}/$1/$2/index.html !-f
  RewriteRule ^([a-z0-9_-]+)/([a-z0-9_-]+)/?$ /pay/catalog-redirect.php [L]
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `npx vitest run scripts/site-htaccess.test.mjs && npm test && /c/php82/php.exe tests/php/run.php && /c/php82/php.exe -l server-pay/catalog-redirect.php && /c/php82/php.exe -l server-pay/catalog-redirect-lib.php`
Expected: всё PASS, `не прошло: 0`, `No syntax errors detected` дважды.

Списка файлов `/pay/` в проверках выкладки нет (`deploy-lib.php` проверяет только `DEPLOY_REQUIRED` сайта и `php -l` каждого PHP-файла), поэтому новые файлы туда добавлять не нужно.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/catalog-redirect-lib.php server-pay/catalog-redirect.php server-pay/.htaccess public/.htaccess tests/php/site_redirect_test.php scripts/site-htaccess.test.mjs
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): переадресации каталога — правило .htaccess и pay/catalog-redirect.php

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Проверка перед сборкой, сравнение сборок и документация

**Files:**
- Modify: `package.json` (`prebuild`)
- Create: `scripts/compare-builds.mjs`
- Modify: `docs/catalog.md`
- Modify: `docs/known-follow-ups.md`
- Modify: `docs/superpowers/specs/2026-10-05-catalog-admin-design.md` (уточнение)

**Interfaces:**
- Consumes: всё из Tasks 1–8.
- Produces: `prebuild` не даёт собрать сайт из повреждённой выгрузки. `node scripts/compare-builds.mjs <до/out> <после/out>` — отчёт «тот же ли сайт» (понадобится и на этапе 3).

- [ ] **Step 1: Проверка выгрузки перед сборкой**

In `package.json`, `"prebuild"` becomes:

```json
    "prebuild": "node scripts/apply-catalog-export.mjs --check && node scripts/build-agent-assets.mjs && node scripts/build-feed.mjs",
```

Run: `npm run build`
Expected: первой строкой `Выгрузка в порядке: разделов 13, букетов 487, версия …`, затем сборка проходит.

- [ ] **Step 2: Сравнение сборок**

Create `scripts/compare-builds.mjs`:

```js
/**
 * Сравнение двух сборок сайта (папок out/): получился ли тот же сайт.
 *
 *   node scripts/compare-builds.mjs <до>/out <после>/out
 *
 * Печатает:
 * - адреса карты сайта, которые пропали или появились;
 * - страницы (index.html), которые пропали или появились;
 * - разделы, где поменялся список букетов в разметке (адрес и цена каждого);
 * - страницы букетов, где поменялись название, цена, наличие или фото;
 * - отличается ли фид (без даты).
 * Нужен при смене источника каталога (этапы 2В и 3): «тот же сайт» — это те
 * же адреса, цены, фото и порядок.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';

function walk(dir, base = dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) walk(full, base, out);
    else out.push(path.relative(base, full).split(path.sep).join('/'));
  }
  return out;
}

const sitemap = (root) =>
  new Set([...readFileSync(path.join(root, 'sitemap.xml'), 'utf8').matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]));

function facts(root, rel) {
  const html = readFileSync(path.join(root, rel), 'utf8');
  const blocks = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
  const list = blocks.find((d) => d['@type'] === 'ItemList');
  const product = blocks.find((d) => d['@type'] === 'Product');
  return {
    list: list ? list.itemListElement.map((i) => `${i.item.url} ${i.item.offers?.price}`).join('\n') : null,
    product: product
      ? [product.name, product.offers?.price ?? '—', product.offers?.availability ?? '—', (product.image ?? []).join(',')].join(' | ')
      : null,
  };
}

const diff = (a, b) => [[...a].filter((x) => !b.has(x)), [...b].filter((x) => !a.has(x))];

const [before, after] = process.argv.slice(2);
if (!before || !after || !existsSync(before) || !existsSync(after)) {
  console.error('Как вызывать: node scripts/compare-builds.mjs <до>/out <после>/out');
  process.exit(2);
}

const [goneUrls, newUrls] = diff(sitemap(before), sitemap(after));
console.log(`Карта сайта: было ${sitemap(before).size}, стало ${sitemap(after).size}.`);
for (const u of goneUrls) console.log(`  − ${u}`);
for (const u of newUrls) console.log(`  + ${u}`);

const pages = (root) => new Set(walk(root).filter((f) => f.endsWith('index.html')));
const [gonePages, newPages] = diff(pages(before), pages(after));
console.log(`Страницы: пропало ${gonePages.length}, появилось ${newPages.length}.`);
for (const p of [...gonePages.map((x) => `  − ${x}`), ...newPages.map((x) => `  + ${x}`)].slice(0, 40)) console.log(p);

let listChanges = 0;
let productChanges = 0;
for (const rel of [...pages(before)].filter((p) => pages(after).has(p))) {
  const a = facts(before, rel);
  const b = facts(after, rel);
  if (a.list !== b.list) {
    listChanges++;
    const [gone, added] = diff(new Set((a.list ?? '').split('\n')), new Set((b.list ?? '').split('\n')));
    console.log(`Список букетов на ${rel}: убрано ${gone.length}, добавлено ${added.length}${gone.length + added.length === 0 ? ' (поменялся порядок)' : ''}.`);
  }
  if (a.product !== b.product) {
    productChanges++;
    if (productChanges <= 25) console.log(`Букет ${rel}:\n  было  ${a.product}\n  стало ${b.product}`);
  }
}
console.log(`Разделов с другим списком: ${listChanges}. Страниц букетов с другими данными: ${productChanges}.`);

const feed = (root) => readFileSync(path.join(root, 'feed/products.xml'), 'utf8').replace(/date="[^"]*"/, '');
console.log(feed(before) === feed(after) ? 'Фид: тот же.' : 'Фид: ОТЛИЧАЕТСЯ.');
```

- [ ] **Step 3: Сравнить сборку ветки со сборкой `master`**

```bash
BASE=$(git merge-base HEAD master)
git worktree add ../pion-before "$BASE"
(cd ../pion-before && npm ci && npm run build)
npm run build
node scripts/compare-builds.mjs ../pion-before/out out | tee /tmp/compare-2v.txt
git worktree remove --force ../pion-before
```

Expected:
- `Карта сайта: было 515, стало 515.` — без строк «−»/«+»;
- `Страницы: пропало 0, появилось 0.`;
- `Список букетов на korobki/index.html: убрано 19, добавлено 0.` — и больше ни одного раздела с другим списком; `bukety-do-5000/index.html` и `index.html` не меняются;
- страниц букетов с другими данными — ровно 19, все из `korobki`: было `… | 0 | https://schema.org/PreOrder | …`, стало `… | — | — | …`;
- `Фид: тот же.`

Если вывод отличается — остановиться и выяснить причину. Расхождение — это находка в коде Tasks 4–8: чинить код, а не ожидания. Весь вывод приложить к отчёту.

- [ ] **Step 4: Документация**

In `docs/catalog.md`, after the section `## Выгрузка` (before `## Админка`), add:

```markdown
## Сайт из выгрузки (этап 2В)

Сайт читает каталог из одного файла — `data/catalog-export.json`. Это
выгрузка в формате админки, та же, что отдаёт `/pay/catalog-export.php`.
Разделы, плитки сетки, страницы букетов (в продаже и снятых), «Букеты до
5000», карта сайта, фид, `api/catalog`, `api/tilda-map.json` и
`api/redirects.json` строятся из него через `scripts/lib/catalog-view.mjs`.

Пока каталог не переехал в базу (этап 3), файл собирается из нынешних файлов
Tilda: `npm run catalog:snapshot` (`scripts/make-catalog-export.mjs` читает
`data/catalog/*.json` и `data/catalog-meta.json`). Поправили эти файлы —
пересоберите снимок, иначе упадёт проверка `scripts/make-catalog-export.test.mjs`.

Перед каждой сборкой `prebuild` проверяет снимок
(`node scripts/apply-catalog-export.mjs --check`). Проверяется вот что:
- uid не повторяются;
- главный раздел есть среди разделов букета;
- пары «раздел + slug» не повторяются;
- цены целые, у букета в продаже больше нуля;
- разделы букетов существуют, плитки ведут в видимые разделы;
- переадресации без цепочек и только на этот сайт;
- версия sha256 сходится с содержимым.

На этапе 3 тот же скрипт будет принимать выгрузку с сервера: он
останавливает сборку, если букетов стало меньше 70% от прошлой (флаг
`--allow-shrink`).

Переадресации: правило в `public/.htaccess` отправляет адрес вида
`/<раздел>/<букет>/` без своей страницы в `/pay/catalog-redirect.php`. Тот
ищет его в `api/redirects.json` и отвечает 301 или страницей 404 с кодом 404.

19 «Цветочных коробок» с ценой 0 ₽ (в Tilda без цены и выключены) в снимке
сняты с продажи: в разделе их нет, страница осталась с пометкой «Сейчас нет в
продаже». Когда у них появятся цены, салон вернёт их в продажу в админке.

Сравнить две сборки («тот же ли сайт»): `node scripts/compare-builds.mjs <до>/out <после>/out`.
```

In `docs/known-follow-ups.md`, add a section after «Админка — этап 2Б (октябрь 2026)» (по образцу соседних разделов):

```markdown
## Сайт из выгрузки — этап 2В (октябрь 2026)

План `docs/superpowers/plans/2026-10-08-catalog-site-from-export.md`. Сайт
читает `data/catalog-export.json`; пока это снимок нынешних файлов Tilda.

**Сделать на этапе 3 (переключение):**
- [ ] Раздел «Новинки» (`novinki`, без плитки) с тремя нынешними карточками
  главной добавить в выгрузку при переносе. Их фото скопировать на сервер в
  `images/catalog/novinki/`: путь `/images/site/…` админка не примет. После
  этого убрать запасной путь на главной (`site.newProducts`) и `newProducts`
  из `data/site.json`, а после первой выкладки — `EXTRA_ITEMS` из `config.php`.
- [ ] `deploy.yml`: скачать выгрузку с `/pay/catalog-export.php`, пропустить
  через `apply-catalog-export.mjs` (флаг `allow_shrink` — вручную), собрать.
- [ ] Снятый букет в корзине: сейчас оформление отвечает «Товар не найден:
  <uid>». Нужно «Букета «…» сейчас нет в продаже — уберите его из корзины»:
  корзина присылает название, сервер берёт его только для сообщения. Это
  правка платёжной части — отдельной задачей с проверками.
- [ ] 19 коробок без цены: салон ставит цены и возвращает их в продажу или
  оставляет снятыми.
- [ ] После переключения убрать `data/catalog/*.json`, `data/catalog-meta.json`,
  `make-catalog-export.mjs` и проверку свежести снимка (или оставить снимок
  для разработки, но без сверки с файлами Tilda). Обёртка
  `src/lib/dedupeProducts.ts` тогда тоже не нужна.
- [ ] Проверить на живом сайте: адрес `/<раздел>/<нет-такого>/` отдаёт нашу
  страницу 404 с кодом 404, а переадресация из админки — 301.
```

In `docs/superpowers/specs/2026-10-05-catalog-admin-design.md`, at the end of section «### 6. Изменения в сайте (Next.js)» add:

```markdown
- **Уточнение 2026-10-08 (план 2В):** выгрузка не раскладывается на
  `data/catalog/<раздел>.json`, `catalog-meta.json` и `redirects.json` —
  сайт читает её одним файлом `data/catalog-export.json`, представления
  строит `scripts/lib/catalog-view.mjs`. До этапа 3 этот файл — снимок
  нынешних файлов (`make-catalog-export.mjs`, без «Новинок»); у снятого с
  продажи букета допустима цена 0 ₽ (старые коробки без цены).
```

- [ ] **Step 5: Все проверки**

Run: `npm test && /c/php82/php.exe tests/php/run.php && npm run build`
Expected: vitest — все PASS; PHP — `не прошло: 0`; сборка проходит.

- [ ] **Step 6: Коммит и отправка ветки**

```bash
git add package.json scripts/compare-builds.mjs docs/catalog.md docs/known-follow-ups.md docs/superpowers/specs/2026-10-05-catalog-admin-design.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "chore(site): проверка выгрузки перед сборкой, сравнение сборок, документация этапа 2В

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin catalog-site
```

---

## После слияния в `master`

Автовыкладка увезёт сайт за 5–20 минут. Проверить снаружи, по одному запросу на адрес (хостинг банит IP за частые обращения):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/bukety/buket-barhatnye-grani/
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/bukety/takogo-buketa-net/
curl -s -o /dev/null -w '%{http_code}\n' https://pionperm.ru/pay/catalog-redirect-lib.php
curl -s https://pionperm.ru/api/redirects.json
```

Expected: `200`; `404` (страница 404 сайта, через `catalog-redirect.php`); `403`; `{}`.
