# Готовность к переносу каталога — план (этап 3А)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрыть в коде пункты из списков «перед этапом 3» (`docs/known-follow-ups.md`, разделы 2Б и 2В), которые относятся к админке, данным и сайту, — чтобы перенос каталога в базу и включение админки (этапы 3Б–3В) не упёрлись в известные ловушки.

**Architecture:** Точечные правки в существующих модулях. Админка (`server-pay/admin/`) и данные каталога (`server-pay/catalog/`) получают защиты: возврат в продажу только с ценой, публикация только с фото, новый раздел скрыт, загрузка каталога проверяет фото, карточка возвращает фото из корзины, мусор в `state.json` не роняет страницы. Сайт: плитка без фото не выводится, тексты снятого букета честные, фото «Новинок» — ссылка. Фид без повторов. Корзина объясняет, что букета больше нет в продаже. Решения владельца и принятые исключения записываются в документацию.

**Tech Stack:** PHP 8.2/8.3 (проверки `tests/php/run.php`), SQLite 3.26 на сервере, Next.js 14 (static export), TypeScript, Node 20 ESM, vitest.

## Global Constraints

- `master` — это боевой сайт. Работа — в ветке `catalog-ready` (от `master`); ветку можно отправлять, слияние — только после итоговой проверки.
- Коммиты — с `GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com"` и последней строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Всё, что видит покупатель и сотрудник салона, — по-русски, на «вы», без технических слов.
- Никаких новых npm-зависимостей. SQL — не новее SQLite 3.26 (проверка `tests/php/catalog_sqlite326_test.php`).
- Не менять: `data/catalog/*.json`, `data/catalog-meta.json`, `data/site.json`, `data/catalog-export.json` (снимок пересобирается только через `npm run catalog:snapshot`, и в этом плане — не нужно), фото в `public/images/`.
- Платёжная часть (`server-pay/lib.php`, `init.php`, `uds-check.php`) меняется **только** в Task 9 и только текстом ошибки для отсутствующего товара.
- Сайт после слияния выглядит и работает как сейчас: каталог в снимке не меняется, «Новинки» на главной — по-прежнему из `site.json` (раздела `novinki` в снимке нет).
- Проверки: `npm test`, `/c/php82/php.exe tests/php/run.php`, `npm run build` — без ошибок, в выводе PHP-проверок без предупреждений.

## Решения владельца и принятые при составлении плана

- **19 «Цветочных коробок» с ценой 0 ₽ остаются снятыми с продажи** (решение владельца, 2026-10-08).
- **Опубликовать букет без фото нельзя** — проверка в админке (карточка черновика), а не в данных: загрузка каталога и проверки не трогаются.
- **Новый раздел создаётся скрытым** («Показывать в каталоге» снято), пока у него нет фото плитки; сайт дополнительно не выводит плитку без фото.
- **Список букетов в админке** показывает по 60 штук с кнопкой «Показать ещё»; миниатюры — ленивые, с размерами.
- **Форма входа без CSRF-токена** и **админка на одном адресе с WordPress** — принятые исключения, записываются в документацию (Task 10).

## Не входит (этапы 3Б и 3В)

3Б, конвейер: `deploy.yml` берёт выгрузку с сервера (за переключателем), `--previous-count` и доработки `apply-catalog-export.mjs`, порядок «тесты → выгрузка → сборка», каталог в `build-info.json` и `state.json`, сторож выкладки каталога и тревоги обслуживания в Telegram, уборка фото ждёт выкладки, доработки `compare-builds.mjs`, PHP-проверки на настоящей SQLite 3.26 в CI, пометка плохих сборок и копирование только изменившихся файлов. 3В, переключение: раздел «Новинки» и его фото, загрузка каталога в базу, учётки, расписание, `EXTRA_ITEMS`, `REMOTE_ADDR`, проверка с телефона.

## Файлы

| Файл | Что меняется |
|---|---|
| `server-pay/catalog/products.php` | Возврат в продажу — только с ценой |
| `server-pay/catalog/sections.php`, `server-pay/admin/lib/view.php` | Новый раздел скрыт; текст уведомления |
| `server-pay/admin/lib/pages-products.php` | Публикация только с фото; сохранение возвращает фото из корзины; список по 60 |
| `server-pay/catalog/import.php`, `import-cli.php` | Загрузка проверяет пути и наличие фото |
| `server-pay/admin/lib/status.php` | Мусор в `catalogChangedAt` не роняет страницы |
| `scripts/lib/catalog-view.mjs`, `src/app/[slug]/[product]/page.tsx`, `src/components/ProductCard/*` | Плитка без фото, тексты снятого, фото «Новинок» — ссылка |
| `scripts/lib/yml-feed.mjs`, `scripts/build-feed.mjs` | Фид без повторов |
| `tests/php/fixtures/catalog-export-chars.json` | Второй образец выгрузки — «трудные» символы |
| `server-pay/cart-lib.php`, `server-pay/lib.php`, `server-pay/.htaccess`, `src/components/CheckoutForm/CheckoutForm.tsx` | Понятное сообщение о снятом букете |
| `docs/*` | Решения и отметки в списках |

---

### Task 1: Админка — возврат в продажу только с ценой, публикация только с фото, новый раздел скрыт

**Files:**
- Modify: `server-pay/catalog/products.php` (`catalog_unhide`, `catalog_change_status`)
- Modify: `server-pay/admin/lib/pages-products.php` (`admin_product_save`)
- Modify: `server-pay/catalog/sections.php` (`catalog_create_section`)
- Modify: `server-pay/admin/lib/view.php` (`ADMIN_NOTICES['section-created']`)
- Test: `tests/php/catalog_ready_test.php`

**Interfaces:**
- Consumes: `catalog_create_product`, `catalog_publish`, `catalog_hide`, `catalog_unhide`, `catalog_create_section`, `CATALOG_PRICE_MIN` (2А); `t_admin_ctx`, `t_admin_call` (`tests/php/admin_fixture.php`); `t_catalog_with_sections`, `t_fields`, `t_row`, `t_now`, `t_case` (`tests/php/catalog_fixture.php`).
- Produces:
  - `catalog_change_status(…, DateTimeImmutable $now, ?Closure $check = null): void` — `$check($row)` вызывается внутри транзакции после проверки статуса;
  - `catalog_unhide` бросает `CatalogError` «Сначала укажите цену в карточке — от 100 ₽, потом возвращайте букет в продажу.», если цена меньше `CATALOG_PRICE_MIN`;
  - `admin_product_save(..., 'publish')` без фото — 422 с текстом «Добавьте хотя бы одно фото — без него букет не опубликовать.»;
  - `catalog_create_section` создаёт раздел с `visible = 0`.

- [ ] **Step 1: Проверки (пока падают)**

Create `tests/php/catalog_ready_test.php`:

```php
<?php
/**
 * Готовность к переносу (этап 3А): ловушки, которые админка не должна
 * пропускать, когда в базе появится настоящий каталог.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';

t_case('вернуть в продажу без цены нельзя', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    catalog_hide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    // Так выглядят 19 коробок из Tilda после загрузки: сняты, цены нет.
    $db->prepare('UPDATE products SET price = 0 WHERE uid = ?')->execute([$uid]);
    $e = t_throws(fn () => catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now()), CatalogError::class, 'без цены — отказ');
    t_true($e !== null && str_contains($e->getMessage(), 'Сначала укажите цену'), 'понятное объяснение');
    t_equal(t_row($db, $uid)['status'], 'hidden', 'букет остался снятым');

    $db->prepare('UPDATE products SET price = 4400 WHERE uid = ?')->execute([$uid]);
    catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    t_equal(t_row($db, $uid)['status'], 'active', 'с ценой — вернулся в продажу');
});

t_case('опубликовать черновик без фото нельзя', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $post = [
        'action' => 'publish', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
    ];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_true($r['status'] === 422 && str_contains($r['body'], 'Добавьте хотя бы одно фото'), 'без фото — объяснение, форма на месте');
    t_equal(t_row($ctx['db'], $uid)['status'], 'draft', 'остался черновиком');

    $post['images'] = ['/images/catalog/bukety/buket-nezhnost-' . $uid . '-aaaaaaaa.webp'];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_equal($r['status'], 303, 'с фото — опубликован');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'в продаже');
});

t_case('новый раздел создаётся скрытым', function (): void {
    $db = t_catalog_with_sections();
    $slug = catalog_create_section($db, 'anna', 'Осень', t_now());
    $q = $db->prepare('SELECT visible FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    t_equal((int)$q->fetchColumn(), 0, 'без фото плитки раздел в каталоге не показывается');
    t_true(!in_array(['type' => 'section', 'slug' => $slug], catalog_export_data($db)['tiles'], true), 'в сетке выгрузки его нет');
    t_true(str_contains(ADMIN_NOTICES['section-created'], 'скрыт'), 'сотруднику объяснено, что раздел пока скрыт');
});
```

Run: `/c/php82/php.exe tests/php/run.php`
Expected: три падения: возврат без цены проходит; публикация без фото проходит (303); раздел создаётся видимым.

- [ ] **Step 2: Возврат в продажу — только с ценой**

In `server-pay/catalog/products.php` replace `catalog_unhide` and `catalog_change_status` with:

```php
/**
 * Вернуть в продажу — на прежнее место, по тому же адресу. Только с ценой:
 * у коробок из Tilda её нет (0 ₽), а букет в продаже за 0 ₽ сайт не примет —
 * сборка остановится целиком.
 */
function catalog_unhide(PDO $db, string $login, string $uid, int $version, DateTimeImmutable $now): void
{
    catalog_change_status(
        $db, $login, $uid, $version, 'hidden', 'active', 'Вернуть в продажу можно только снятый букет.', $now,
        static function (array $p): void {
            if ((int)$p['price'] < CATALOG_PRICE_MIN) {
                throw new CatalogError('Сначала укажите цену в карточке — от ' . CATALOG_PRICE_MIN . ' ₽, потом возвращайте букет в продажу.');
            }
        },
    );
}

/**
 * Смена статуса одной транзакцией. $check — дополнительная проверка букета
 * (уже прочитанного внутри транзакции): бросает CatalogError, если нельзя.
 */
function catalog_change_status(
    PDO $db,
    string $login,
    string $uid,
    int $version,
    string $from,
    string $to,
    string $error,
    DateTimeImmutable $now,
    ?Closure $check = null,
): void {
    catalog_tx($db, function () use ($db, $login, $uid, $version, $from, $to, $error, $now, $check): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] !== $from) {
            throw new CatalogError($error);
        }
        if ($check !== null) {
            $check($p);
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
```

(Цена 100 ₽ пишется как «100 ₽» — `CATALOG_PRICE_MIN` выводится числом; если в проекте для рублей есть общий форматтер в каталоге, не тянуть его сюда: текст должен совпадать с проверкой выше.)

- [ ] **Step 3: Публикация — только с фото**

In `server-pay/admin/lib/pages-products.php`, in `admin_product_save`, right after the line `catalog_product_fields($ctx['db'], $fields, $p['main_section']);` add:

```php
        // Без фото на сайте пустая карточка: публикуем только с фото.
        if ($action === 'publish' && $fields['images'] === []) {
            throw new CatalogError('Добавьте хотя бы одно фото — без него букет не опубликовать.');
        }
```

Существующие проверки, которые публикуют черновик через карточку без фото (`tests/php/admin_*_test.php`), получат 422. Исправить их **данные**, а не ожидания: добавить в POST `'images' => ['/images/catalog/bukety/<любое-имя>.webp']` (путь должен проходить `CATALOG_IMAGE_PATH`). Перечислить исправленные проверки в отчёте.

- [ ] **Step 4: Новый раздел — скрытым**

In `server-pay/catalog/sections.php`, in `catalog_create_section`:
- update the doc comment: `Новый раздел: slug из названия, уникальный и не совпадающий с адресами сайта (иначе -2, -3); плитка встаёт в конец сетки. Раздел создаётся скрытым: без фото плитки на сайте была бы пустая картинка — сотрудник показывает его, когда добавит фото.`
- replace the `INSERT INTO sections …` statement with:

```php
        $db->prepare('INSERT INTO sections (slug, label, cover_title, heading, visible, updated_at) VALUES (?, ?, ?, ?, 0, ?)')
            ->execute([$slug, $label, $label, mb_strtoupper($label), catalog_iso($now)]);
```

In `server-pay/admin/lib/view.php` replace the value of `ADMIN_NOTICES['section-created']` with:

```php
    'section-created' => 'Раздел создан и пока скрыт. Добавьте фото плитки и отметьте «Показывать в каталоге».',
```

Проверки, которые ждали видимый новый раздел (например, в `tests/php/catalog_sections_test.php` или `admin_sections_test.php`), поправить по смыслу: раздел скрыт до тех пор, пока его не показали. Перечислить в отчёте.

- [ ] **Step 5: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php && npm test`
Expected: `не прошло: 0`, без предупреждений; vitest — PASS.

- [ ] **Step 6: Коммит**

```bash
git add server-pay/catalog/products.php server-pay/catalog/sections.php server-pay/admin/lib/pages-products.php server-pay/admin/lib/view.php tests/php/
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): возврат в продажу только с ценой, публикация только с фото, новый раздел скрыт

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Сайт — плитка без фото, тексты снятого букета, фото «Новинок» — ссылка

**Files:**
- Modify: `scripts/lib/catalog-view.mjs` (`catalogTiles`)
- Modify: `scripts/lib/catalog-view.test.mjs`
- Modify: `src/app/[slug]/[product]/page.tsx`
- Modify: `src/components/ProductCard/ProductCard.tsx`, `src/components/ProductCard/ProductCard.module.css`

**Interfaces:**
- Consumes: `catalogTiles` (2В), `getRelatedProducts`, `getSite` (2В).
- Produces: `catalogTiles` не выводит плитку раздела с пустым `tileImage`; страница снятого букета — заголовок «… — нет в продаже | Салон «Пион»», подсказка по наличию похожих; `ProductCard` с `href` делает ссылкой и фото.

- [ ] **Step 1: Проверка (пока падает)**

Append to `scripts/lib/catalog-view.test.mjs` (inside the existing `describe('catalog-view на выгрузке-образце', …)` or as a new `describe`):

```js
describe('плитки без фото', () => {
  it('раздел без фото плитки в сетку не выводится', () => {
    const exp = load();
    exp.sections.find((s) => s.slug === 'bukety').tileImage = '';
    expect(catalogTiles(exp).map((t) => t.label)).toEqual(['Цветы', 'Создать уникальный букет']);
  });
});
```

Run: `npx vitest run scripts/lib/catalog-view.test.mjs`
Expected: FAIL — плитка «Букеты» с пустой картинкой осталась.

- [ ] **Step 2: `catalogTiles`**

In `scripts/lib/catalog-view.mjs` replace `catalogTiles` with:

```js
/**
 * Плитки сетки каталога: у раздела — его название, адрес и фото плитки.
 * Раздел без фото плитки в сетку не выводится: пустая картинка выглядела бы
 * поломкой (админка и так создаёт новый раздел скрытым).
 */
export function catalogTiles(exp) {
  return exp.tiles.flatMap((t) => {
    if (t.type !== 'section') return [{ label: t.label, href: t.href, image: t.image }];
    const section = findSection(exp, t.slug);
    return section && section.tileImage ? [{ label: section.label, href: `/${section.slug}`, image: section.tileImage }] : [];
  });
}
```

- [ ] **Step 3: Тексты на странице снятого букета**

In `src/app/[slug]/[product]/page.tsx`:

1. In `generateMetadata`, replace `const title = `${name} — купить в Перми | Салон «Пион»`;` with:

```tsx
  // «Купить» в заголовке снятого букета обещало бы то, чего нельзя.
  const title = onSale ? `${name} — купить в Перми | Салон «Пион»` : `${name} — нет в продаже | Салон «Пион»`;
```

2. In `ProductPage`, replace the hidden-branch paragraph `<p className={styles.assurance}>Посмотрите похожие букеты ниже — их можно заказать.</p>` with:

```tsx
            <p className={styles.assurance}>
              {related.length > 0
                ? 'Посмотрите похожие букеты ниже — их можно заказать.'
                : `Позвоните нам — ${site.phone}, подскажем, что собрать вместо него.`}
            </p>
```

- [ ] **Step 4: Фото «Новинок» — ссылка**

In `src/components/ProductCard/ProductCard.tsx`:
- add `import Image from 'next/image';`;
- replace the `<Gallery … />` element inside `.imageWrap` with:

```tsx
        {/* У букета каталога есть страница — фото ведёт на неё одним снимком.
            Витрина из CRM своих страниц не имеет и листает все кадры в карточке. */}
        {href && product.images[0] ? (
          <Link href={href} className={styles.photoLink} aria-label={product.title}>
            <Image
              src={product.images[0]}
              alt={product.title}
              fill
              sizes="(max-width: 900px) 50vw, 300px"
              className={styles.photo}
            />
          </Link>
        ) : (
          <Gallery
            images={product.images}
            alt={product.title}
            sizes="(max-width: 900px) 50vw, 300px"
            compact
          />
        )}
```

In `src/components/ProductCard/ProductCard.module.css` add (размер — как у кадра `Gallery` в режиме `compact`: сверить с `src/components/Gallery/Gallery.module.css` и, если у `.imageWrap` нет `position: relative`, добавить его):

```css
/* Фото-ссылка у карточки с собственной страницей (букет каталога в «Новинках»). */
.photoLink {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
}

.photo {
  object-fit: cover;
}
```

Если у `Gallery` в режиме `compact` другая пропорция кадра, взять её вместо `1 / 1` — фото-ссылка должна занимать ровно то же место, что карусель. Записать в отчёт, какая пропорция взята и откуда.

- [ ] **Step 5: Проверки и сборка**

Run: `npx vitest run scripts/lib/catalog-view.test.mjs && npm test && npx tsc --noEmit && npm run build`
Expected: PASS; сборка проходит.

Run: `node -e "const exp=require('./data/catalog-export.json');const p=exp.products.find(x=>x.status==='hidden');const h=require('fs').readFileSync('out/'+p.mainSection+'/'+p.slug+'/index.html','utf8');console.log(/<title>[^<]*нет в продаже/.test(h), h.includes('купить в Перми'))"`
Expected: `true false`.

На главной «Новинки» по-прежнему из `site.json` (без ссылок) — `out/index.html` не меняется: `node scripts/compare-builds.mjs` здесь не нужен, достаточно `git stash`-free проверки, что в `out/index.html` нет `photoLink`.

prebuild перегенерирует `public/` — изменений там быть не должно (`git status`); если есть — выяснить, прежде чем коммитить.

- [ ] **Step 6: Коммит**

```bash
git add scripts/lib/catalog-view.mjs scripts/lib/catalog-view.test.mjs 'src/app/[slug]/[product]/page.tsx' src/components/ProductCard/ProductCard.tsx src/components/ProductCard/ProductCard.module.css
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(site): плитка без фото не выводится, честные тексты снятого букета, фото «Новинок» — ссылка

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Загрузка каталога проверяет фото

**Files:**
- Modify: `server-pay/catalog/import.php`
- Modify: `server-pay/catalog/import-cli.php`
- Modify: `tests/php/catalog_import_test.php`, `tests/php/catalog_snapshot_test.php`

**Interfaces:**
- Consumes: `CATALOG_IMAGE_PATH` (`products.php`), `CATALOG_SITE_IMAGE` (`sections.php`).
- Produces: `catalog_import(PDO $db, array $export, DateTimeImmutable $now, ?string $webroot = null): array` — пути фото букетов проверяются по `CATALOG_IMAGE_PATH`, фото разделов (плитка, обложки) — по `CATALOG_SITE_IMAGE`; записи не из строк отвергаются; при заданном `$webroot` каждый файл должен существовать (`$webroot . $path`). Ошибка — `CatalogError` по-русски, с первыми путями. `import-cli.php` передаёт корень сайта (`PION_WEBROOT` или `dirname(__DIR__, 2)`).

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/catalog_import_test.php` (по образцу соседних случаев; `t_small_export()` — из `catalog_export_js_test.php`, здесь прочитать образец напрямую):

```php
function t_import_sample(): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/catalog-export-small.json'), true, 64, JSON_THROW_ON_ERROR);
}

t_case('загрузка: пути фото проверяются', function (): void {
    $e = t_import_sample();
    unset($e['version']);
    $e['products'][0]['images'] = ['/images/site/new-pion.webp'];
    $err = t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'фото букета не из images/catalog — отказ');
    t_true($err !== null && str_contains($err->getMessage(), '100000000001'), 'в сообщении — какой букет');

    $e = t_import_sample();
    unset($e['version']);
    $e['products'][0]['images'] = [['/images/catalog/bukety/a.webp']];
    t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'запись не строкой — отказ');

    $e = t_import_sample();
    unset($e['version']);
    $e['sections'][0]['covers'] = ['/images/catalog/_deleted/x.webp'];
    t_throws(fn () => catalog_import(t_catalog_db(), $e, t_now()), CatalogError::class, 'обложка из корзины — отказ');
});

t_case('загрузка: с корнем сайта — каждое фото должно лежать на месте', function (): void {
    $webroot = t_tmpdir();
    $err = t_throws(fn () => catalog_import(t_catalog_db(), t_import_sample(), t_now(), $webroot), CatalogError::class, 'фото нет — отказ');
    t_true($err !== null && str_contains($err->getMessage(), 'не найдены'), 'объяснение: фото не найдены');
    $sample = t_import_sample();
    foreach ($sample['products'] as $p) {
        foreach ($p['images'] as $path) {
            t_put_files($webroot, [ltrim($path, '/') => 'webp']);
        }
    }
    foreach ($sample['sections'] as $s) {
        foreach (array_merge($s['tileImage'] !== '' ? [$s['tileImage']] : [], $s['covers']) as $path) {
            t_put_files($webroot, [ltrim($path, '/') => 'webp']);
        }
    }
    foreach ($sample['tiles'] as $t) {
        if (isset($t['image'])) {
            t_put_files($webroot, [ltrim($t['image'], '/') => 'webp']);
        }
    }
    t_equal(catalog_import(t_catalog_db(), $sample, t_now(), $webroot)['products'], 5, 'все на месте — загружено');
});
```

In `tests/php/catalog_snapshot_test.php`, change the `catalog_import(...)` call to pass the repo's `public/` as the web root (the snapshot's photos all live there):

```php
    $r = catalog_import($db, $e, t_now(), __DIR__ . '/../../public');
```

Run: `/c/php82/php.exe tests/php/run.php`
Expected: новые случаи падают (неправильные пути загружаются; аргумента `$webroot` нет — `ArgumentCountError` или игнор).

- [ ] **Step 2: Реализация**

In `server-pay/catalog/import.php`:

1. After `require_once __DIR__ . '/export.php';` add:

```php
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/sections.php';
```

(если подключение этих файлов тянет за собой то, что уже подключено, — `require_once` это выдержит; если появится конфликт имён — сообщить, не переименовывать функции.)

2. Change the signature to `function catalog_import(PDO $db, array $export, DateTimeImmutable $now, ?string $webroot = null): array` and the closure `use` list to include `$webroot`. Update the doc comment: `$webroot — корень сайта: если задан, каждое фото из выгрузки должно лежать на месте (загрузка на сервере). Без него проверяются только пути.`

3. Right after the `foreach (['sections', 'tiles', 'products', 'redirects'] as $part)` check, add:

```php
        // Фото: только пути, которые принимает и сама админка, и только строки. Иначе
        // уехавший путь не заметили бы, а ночная уборка отправила бы живые фото в корзину.
        $missing = [];
        $need = static function (string $path) use ($webroot, &$missing): void {
            if ($webroot !== null && !is_file($webroot . $path)) {
                $missing[] = $path;
            }
        };
        foreach ($export['products'] as $p) {
            $images = $p['images'] ?? null;
            if (!is_array($images)) {
                throw new CatalogError("Букет {$p['uid']}: фото — не список.");
            }
            foreach ($images as $path) {
                if (!is_string($path) || !preg_match(CATALOG_IMAGE_PATH, $path)) {
                    throw new CatalogError("Букет {$p['uid']}: неправильный путь к фото " . json_encode($path, CATALOG_JSON) . '.');
                }
                $need($path);
            }
        }
        foreach ($export['sections'] as $s) {
            $paths = array_merge(($s['tileImage'] ?? '') !== '' ? [$s['tileImage']] : [], is_array($s['covers'] ?? null) ? $s['covers'] : [null]);
            foreach ($paths as $path) {
                if (!is_string($path) || !preg_match(CATALOG_SITE_IMAGE, $path)) {
                    throw new CatalogError("Раздел {$s['slug']}: неправильный путь к фото " . json_encode($path, CATALOG_JSON) . '.');
                }
                $need($path);
            }
        }
        foreach ($export['tiles'] as $t) {
            if (($t['type'] ?? '') !== 'section') {
                if (!is_string($t['image'] ?? null) || !preg_match(CATALOG_SITE_IMAGE, $t['image'])) {
                    throw new CatalogError('Плитка ' . json_encode($t['label'] ?? '', CATALOG_JSON) . ': неправильный путь к фото.');
                }
                $need($t['image']);
            }
        }
        if ($missing !== []) {
            throw new CatalogError('Фото не найдены на сервере: ' . count($missing) . ', например ' . implode(', ', array_slice($missing, 0, 3)) . '.');
        }
```

(`json_encode` с `CATALOG_JSON` бросит исключение на невалидном UTF-8 — это допустимо: `catalog_import` и так работает в транзакции, а сообщение будет непонятным только для заведомо битого файла.)

In `server-pay/catalog/import-cli.php` replace `$counts = catalog_import($db, $export, new DateTimeImmutable());` with:

```php
    // pay/catalog → pay → корень сайта: фото из выгрузки должны лежать там.
    $webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
    $counts = catalog_import($db, $export, new DateTimeImmutable(), $webroot);
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0` (включая снимок с корнем `public/` — все фото снимка лежат в `public/images/`).

- [ ] **Step 4: Коммит**

```bash
git add server-pay/catalog/import.php server-pay/catalog/import-cli.php tests/php/catalog_import_test.php tests/php/catalog_snapshot_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(catalog): загрузка каталога проверяет пути и наличие фото

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Карточка возвращает фото из корзины; мусор в `state.json` не роняет страницы

**Files:**
- Modify: `server-pay/admin/lib/pages-products.php` (`admin_product_save`)
- Modify: `server-pay/admin/lib/status.php` (`admin_deployed_catalog`)
- Test: `tests/php/catalog_ready_test.php` (дополнить)

**Interfaces:**
- Consumes: `catalog_photo_untrash(string $webroot, string $rel): bool`, `catalog_photo_trash(string $webroot, string $rel, DateTimeImmutable $now): bool` (`server-pay/catalog/photo-files.php`).
- Produces: сохранение карточки (и «Опубликовать») возвращает из корзины каждое фото, на которое ссылается карточка; `admin_deployed_catalog` отдаёт `changedAt = ''` для значения, которое не строка или не дата `DATE_ATOM`.

- [ ] **Step 1: Проверки (пока падают)**

Append to `tests/php/catalog_ready_test.php`:

```php
t_case('сохранение карточки возвращает фото из корзины', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $path = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-bbbbbbbb.webp';
    t_put_files($ctx['webroot'], [ltrim($path, '/') => 'webp']);
    // Фото загрузили, но карточку не сохраняли больше суток — ночная уборка унесла файл в корзину.
    catalog_photo_trash($ctx['webroot'], $path, t_now());
    t_true(!is_file($ctx['webroot'] . $path), 'файл в корзине');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'save', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => [$path],
    ]);
    t_equal($r['status'], 303, 'сохранено');
    t_true(is_file($ctx['webroot'] . $path), 'фото вернулось на место');
});

t_case('мусор вместо даты выкладки не роняет страницы', function (): void {
    $ctx = t_admin_ctx();
    foreach (['вчера вечером', 12345, ['2026'], ''] as $junk) {
        file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
            'catalogVersion' => 'v1', 'catalogChangedAt' => $junk,
        ]]));
        t_equal(admin_deployed_catalog($ctx['deployHome'])['changedAt'], '', 'значение ' . json_encode($junk, JSON_UNESCAPED_UNICODE) . ' — неизвестно');
    }
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    t_equal(admin_deployed_catalog($ctx['deployHome'])['changedAt'], '2026-10-05T14:05:00+05:00', 'нормальная дата — как есть');
});
```

Run: `/c/php82/php.exe tests/php/run.php`
Expected: падают: фото не вернулось; мусор возвращается как есть (а для числа и массива — предупреждение «Array to string conversion» в выводе).

- [ ] **Step 2: Реализация**

In `server-pay/admin/lib/pages-products.php`, in `admin_product_save`, right after `catalog_update_product($ctx['db'], $ctx['user']['login'], $p['uid'], $version, $fields, $ctx['now']);` add:

```php
        // Ночная уборка могла унести в корзину фото, которое загрузили давно, а сохранили только сейчас.
        foreach ($fields['images'] as $path) {
            catalog_photo_untrash($ctx['webroot'], $path);
        }
```

In `server-pay/admin/lib/status.php` replace the `return [...]` line of `admin_deployed_catalog` with:

```php
    $changed = $current['catalogChangedAt'] ?? '';
    // Мусор вместо даты не должен ронять журнал и карточки: такую отметку считаем неизвестной.
    if (!is_string($changed) || ($changed !== '' && DateTimeImmutable::createFromFormat(DATE_ATOM, $changed) === false)) {
        $changed = '';
    }
    return ['version' => $current['catalogVersion'], 'changedAt' => $changed];
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`, вывод без предупреждений.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/admin/lib/pages-products.php server-pay/admin/lib/status.php tests/php/catalog_ready_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "fix(admin): сохранение карточки возвращает фото из корзины, мусор в state.json не роняет страницы

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Ожидание при занятой записи — на всех страницах с POST

**Files:**
- Modify: `tests/php/admin_auth_test.php` (случай «занята запись: ожидание, а не мгновенный отказ»)
- Modify (только если проверка найдёт): `server-pay/admin/lib/*.php`

**Interfaces:**
- Consumes: `admin_handle`, `admin_request`, страницы админки (2Б).
- Produces: проверка, что каждое действие с записью в базу при занятой записи ждёт `busy_timeout`, а не падает сразу.

- [ ] **Step 1: Расширить проверку**

In `tests/php/admin_auth_test.php`, in the case `'занята запись: ожидание, а не мгновенный отказ'`:

1. Before `$other = new PDO(...)` (пока запись свободна) подготовить данные: черновик, букет в продаже, снятый, удалённый — через `catalog_create_product`, `catalog_publish`, `catalog_hide`, `catalog_delete` на соединении `$a` (готовые функции 2А), запомнив uid и версии (`t_row($a, $uid)['version']`). Для публикации черновика в POST нужен путь фото, проходящий `CATALOG_IMAGE_PATH`.

2. Add to `$calls` (через `admin_handle(admin_request('POST', post: [...] + ['csrf' => $csrf], cookies: $cookies), $ctx, '<имя>', '<функция>')`, как у существующих случаев) по одному вызову на каждое действие с записью:
   - `product`/`admin_page_product`: `action=create` (title, price, sections), `action=save` (черновик), `action=publish` (черновик, с фото), `action=hide` + `confirm=1` (в продаже), `action=unhide` (снятый, с ценой), `action=delete` + `confirm=1`, `action=restore` (удалённый);
   - `sections`/`admin_page_sections`: `action=create` (label), `action=move` (tile id, `dir=up`);
   - `logout`/`admin_page_logout` (удаляет сессию — тоже запись).

   Точные имена полей брать из существующих проверок (`tests/php/admin_card_test.php`, `admin_actions_test.php`, `admin_sections_test.php`). Каждый случай должен, если бы запись не была занята, действительно дойти до записи — иначе проверка ничего не доказывает (например, у `hide` без `confirm=1` записи нет).

3. Assertion stays the same for every case: `$took >= 0.25 && str_contains($error, 'locked')`.

Run: `/c/php82/php.exe tests/php/run.php`
Expected: либо все случаи проходят (ожидание есть везде), либо падают конкретные — это находки.

- [ ] **Step 2: Исправить находки**

Если какой-то случай отказывает сразу (`ждали 0.0…`), найти незакрытое чтение перед записью в соответствующей странице (`$stmt->fetch()` без `closeCursor()` до `catalog_tx`/записи) и закрыть его так же, как в `pages-sections.php` (2Б). Каждую находку — отдельной строкой в отчёте: файл, строка, что было открыто.

Если сам `admin_handle` (продление сессии, `admin_session`) упирается в запись раньше страницы, и поэтому случай проходит, не дойдя до страницы, — убедиться, что `$now` в вызовах тот же, что при входе (сессия не продлевается), и описать в отчёте, как проверено, что вызов доходит до страницы.

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 4: Коммит**

```bash
git add tests/php/admin_auth_test.php server-pay/admin/lib/
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "test(admin): ожидание при занятой записи — для всех действий с записью

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Список букетов — по 60 и лёгкие миниатюры

**Files:**
- Modify: `server-pay/admin/lib/pages-products.php` (`admin_page_products`)
- Modify (если нужно для размера миниатюр): `server-pay/admin/assets/admin.css`
- Test: `tests/php/admin_products_test.php`

**Interfaces:**
- Consumes: `admin_products`, `admin_str` (2Б).
- Produces: `admin_page_products` показывает первые `ADMIN_LIST_PAGE = 60` найденных; при большем числе — ссылка «Показать ещё» (`?shown=120…`, фильтры сохраняются); у миниатюр `width`, `height`, `loading="lazy"`, `decoding="async"`.

- [ ] **Step 1: Проверка (пока падает)**

Append to `tests/php/admin_products_test.php`:

```php
t_case('список букетов — по 60, с «Показать ещё»', function (): void {
    $ctx = t_admin_ctx();
    for ($i = 1; $i <= 65; $i++) {
        catalog_create_product($ctx['db'], 'anna', t_fields(['title' => "Букет «Номер $i»"]), t_now("+$i seconds"));
    }
    $page = t_admin_call($ctx, 'products', 'admin_page_products', query: ['status' => 'draft'])['body'];
    t_equal(substr_count($page, 'product.php?uid='), 60, 'первые 60');
    t_true(str_contains($page, 'Найдено: 65'), 'всего найдено — 65');
    t_true(str_contains($page, 'shown=120') && str_contains($page, 'status=draft') && str_contains($page, 'Показать ещё'), 'ссылка «Показать ещё» с теми же фильтрами');
    $more = t_admin_call($ctx, 'products', 'admin_page_products', query: ['status' => 'draft', 'shown' => '120'])['body'];
    t_equal(substr_count($more, 'product.php?uid='), 65, 'по ссылке — все 65');
    t_true(!str_contains($more, 'Показать ещё'), 'больше показывать нечего');
});
```

(Если в `admin_products_test.php` нет `require_once` нужных страниц — добавить по образцу файла; если ссылка «Добавить букет» тоже содержит `product.php?`, считать по `product.php?uid=`, как выше.)

Run: `/c/php82/php.exe tests/php/run.php`
Expected: FAIL — выводятся все 65, ссылки нет.

- [ ] **Step 2: Реализация**

In `server-pay/admin/lib/pages-products.php`:

1. Before `function admin_page_products` add:

```php
/** Сколько букетов в списке за раз: полный каталог — сотни фото, на мобильном интернете это долго. */
const ADMIN_LIST_PAGE = 60;
```

2. In `admin_page_products`, after the `$items = array_filter(...)` statement add:

```php
    $found = count($items);
    $shown = max(ADMIN_LIST_PAGE, (int)admin_str($req['query'], 'shown'));
    $items = array_slice($items, 0, $shown);
```

3. Replace the thumbnail `<img …>` with:

```php
            ? '<img src="' . h($p['images'][0]) . '" alt="" width="64" height="64" loading="lazy" decoding="async">'
```

(размер — как у `.items img` в `admin.css`; если там другой, поставить его и сказать в отчёте.)

4. Replace `'<p class="hint">Найдено: ' . count($items) . '</p>'` with `'<p class="hint">Найдено: ' . $found . '</p>'`, and right after the list expression (`($list !== '' ? … : '<p>Ничего не нашлось.</p>')`) append:

```php
        . ($found > $shown
            ? '<p class="buttons"><a class="btn-quiet" href="' . ADMIN_BASE . '?' . h(http_build_query(
                ['q' => $q, 'section' => $section, 'status' => $status, 'shown' => $shown + ADMIN_LIST_PAGE], '', '&')
            ) . '">Показать ещё</a></p>'
            : '')
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php`
Expected: `не прошло: 0`.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/admin/lib/pages-products.php server-pay/admin/assets/admin.css tests/php/admin_products_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "feat(admin): список букетов по 60 с «Показать ещё», лёгкие миниатюры

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Фид без повторов

**Files:**
- Modify: `scripts/lib/yml-feed.mjs` (`feedCategories`)
- Modify: `scripts/build-feed.mjs`
- Test: `scripts/lib/yml-feed.test.mjs`, `scripts/build-feed.test.mjs`

**Interfaces:**
- Consumes: `feedCategories({catalogTiles, flowerTiles, sections})`, `buildFeed({root, siteUrl, date})` (2В).
- Produces: `feedCategories` не добавляет раздел второй раз (раздел уже стал дочерней категорией «Цветов» — плитка того же раздела пропускается, и наоборот); `buildFeed` возвращает `{yml, csv, outside}` — `outside` — сколько букетов в продаже не попало в фид (их главного раздела нет среди категорий); CLI пишет это число в лог; один uid — одно предложение.

- [ ] **Step 1: Проверки (пока падают)**

Append to `scripts/lib/yml-feed.test.mjs`:

```js
describe('feedCategories без повторов', () => {
  it('раздел, уже ставший подкатегорией «Цветов», второй раз не добавляется', () => {
    const categories = feedCategories({
      catalogTiles: [
        { label: 'Цветы', href: '/flowers', image: '/x.webp' },
        { label: 'Пионы', href: '/pions', image: '/x.webp' },
        { label: 'Букеты', href: '/bukety', image: '/x.webp' },
      ],
      flowerTiles: [{ label: 'Пионы', href: '/pions', image: '/x.webp' }],
      sections: new Set(['pions', 'bukety']),
    });
    expect(categories.filter((c) => c.section === 'pions')).toHaveLength(1);
    expect(categories.map((c) => c.name)).toEqual(['Цветы', 'Пионы', 'Букеты']);
  });
});
```

(`feedCategories` уже импортирован в этом файле? Если нет — добавить в импорт.)

Append to `scripts/build-feed.test.mjs`, inside `describe('buildFeed на настоящих данных сайта', …)`:

```js
  it('каждый букет — одним предложением; сколько не попало — считается', () => {
    const ids = [...yml.matchAll(/<offer id="(\d+)"/g)].map((m) => m[1]);
    expect(new Set(ids).size).toBe(ids.length);
    const { outside } = buildFeed({ root, siteUrl: 'https://pionperm.ru', date: '2026-10-05 20:00' });
    expect(Number.isInteger(outside)).toBe(true);
  });
```

Run: `npx vitest run scripts/lib/yml-feed.test.mjs scripts/build-feed.test.mjs`
Expected: FAIL — «Пионы» встречаются дважды; `outside` нет.

- [ ] **Step 2: Реализация**

In `scripts/lib/yml-feed.mjs`, in `feedCategories`: add `const seen = new Set();` after `const categories = [];`, and:
- in the flowers branch, filter children also by `!seen.has(slugOf(t.href))` and `seen.add(...)` each child's section when adding it;
- in the `else if (slug !== null && sections.has(slug))` branch, add `&& !seen.has(slug)` to the condition and `seen.add(slug)` when adding.
Add to the function's comment: `Раздел попадает в категории один раз: если салон покажет «Пионы» плиткой каталога, а они уже подкатегория «Цветов», вторая категория дала бы в фиде повтор предложений — 2ГИС и Яндекс такое не принимают.`

In `scripts/build-feed.mjs`, in `buildFeed`, replace the `const products = …` statement with:

```js
  // Те же товары, что видит покупатель: букеты в продаже, каждый один раз —
  // в категории своего главного раздела (букет может стоять в нескольких).
  const covered = new Set(categories.filter((c) => c.section).map((c) => c.section));
  const products = [...covered].flatMap((section) =>
    sectionProducts(catalog, section)
      .filter((product) => product.mainSection === section)
      .map((product) => ({ ...product, section })),
  );
  // Букеты, у главного раздела которых нет категории в фиде (архивные сезонные
  // разделы, «Новинки»), в фид не попадают — считаем их, чтобы это было видно в логе.
  const outside = catalog.products.filter((p) => p.status === 'active' && !covered.has(p.mainSection)).length;
```

and `return { yml: …, csv: …, outside };`.

In the CLI part, destructure `outside` and change the log line to:

```js
  console.log(`[feed] public/feed/products.xml и products.csv: товаров ${(yml.match(/<offer /g) ?? []).length}, не в фиде (нет категории): ${outside}`);
```

- [ ] **Step 3: Убедиться, что фид не изменился**

Run: `node -e "import('./scripts/build-feed.mjs').then(m=>{const r=m.buildFeed({root:process.cwd(),siteUrl:'https://pionperm.ru',date:'X'});console.log((r.yml.match(/<offer /g)||[]).length, r.outside)})"`
Expected: `384` и число букетов в продаже из `new-year-2025`/`valentinesday` (архивные разделы без категории). Записать оба числа в отчёт.

Сравнить с фидом до правки (`git stash` правок или `git show HEAD:scripts/build-feed.mjs` во временный файл, как в 2В) — YML и CSV должны совпасть байт в байт (кроме даты).

Run: `npm test`
Expected: PASS.

- [ ] **Step 4: Коммит**

```bash
git add scripts/lib/yml-feed.mjs scripts/lib/yml-feed.test.mjs scripts/build-feed.mjs scripts/build-feed.test.mjs
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "fix(feed): раздел в категориях один раз, букет — одним предложением; счёт не попавших

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Второй образец выгрузки — «трудные» символы

**Files:**
- Create: `tests/php/fixtures/catalog-export-chars.json`
- Modify: `scripts/lib/catalog-export.test.mjs`
- Modify: `tests/php/catalog_export_js_test.php`

**Interfaces:**
- Consumes: `formatExport`, `exportVersion`, `validateExport` (2В); `catalog_export_version`, `catalog_import`, `catalog_export_data` (2А).
- Produces: образец с табуляцией, U+2028, U+2029, эмодзи, `</script>` и переводом строки; версия `b89275b3a31a1449d1aeec4b4b4e2796d9b85eb94c3c3989b28d1f583183349d` (посчитана при составлении плана на JS и сверена с PHP; загрузка «туда-обратно» проходит).

- [ ] **Step 1: Образец**

Create the fixture with this one-off script (run from the repo root, not committed):

```bash
node --input-type=module -e "
import { readFileSync, writeFileSync } from 'node:fs';
import { formatExport } from './scripts/lib/catalog-export.mjs';
const exp = JSON.parse(readFileSync('tests/php/fixtures/catalog-export-small.json', 'utf8'));
exp.products[0].title = 'Букет «А» 🌷';
exp.products[0].description = 'Состав:\tроза эвкалипт гипсофила — «нежный» 🌸 & </script>';
exp.sections[0].coverSub = 'Строка один\nстрока два\tс табуляцией';
delete exp.version;
writeFileSync('tests/php/fixtures/catalog-export-chars.json', formatExport(exp));
console.log(JSON.parse(formatExport(exp)).version);
"
```

Expected output: `b89275b3a31a1449d1aeec4b4b4e2796d9b85eb94c3c3989b28d1f583183349d`. Если версия другая — остановиться и сообщить (значит, изменился образец 2В или формат).

- [ ] **Step 2: Проверки на обеих сторонах**

Append to `scripts/lib/catalog-export.test.mjs`:

```js
describe('образец с трудными символами', () => {
  const CHARS = path.join(root, 'tests/php/fixtures/catalog-export-chars.json');
  it('версия та же, что считает PHP (tests/php/catalog_export_js_test.php)', () => {
    const exp = JSON.parse(readFileSync(CHARS, 'utf8'));
    expect(exportVersion(exp)).toBe('b89275b3a31a1449d1aeec4b4b4e2796d9b85eb94c3c3989b28d1f583183349d');
    expect(validateExport(exp)).toEqual([]);
    expect(exp.products[0].description).toContain(' ');
    expect(exp.products[0].title).toContain('🌷');
  });
});
```

Append to `tests/php/catalog_export_js_test.php`:

```php
t_case('образец с трудными символами: та же версия и загрузка «туда-обратно»', function (): void {
    $e = json_decode((string)file_get_contents(__DIR__ . '/fixtures/catalog-export-chars.json'), true, 64, JSON_THROW_ON_ERROR);
    t_equal(catalog_export_version($e), 'b89275b3a31a1449d1aeec4b4b4e2796d9b85eb94c3c3989b28d1f583183349d', 'тот же sha256, что в vitest');
    $db = t_catalog_db();
    catalog_import($db, $e, t_now());
    $want = ['sections' => $e['sections'], 'tiles' => $e['tiles'], 'products' => $e['products'], 'redirects' => $e['redirects']];
    t_equal(json_encode(catalog_export_data($db), CATALOG_JSON), json_encode($want, CATALOG_JSON), 'выгрузка из базы — тот же JSON');
});
```

- [ ] **Step 3: Убедиться, что проходят**

Run: `npm test && /c/php82/php.exe tests/php/run.php`
Expected: PASS; `не прошло: 0`.

(Git может переводить строки файла-образца в CRLF в рабочей копии — на JSON это не влияет: символы внутри строк записаны как есть или экранированы.)

- [ ] **Step 4: Коммит**

```bash
git add tests/php/fixtures/catalog-export-chars.json scripts/lib/catalog-export.test.mjs tests/php/catalog_export_js_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "test(catalog): образец выгрузки с трудными символами — одна версия в JS и PHP

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Корзина — понятное сообщение о снятом букете

**Files:**
- Create: `server-pay/cart-lib.php`
- Modify: `server-pay/lib.php` (только сообщение для отсутствующего товара)
- Modify: `server-pay/.htaccess`
- Modify: `src/components/CheckoutForm/CheckoutForm.tsx` (название позиции в запросе)
- Test: `tests/php/cart_lib_test.php`

**Interfaces:**
- Consumes: `price_order(array $cartItems, string $deliveryId, ?DateTimeImmutable $now = null)` (`server-pay/lib.php:117`).
- Produces:
  - `cart_unavailable_message(array $row): string` — «Букета «<название>» сейчас нет в продаже — уберите его из корзины.»; без названия — «Одного из букетов в корзине сейчас нет в продаже — уберите его из корзины.» Название берётся только для текста: обрезается до 120 знаков, управляющие символы убираются; на цену и состав заказа не влияет;
  - `price_order` бросает `InvalidArgumentException(cart_unavailable_message($row))` вместо «Товар не найден: <uid>»;
  - корзина присылает `title` у каждой позиции.

- [ ] **Step 1: Проверка (пока падает)**

Create `tests/php/cart_lib_test.php`:

```php
<?php
/**
 * Сообщение о букете, которого больше нет в продаже: салон снял его, а у
 * покупателя он остался в корзине. Название — только для текста.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/cart-lib.php';

t_equal(
    cart_unavailable_message(['uid' => '120011867771', 'quantity' => 1, 'title' => 'Цветочная коробка #21']),
    'Букета «Цветочная коробка #21» сейчас нет в продаже — уберите его из корзины.',
    'с названием — называем букет',
);
t_equal(
    cart_unavailable_message(['uid' => '120011867771', 'quantity' => 1]),
    'Одного из букетов в корзине сейчас нет в продаже — уберите его из корзины.',
    'без названия — общий текст',
);
t_equal(
    cart_unavailable_message(['title' => ['не строка']]),
    'Одного из букетов в корзине сейчас нет в продаже — уберите его из корзины.',
    'название не строкой — общий текст',
);
$long = cart_unavailable_message(['title' => str_repeat('я', 500) . "\r\nЛишнее"]);
t_true(mb_strlen($long) < 200 && !str_contains($long, "\n"), 'длинное название обрезано, переводы строк убраны');
```

Run: `/c/php82/php.exe tests/php/run.php`
Expected: фатальная ошибка `Failed opening required '…/server-pay/cart-lib.php'`.

- [ ] **Step 2: Реализация**

Create `server-pay/cart-lib.php`:

```php
<?php
/**
 * Тексты корзины, которые не зависят от настроек сервера (config.php), —
 * поэтому отдельно от lib.php: их можно проверить без боевых ключей.
 */

declare(strict_types=1);

/**
 * Букета из корзины нет в каталоге выложенной сборки: салон снял его с
 * продажи (или витринный букет уже продан). Название присылает корзина — оно
 * только для текста: цена и состав заказа берутся из каталога сервера.
 */
function cart_unavailable_message(array $row): string
{
    $title = $row['title'] ?? '';
    $title = is_string($title) ? trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)) : '';
    if ($title === '') {
        return 'Одного из букетов в корзине сейчас нет в продаже — уберите его из корзины.';
    }
    return 'Букета «' . mb_substr($title, 0, 120) . '» сейчас нет в продаже — уберите его из корзины.';
}
```

In `server-pay/lib.php`:
- after the line `require __DIR__ . '/config.php';` add `require_once __DIR__ . '/cart-lib.php';`;
- replace `throw new InvalidArgumentException('Товар не найден: ' . $uid);` with `throw new InvalidArgumentException(cart_unavailable_message($row));`.

**Больше ничего в `lib.php` не трогать.**

In `server-pay/.htaccess`, after the `<Files "catalog-redirect-lib.php"> … </Files>` block add:

```apache
<Files "cart-lib.php">
  Require all denied
</Files>
```

In `src/components/CheckoutForm/CheckoutForm.tsx` replace `items: items.map((i) => ({ uid: i.uid, quantity: i.quantity })),` with:

```tsx
          // Название — только для понятного сообщения, если букет успели снять с продажи.
          items: items.map((i) => ({ uid: i.uid, quantity: i.quantity, title: i.title })),
```

(Если у позиции корзины поле названия называется иначе — посмотреть тип в `src/components/Cart/`, использовать его и сказать в отчёте. `uds-check.php` вызывает `price_order` с тем же `items` — отдельной правки не требует.)

- [ ] **Step 3: Убедиться, что проходят**

Run: `/c/php82/php.exe tests/php/run.php && /c/php82/php.exe -l server-pay/lib.php && /c/php82/php.exe -l server-pay/cart-lib.php && npm test && npx tsc --noEmit`
Expected: `не прошло: 0`; `No syntax errors detected` дважды; vitest PASS; tsc чисто.

- [ ] **Step 4: Коммит**

```bash
git add server-pay/cart-lib.php server-pay/lib.php server-pay/.htaccess src/components/CheckoutForm/CheckoutForm.tsx tests/php/cart_lib_test.php
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "fix(checkout): понятное сообщение, если букета из корзины уже нет в продаже

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Документация — решения и отметки в списках

**Files:**
- Modify: `docs/known-follow-ups.md`
- Modify: `docs/catalog.md`
- Modify: `docs/superpowers/specs/2026-10-05-catalog-admin-design.md` (раздел «Безопасность»)

**Interfaces:**
- Consumes: результаты Tasks 1–9.
- Produces: списки «перед этапом 3» отражают сделанное; решения записаны.

- [ ] **Step 1: `docs/known-follow-ups.md`**

1. В разделе «Админка — этап 2Б» → «Перед этапом 3 (ворота)» отметить `- [x]` и дописать «— сделано в 3А (`docs/superpowers/plans/2026-10-08-catalog-ready.md`)» у пунктов:
   - «Загрузка каталога проверяет фото» (Task 3);
   - «Миниатюры в списке букетов» (Task 6: по 60 и ленивые миниатюры);
   - «`catalogChangedAt` в `state.json`» (Task 4);
   - «Сохранение карточки возвращает фото из корзины» (Task 4);
   - «Защита от «открытого чтения» — общая» (Task 5; перечислить, если были находки);
   - «Решения записать в документацию» (Step 3 ниже);
   - «Черновик можно опубликовать без фото» (Task 1: админка требует фото).
2. Пункты «Уборка фото ждёт выкладки», «Тревоги обслуживания», «Сторож выкладки», «Проверка SQLite 3.26» дописать «— этап 3Б (конвейер)»; «Проверка на настоящем телефоне», «`REMOTE_ADDR` на сервере» — «— этап 3В (переключение)».
3. В разделе «Сайт из выгрузки — этап 2В» отметить `- [x]` с «— сделано в 3А»:
   - «Снятый букет в корзине» (Task 9);
   - «19 коробок без цены» — дописать: «Решение владельца 2026-10-08: оставить снятыми»;
   - «Админка: «Вернуть в продажу» без цены нужно запретить» (Task 1);
   - «Двойные товары в фиде» (Task 7);
   - «Новый раздел без фото плитки» (Tasks 1–2: создаётся скрытым, сайт не выводит плитку без фото);
   - «Тексты на сайте для эпохи админки» (Task 2);
   - «Изменения формата выгрузки» — образец с трудными символами (Task 8); правило «поле — в PHP и JS одновременно» остаётся.
4. Остальным пунктам 2В дописать, к какому этапу они относятся: «`deploy.yml`», «Тесты, привязанные к снимку», «Защита от массовой потери», «`apply-catalog-export.mjs` до включения», «`compare-builds.mjs`» — «— этап 3Б»; «Раздел «Новинки»», «Загрузка каталога в базу», «После переключения убрать …» — «— этап 3В».

- [ ] **Step 2: `docs/catalog.md`**

1. Абзац про 19 коробок: дописать «Решение владельца (2026-10-08): коробки остаются снятыми. Вернуть в продажу без цены админка не даст — сначала цена в карточке.» и убрать оговорку «пока сама админка не отказывает …» (теперь отказывает).
2. В разделе «Админка» дописать два предложения: «Опубликовать букет без фото нельзя. Новый раздел создаётся скрытым: добавьте фото плитки и отметьте «Показывать в каталоге».»

- [ ] **Step 3: Спецификация, раздел «Безопасность»**

После строки «Каждая форма с CSRF-токеном. Изменения — только POST.» добавить:

```markdown
  Исключение (принято 2026-10-08): форма входа без токена — токен живёт в
  сессии, а её до входа нет. Смягчено: кука `SameSite=Strict`, регистрации
  нет, вход с чужого сайта (`Sec-Fetch-Site: cross-site`) отклоняется.
- Админка на одном адресе-источнике с WordPress (`/blog`) — принято
  (2026-10-08): на сервере WordPress работает от того же пользователя ОС, а
  для кражи сессии через браузер нужна дыра в плагине WP. Пересмотреть, если
  появятся плагины.
```

- [ ] **Step 4: Все проверки и отправка ветки**

Run: `npm test && /c/php82/php.exe tests/php/run.php && npm run build`
Expected: всё проходит; `git status` чистый, кроме этих документов.

```bash
git add docs/known-follow-ups.md docs/catalog.md docs/superpowers/specs/2026-10-05-catalog-admin-design.md
GIT_AUTHOR_NAME="Максим" GIT_AUTHOR_EMAIL="acebmaks@gmail.com" GIT_COMMITTER_NAME="Максим" GIT_COMMITTER_EMAIL="acebmaks@gmail.com" git commit -m "docs: этап 3А — решения владельца, исключения безопасности, отметки в списках

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin catalog-ready
```
