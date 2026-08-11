# Клон pionperm.ru на Next.js — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild pionperm.ru (a Tilda-based flower shop site) as a standalone Next.js codebase, visually identical, with real content/photos pulled from the live site, working cart/forms on the frontend only (no payment/CRM integration).

**Architecture:** Next.js 14 (App Router) + TypeScript, plain CSS Modules for styling. Content (catalog products, static page text, images) is scraped once from the live site into local JSON + `public/images`, then the app renders purely from those local files — no runtime dependency on pionperm.ru or Tilda.

**Tech Stack:** Next.js 14, React 18, TypeScript, CSS Modules, Playwright (scraper only, dev dependency), Vitest (unit tests for pure logic: cart reducer, form validation, product sorting).

## Global Constraints

- Design tokens (from live site CSS): accent `#b08d7a`, text/black `#000000`, white `#ffffff`, light bg `#f4f4f4` / `#f3f3f3`, dark text `#2a2928`, border/gray `#d6d6d6`. Font: `Montserrat` (Google Fonts, `latin,cyrillic` subsets, weights 100–900).
- Company info (footer/header, verbatim from live site): phone `+7 342 258 45 45`, address `г. Пермь, ул. Газеты Звезда, д. 27`, VK `https://vk.com/pionpermcveti`, Telegram `https://t.me/PionPerm`, WhatsApp `https://wa.me/79082413741`. Legal line: `© 2024 ИП Дылдина Раиса Закирзяновна Салон цветов и подарков "Пион" Пермь, ул.Газеты Звезда, 27 ОГРНИП 323595800106532 ИНН 590403045968`. Hours: `пн-пт 10:00-22:00, сб-вс 10:00-22:00`.
- Nav items (exact labels): `КАТАЛОГ` (/catalog), `АКЦИИ` (/stock), `ДОСТАВКА` (/delivery-and-payment), `О НАС` (/about), `КОНТАКТЫ` (/contacts), `UDS` (/uds).
- No real order/payment submission anywhere in the app — every form ends in a local "Спасибо, мы с вами свяжемся" confirmation state. Never call an external API to place an order or take payment.
- Do not fetch from `pionperm.ru` or `tildacdn.com` at app runtime — only the one-time scraper script does that. The Next.js app itself must run fully offline against local `data/` and `public/images/`.
- All prices are integers in rubles (no kopecks in display, e.g. `4 830 ₽`), matching source data's `price` field (strip decimals).

---

## File Structure

```
Pion/
  package.json, tsconfig.json, next.config.mjs, vitest.config.ts
  scripts/
    scrape.mjs                  # one-time Playwright scraper -> data/ + public/images
    lib/
      downloadImage.mjs         # shared image-download helper used by scrape.mjs
  data/
    site.json                   # nav, contacts, socials, footer links, hero slides, features, "Новинки"
    catalog/<slug>.json         # one file per catalog category (array of Product)
    pages/<slug>.json           # one file per static/promo page (array of ContentBlock)
  public/images/
    catalog/<slug>/<file>       # product photos
    pages/<slug>/<file>         # static-page photos
    site/<file>                 # logo, hero banners
  src/
    lib/
      types.ts                  # Product, ContentBlock, SiteData types
      content.ts                # typed loaders: getCatalog(slug), getPage(slug), getSite()
    components/
      Header/Header.tsx, Header.module.css
      Footer/Footer.tsx, Footer.module.css
      HeroSlider/HeroSlider.tsx, HeroSlider.module.css
      ProductCard/ProductCard.tsx, ProductCard.module.css
      CategoryGrid/CategoryGrid.tsx, CategoryGrid.module.css, sortProducts.ts, sortProducts.test.ts
      ContentBlocks/ContentBlocks.tsx, ContentBlocks.module.css
      Cart/CartContext.tsx, cartReducer.ts, cartReducer.test.ts, CartDrawer.tsx, CartDrawer.module.css
      CheckoutForm/CheckoutForm.tsx, validateCheckout.ts, validateCheckout.test.ts, CheckoutForm.module.css
      BouquetBuilder/BouquetBuilderPopup.tsx, validateBouquetForm.ts, validateBouquetForm.test.ts, BouquetBuilder.module.css
    app/
      layout.tsx, globals.css
      page.tsx                  # home
      catalog/page.tsx          # catalog overview (links to all categories)
      [slug]/page.tsx           # dynamic: every catalog category AND every static/promo page
      [slug]/not-found.tsx
```

**Category slugs** (→ `data/catalog/<slug>.json`): `bukety`, `korziny`, `korobki`, `flowers`, `wedding`, `balloons`, `chocolate`, `indoorflowers`, `luchshee`, `flame`, `pions`, `roses`, `mixflower`.

**Static/promo page slugs** (→ `data/pages/<slug>.json`): `about`, `delivery-and-payment`, `flower-delivery`, `contacts`, `uds`, `stock`, `policy`, `valentinesday`, `new-year-2025`, `doza_endorfina`.

Both sets are declared once in `src/lib/content.ts` as `CATEGORY_SLUGS` and `PAGE_SLUGS` arrays — every other task (routing, scraper) imports from there instead of re-declaring the list, so the site's page inventory has one source of truth.

---

### Task 1: Project scaffold, design tokens, fonts

**Files:**
- Create: `package.json`, `tsconfig.json`, `next.config.mjs`, `vitest.config.ts`, `.gitignore`
- Create: `src/app/layout.tsx`, `src/app/globals.css`

**Interfaces:**
- Produces: CSS custom properties on `:root` (`--color-accent`, `--color-black`, `--color-white`, `--color-bg-light`, `--color-text-dark`, `--color-border`, `--font-sans`) that every later component task consumes.

- [ ] **Step 1: Init the Next.js + TypeScript project**

```bash
npx create-next-app@14 . --typescript --eslint --app --src-dir --import-alias "@/*" --no-tailwind --use-npm
```

When prompted about a non-empty directory (the `docs/` folder already exists), confirm to continue.

- [ ] **Step 2: Add dev dependencies for scraping and testing**

```bash
npm install --save-dev playwright vitest @vitejs/plugin-react jsdom
npx playwright install chromium
```

- [ ] **Step 3: Add `vitest.config.ts`**

```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
```

- [ ] **Step 4: Add an `npm test` script**

Edit `package.json`, add to `"scripts"`:

```json
"test": "vitest run"
```

- [ ] **Step 5: Write design tokens into `src/app/globals.css`**

```css
:root {
  --color-accent: #b08d7a;
  --color-black: #000000;
  --color-white: #ffffff;
  --color-bg-light: #f4f4f4;
  --color-bg-light-2: #f3f3f3;
  --color-text-dark: #2a2928;
  --color-border: #d6d6d6;
  --font-sans: 'Montserrat', Arial, sans-serif;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  font-family: var(--font-sans);
  color: var(--color-text-dark);
  background: var(--color-white);
}

a {
  color: inherit;
  text-decoration: none;
}
```

- [ ] **Step 6: Load Montserrat via `next/font/google` in `src/app/layout.tsx`**

```tsx
import type { Metadata } from 'next';
import { Montserrat } from 'next/font/google';
import './globals.css';

const montserrat = Montserrat({
  subsets: ['latin', 'cyrillic'],
  weight: ['300', '400', '500', '600', '700', '800'],
  variable: '--font-montserrat',
});

export const metadata: Metadata = {
  title: 'Салон цветов и подарков «Пион»',
  description: 'Букеты и подарки с доставкой по Перми',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ru" className={montserrat.variable}>
      <body>{children}</body>
    </html>
  );
}
```

- [ ] **Step 7: Verify the app boots**

Run: `npm run dev` (start it, confirm no errors in the terminal, then stop it with Ctrl+C — do not leave it running for this step)
Expected: server starts on `http://localhost:3000` with no compile errors (a blank/default page is fine at this point).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: scaffold Next.js project with design tokens and Montserrat font"
```

---

### Task 2: Shared types + hand-authored `data/site.json`

This is small, structured content already gathered directly from the live site's header/footer/nav — hand-author it rather than scraping, since scraping a dozen fields isn't worth the script complexity.

**Files:**
- Create: `src/lib/types.ts`
- Create: `data/site.json`
- Create: `src/lib/content.ts`

**Interfaces:**
- Produces: `Product`, `ContentBlock`, `SiteData` types; `CATEGORY_SLUGS`, `PAGE_SLUGS` arrays; `getSite()`, `getCatalog(slug)`, `getPage(slug)` functions — every component/page task after this one imports from `@/lib/content` and `@/lib/types`.

- [ ] **Step 1: Define shared types in `src/lib/types.ts`**

```typescript
export interface Product {
  uid: string;
  title: string;
  description: string;
  price: number;
  images: string[];
  slug: string;
}

export type ContentBlock =
  | { type: 'heading'; text: string }
  | { type: 'paragraph'; text: string }
  | { type: 'image'; src: string; alt: string }
  | { type: 'cta'; text: string; href: string };

export interface HeroSlide {
  title: string;
  subtitle: string;
  buttonText: string;
  buttonHref: string;
  image: string;
}

export interface FeaturedProduct {
  title: string;
  subtitle: string;
  price: number;
  image: string;
}

export interface Feature {
  title: string;
  description: string;
}

export interface SiteData {
  nav: { label: string; href: string }[];
  phone: string;
  address: string;
  social: { label: string; href: string }[];
  footer: {
    columns: { title: string; links: { label: string; href: string }[] }[];
    legal: string;
    hours: string;
  };
  heroSlides: HeroSlide[];
  newProducts: FeaturedProduct[];
  features: Feature[];
}
```

- [ ] **Step 2: Author `data/site.json`**

```json
{
  "nav": [
    { "label": "КАТАЛОГ", "href": "/catalog" },
    { "label": "АКЦИИ", "href": "/stock" },
    { "label": "ДОСТАВКА", "href": "/delivery-and-payment" },
    { "label": "О НАС", "href": "/about" },
    { "label": "КОНТАКТЫ", "href": "/contacts" },
    { "label": "UDS", "href": "/uds" }
  ],
  "phone": "+7 342 258 45 45",
  "address": "г. Пермь, ул. Газеты Звезда, д. 27",
  "social": [
    { "label": "VK", "href": "https://vk.com/pionpermcveti" },
    { "label": "Telegram", "href": "https://t.me/PionPerm" },
    { "label": "WhatsApp", "href": "https://wa.me/79082413741" }
  ],
  "footer": {
    "columns": [
      {
        "title": "БУКЕТЫ",
        "links": [
          { "label": "Авторские букеты", "href": "/bukety" },
          { "label": "Корзины цветов", "href": "/korziny" },
          { "label": "Цветы", "href": "/flowers" },
          { "label": "Коробки с цветами", "href": "/korobki" }
        ]
      },
      {
        "title": "ДЕКОР",
        "links": [
          { "label": "Лучшее для дома", "href": "/luchshee" },
          { "label": "Воздушные шары", "href": "/balloons" }
        ]
      },
      {
        "title": "ИНФОРМАЦИЯ",
        "links": [
          { "label": "О нас", "href": "/about" },
          { "label": "Доставка и оплата", "href": "/delivery-and-payment" },
          { "label": "Контакты", "href": "/contacts" },
          { "label": "Политика конфиденциальности", "href": "/policy" }
        ]
      }
    ],
    "legal": "© 2024 ИП Дылдина Раиса Закирзяновна. Салон цветов и подарков «Пион». Пермь, ул. Газеты Звезда, 27. ОГРНИП 323595800106532. ИНН 590403045968.",
    "hours": "Режим работы: пн-пт 10:00-22:00, сб-вс 10:00-22:00"
  },
  "heroSlides": [],
  "newProducts": [],
  "features": [
    { "title": "Всегда свежие цветы в нашем салоне", "description": "Мы используем лучшие свежесрезанные цветы" },
    { "title": "Фото и видео букета перед доставкой", "description": "Только приятные сюрпризы для вас и ваших близких" },
    { "title": "Экспресс-доставка по Перми", "description": "Бережно собираем букет и доставляем прямо к двери" },
    { "title": "Заказ из любой точки мира", "description": "Мы подключили на сайт оплату банковской картой" }
  ]
}
```

`heroSlides` and `newProducts` are left empty here — Task 4's scraper fills them in (they need real photos, which Task 1–3 don't have yet).

- [ ] **Step 3: Write `src/lib/content.ts`**

```typescript
import type { Product, ContentBlock, SiteData } from './types';
import siteJson from '../../data/site.json';

export const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'flowers', 'wedding', 'balloons',
  'chocolate', 'indoorflowers', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
] as const;

export const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
] as const;

export type CategorySlug = (typeof CATEGORY_SLUGS)[number];
export type PageSlug = (typeof PAGE_SLUGS)[number];

export function getSite(): SiteData {
  return siteJson as SiteData;
}

export async function getCatalog(slug: string): Promise<Product[] | null> {
  if (!(CATEGORY_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/catalog/${slug}.json`);
  return mod.default as Product[];
}

export async function getPage(slug: string): Promise<ContentBlock[] | null> {
  if (!(PAGE_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/pages/${slug}.json`);
  return mod.default as ContentBlock[];
}
```

- [ ] **Step 4: Create empty placeholder JSON so the dynamic imports don't fail before scraping runs**

```bash
mkdir -p data/catalog data/pages
for slug in bukety korziny korobki flowers wedding balloons chocolate indoorflowers luchshee flame pions roses mixflower; do
  echo "[]" > "data/catalog/$slug.json"
done
for slug in about delivery-and-payment flower-delivery contacts uds stock policy valentinesday new-year-2025 doza_endorfina; do
  echo "[]" > "data/pages/$slug.json"
done
```

- [ ] **Step 5: Verify TypeScript compiles**

Run: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add shared content types, site.json, and content loaders"
```

---

### Task 3: Scraper core — image downloader + one pilot category (`bukety`)

Product data lives behind Tilda's store widget, which lazy-loads via a `Load more` button rather than being present in the initial HTML — so the scraper drives a real headless browser (Playwright) and clicks `Load more` until every product for a category is on the page, then reads the rendered DOM.

**Files:**
- Create: `scripts/lib/downloadImage.mjs`
- Create: `scripts/scrape.mjs` (pilot: `bukety` only in this task; Task 4 extends it to every slug)

**Interfaces:**
- Produces: `downloadImage(url, destPath)` (async, skips re-download if `destPath` already exists), `scrapeCategory(page, slug, categoryUrl)` (returns `Product[]`, also downloads each product's images to `public/images/catalog/<slug>/`).

- [ ] **Step 1: Write the image downloader**

```javascript
// scripts/lib/downloadImage.mjs
import { createWriteStream, existsSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { pipeline } from 'node:stream/promises';
import path from 'node:path';

export async function downloadImage(url, destPath) {
  if (existsSync(destPath)) return destPath;
  await mkdir(path.dirname(destPath), { recursive: true });
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Failed to download ${url}: ${res.status}`);
  await pipeline(res.body, createWriteStream(destPath));
  return destPath;
}
```

- [ ] **Step 2: Write the pilot scraper for one category**

```javascript
// scripts/scrape.mjs
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { downloadImage } from './lib/downloadImage.mjs';

const SITE = 'https://pionperm.ru';
const ROOT = path.resolve(import.meta.dirname, '..');

async function scrapeCategory(page, slug) {
  await page.goto(`${SITE}/${slug}`, { waitUntil: 'networkidle' });

  // Click "Load more" until it's gone (Tilda store lazy-loads products).
  while (true) {
    const loadMore = page.locator('.js-store-load-more-btn').first();
    if (!(await loadMore.isVisible().catch(() => false))) break;
    await loadMore.click();
    await page.waitForTimeout(600);
  }

  const cards = await page.locator('.t-store__card').all();
  const products = [];

  for (const card of cards) {
    const title = (await card.locator('.t-store__card__title').first().innerText().catch(() => '')).trim();
    if (!title) continue;
    const description = (await card.locator('.t-store__card__descr').first().innerText().catch(() => '')).trim();
    const priceText = (await card.locator('.t-store__card__price-value').first().innerText().catch(() => '0'));
    const price = Math.round(parseFloat(priceText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0);
    const imgSrc = await card.locator('img').first().getAttribute('src').catch(() => null);

    const uid = `${slug}-${products.length + 1}`;
    const productSlug = title
      .toLowerCase()
      .replace(/[«»"]/g, '')
      .replace(/[^a-zа-я0-9]+/gi, '-')
      .replace(/^-+|-+$/g, '');

    const images = [];
    if (imgSrc) {
      const ext = path.extname(new URL(imgSrc, SITE).pathname) || '.jpg';
      const fileName = `${productSlug}${ext}`;
      const dest = path.join(ROOT, 'public', 'images', 'catalog', slug, fileName);
      await downloadImage(new URL(imgSrc, SITE).href, dest);
      images.push(`/images/catalog/${slug}/${fileName}`);
    }

    products.push({ uid, title, description, price, images, slug: productSlug });
  }

  return products;
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  const products = await scrapeCategory(page, 'bukety');
  await mkdir(path.join(ROOT, 'data', 'catalog'), { recursive: true });
  await writeFile(
    path.join(ROOT, 'data', 'catalog', 'bukety.json'),
    JSON.stringify(products, null, 2),
    'utf-8'
  );
  console.log(`bukety: ${products.length} products`);

  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
```

- [ ] **Step 3: Run the pilot scraper**

Run: `node scripts/scrape.mjs`
Expected: prints `bukety: N products` with `N` close to the ~100 products visible on the live `/bukety` page; `data/catalog/bukety.json` is populated; `public/images/catalog/bukety/` contains one image per product.

If `.t-store__card` / `.t-store__card__title` selectors don't match (Tilda markup can vary by block version), open `https://pionperm.ru/bukety` in a normal browser, inspect one product card's DOM, and adjust the selectors in Step 2 to match — then re-run.

- [ ] **Step 4: Spot-check the output**

Open `data/catalog/bukety.json` and confirm: titles are non-empty and match what's visible on the live page, prices are plausible integers (thousands of rubles), and a handful of the downloaded files in `public/images/catalog/bukety/` open as valid images.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add scraper core (image downloader + bukety pilot category)"
```

---

### Task 4: Extend scraper to every category, static page, and the homepage; run it fully

**Files:**
- Modify: `scripts/scrape.mjs`

**Interfaces:**
- Consumes: `downloadImage`, `scrapeCategory` from Task 3.
- Produces: populated `data/catalog/*.json` (13 files), `data/pages/*.json` (10 files), and `heroSlides`/`newProducts` filled into `data/site.json`.

- [ ] **Step 1: Add a generic static-page scraper**

```javascript
// add to scripts/scrape.mjs

async function scrapePage(page, slug) {
  await page.goto(`${SITE}/${slug}`, { waitUntil: 'networkidle' });

  const blocks = await page.evaluate(() => {
    const out = [];
    const nodes = document.querySelectorAll(
      '#allrecords h1, #allrecords h2, #allrecords h3, #allrecords p, #allrecords img'
    );
    nodes.forEach((el) => {
      const text = el.textContent?.trim();
      if (el.tagName === 'IMG') {
        const src = el.getAttribute('src');
        if (src) out.push({ type: 'image', src, alt: el.getAttribute('alt') || '' });
      } else if (/^H[1-3]$/.test(el.tagName) && text) {
        out.push({ type: 'heading', text });
      } else if (el.tagName === 'P' && text) {
        out.push({ type: 'paragraph', text });
      }
    });
    return out;
  });

  const result = [];
  for (const [i, block] of blocks.entries()) {
    if (block.type === 'image') {
      const abs = new URL(block.src, SITE).href;
      const ext = path.extname(new URL(abs).pathname) || '.jpg';
      const fileName = `img-${i}${ext}`;
      const dest = path.join(ROOT, 'public', 'images', 'pages', slug, fileName);
      await downloadImage(abs, dest);
      result.push({ type: 'image', src: `/images/pages/${slug}/${fileName}`, alt: block.alt });
    } else {
      result.push(block);
    }
  }
  return result;
}
```

- [ ] **Step 2: Add a homepage scraper for hero slides and "Новинки"**

```javascript
// add to scripts/scrape.mjs

async function scrapeHome(page) {
  await page.goto(`${SITE}/`, { waitUntil: 'networkidle' });

  const heroSlides = await page.evaluate(() => {
    const slides = [];
    document.querySelectorAll('.t-slds__wrapper .t-slds__item, .t954__slide').forEach((slide) => {
      const title = slide.querySelector('.t954__title, [class*="title"]')?.textContent?.trim();
      const subtitle = slide.querySelector('.t954__descr, [class*="descr"]')?.textContent?.trim();
      const button = slide.querySelector('a.t-btn, a[class*="btn"]');
      const img = slide.querySelector('img')?.getAttribute('src');
      if (title && img) {
        slides.push({
          title,
          subtitle: subtitle || '',
          buttonText: button?.textContent?.trim() || 'Подробнее',
          buttonHref: button?.getAttribute('href') || '/catalog',
          image: img,
        });
      }
    });
    return slides;
  });

  for (const slide of heroSlides) {
    const abs = new URL(slide.image, SITE).href;
    const ext = path.extname(new URL(abs).pathname) || '.jpg';
    const fileName = `hero-${heroSlides.indexOf(slide)}${ext}`;
    const dest = path.join(ROOT, 'public', 'images', 'site', fileName);
    await downloadImage(abs, dest);
    slide.image = `/images/site/${fileName}`;
  }

  return { heroSlides };
}
```

If the CSS selectors above (`.t954__slide` etc.) don't match once you inspect the live homepage DOM, adjust them the same way as in Task 3 Step 3 — Tilda block class names are consistent per block *type* (e.g. `T954`), which you can read off the `<!-- T954 -->` HTML comments in the page source.

- [ ] **Step 3: Wire everything together in `main()`**

```javascript
// replace main() in scripts/scrape.mjs

const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'flowers', 'wedding', 'balloons',
  'chocolate', 'indoorflowers', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
];

const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
];

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  await mkdir(path.join(ROOT, 'data', 'catalog'), { recursive: true });
  for (const slug of CATEGORY_SLUGS) {
    const products = await scrapeCategory(page, slug);
    await writeFile(
      path.join(ROOT, 'data', 'catalog', `${slug}.json`),
      JSON.stringify(products, null, 2),
      'utf-8'
    );
    console.log(`${slug}: ${products.length} products`);
  }

  await mkdir(path.join(ROOT, 'data', 'pages'), { recursive: true });
  for (const slug of PAGE_SLUGS) {
    const blocks = await scrapePage(page, slug);
    await writeFile(
      path.join(ROOT, 'data', 'pages', `${slug}.json`),
      JSON.stringify(blocks, null, 2),
      'utf-8'
    );
    console.log(`${slug}: ${blocks.length} blocks`);
  }

  const { heroSlides } = await scrapeHome(page);
  const siteJsonPath = path.join(ROOT, 'data', 'site.json');
  const site = JSON.parse(await (await import('node:fs/promises')).readFile(siteJsonPath, 'utf-8'));
  site.heroSlides = heroSlides;
  site.newProducts = (JSON.parse(
    await (await import('node:fs/promises')).readFile(
      path.join(ROOT, 'data', 'catalog', 'bukety.json'), 'utf-8'
    )
  )).slice(0, 3).map((p) => ({
    title: p.title, subtitle: p.description, price: p.price, image: p.images[0] || '',
  }));
  await writeFile(siteJsonPath, JSON.stringify(site, null, 2), 'utf-8');

  await browser.close();
}
```

- [ ] **Step 4: Run the full scrape**

Run: `node scripts/scrape.mjs`
Expected: console prints a product/block count for every one of the 13 categories and 10 pages with no errors; `data/site.json` now has non-empty `heroSlides` and `newProducts`; `public/images/` contains subfolders for every category and page slug with real photos in them.

This will take several minutes (13 categories × clicking "Load more" repeatedly + downloading every image) — that's expected, not a hang.

- [ ] **Step 5: Spot-check across a few categories and pages**

Open 3–4 of the generated `data/catalog/*.json` and `data/pages/*.json` files and confirm content is non-empty and plausible (compare a couple of product titles/prices against what's visible on the live equivalent page in a browser).

- [ ] **Step 6: Commit the scraper extension and scraped data**

```bash
git add -A
git commit -m "feat: extend scraper to full catalog, static pages, and homepage; run full scrape"
```

---

### Task 5: Header and Footer components

**Files:**
- Create: `src/components/Header/Header.tsx`, `src/components/Header/Header.module.css`
- Create: `src/components/Footer/Footer.tsx`, `src/components/Footer/Footer.module.css`

**Interfaces:**
- Consumes: `getSite()` from `@/lib/content`, `SiteData` type from `@/lib/types`.
- Produces: `<Header />`, `<Footer />` — used by `src/app/layout.tsx` in Task 9.

- [ ] **Step 1: Write `Header.tsx`**

```tsx
import Link from 'next/link';
import { getSite } from '@/lib/content';
import styles from './Header.module.css';

export function Header() {
  const site = getSite();
  return (
    <header className={styles.header}>
      <div className={styles.top}>
        <Link href="/" className={styles.logo}>ПИОН</Link>
        <nav className={styles.nav}>
          <ul className={styles.navList}>
            {site.nav.map((item) => (
              <li key={item.href}>
                <Link href={item.href}>{item.label}</Link>
              </li>
            ))}
          </ul>
        </nav>
        <div className={styles.contacts}>
          <a href={`tel:${site.phone.replace(/[^+\d]/g, '')}`} className={styles.phone}>
            {site.phone}
          </a>
          <span className={styles.address}>{site.address}</span>
        </div>
        <ul className={styles.social}>
          {site.social.map((s) => (
            <li key={s.href}>
              <a href={s.href} target="_blank" rel="noreferrer">{s.label}</a>
            </li>
          ))}
        </ul>
        <Link href="/catalog" className={styles.orderBtn}>СДЕЛАТЬ ЗАКАЗ</Link>
      </div>
    </header>
  );
}
```

- [ ] **Step 2: Write `Header.module.css`**

```css
.header {
  border-bottom: 1px solid var(--color-border);
  background: var(--color-white);
}

.top {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 16px 40px;
  flex-wrap: wrap;
}

.logo {
  font-weight: 800;
  font-size: 24px;
  letter-spacing: 2px;
}

.navList {
  display: flex;
  gap: 20px;
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.contacts {
  display: flex;
  flex-direction: column;
  font-size: 13px;
  margin-left: auto;
}

.phone {
  font-weight: 700;
}

.social {
  display: flex;
  gap: 12px;
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 12px;
}

.orderBtn {
  background: var(--color-black);
  color: var(--color-white);
  padding: 12px 24px;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
}
```

- [ ] **Step 3: Write `Footer.tsx`**

```tsx
import { getSite } from '@/lib/content';
import styles from './Footer.module.css';

export function Footer() {
  const site = getSite();
  return (
    <footer className={styles.footer}>
      <div className={styles.vkBlock}>
        <p>ПОДПИСЫВАЙТЕСЬ НА НАШУ ГРУППУ VK</p>
        <a href={site.social.find((s) => s.label === 'VK')?.href} target="_blank" rel="noreferrer">
          ПЕРЕЙТИ В ГРУППУ VK
        </a>
      </div>
      <div className={styles.columns}>
        {site.footer.columns.map((col) => (
          <div key={col.title} className={styles.column}>
            <h4>{col.title}</h4>
            <ul>
              {col.links.map((l) => (
                <li key={l.href}><a href={l.href}>{l.label}</a></li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className={styles.legal}>
        <p>{site.footer.legal}</p>
        <p>{site.footer.hours}</p>
      </div>
    </footer>
  );
}
```

- [ ] **Step 4: Write `Footer.module.css`**

```css
.footer {
  background: var(--color-text-dark);
  color: var(--color-white);
  padding: 48px 40px;
}

.vkBlock {
  text-align: center;
  margin-bottom: 32px;
}

.vkBlock a {
  display: inline-block;
  margin-top: 12px;
  padding: 10px 20px;
  border: 1px solid var(--color-white);
}

.columns {
  display: flex;
  gap: 48px;
  flex-wrap: wrap;
}

.column h4 {
  font-size: 13px;
  letter-spacing: 1px;
  margin-bottom: 12px;
}

.column ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 13px;
  opacity: 0.85;
}

.legal {
  margin-top: 32px;
  font-size: 11px;
  opacity: 0.6;
  line-height: 1.6;
}
```

- [ ] **Step 5: Verify it compiles**

Run: `npx tsc --noEmit`
Expected: no errors (components aren't wired into a page yet, but must type-check standalone).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add Header and Footer components"
```

---

### Task 6: HeroSlider component

**Files:**
- Create: `src/components/HeroSlider/HeroSlider.tsx`, `src/components/HeroSlider/HeroSlider.module.css`

**Interfaces:**
- Consumes: `HeroSlide[]` (from `@/lib/types`).
- Produces: `<HeroSlider slides={HeroSlide[]} />` — used by home page in Task 9.

- [ ] **Step 1: Write `HeroSlider.tsx`**

```tsx
'use client';

import { useState, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import type { HeroSlide } from '@/lib/types';
import styles from './HeroSlider.module.css';

export function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const [index, setIndex] = useState(0);

  const next = useCallback(() => {
    setIndex((i) => (i + 1) % slides.length);
  }, [slides.length]);

  useEffect(() => {
    if (slides.length <= 1) return;
    const timer = setInterval(next, 5000);
    return () => clearInterval(timer);
  }, [next, slides.length]);

  if (slides.length === 0) return null;
  const slide = slides[index];

  return (
    <div className={styles.slider}>
      <div className={styles.imageWrap}>
        <Image src={slide.image} alt={slide.title} fill className={styles.image} priority />
      </div>
      <div className={styles.content}>
        <h2>{slide.title}</h2>
        <p>{slide.subtitle}</p>
        <Link href={slide.buttonHref} className={styles.button}>{slide.buttonText}</Link>
      </div>
      <div className={styles.dots}>
        {slides.map((s, i) => (
          <button
            key={s.title + i}
            aria-label={`Перейти к слайду ${i + 1}`}
            className={i === index ? styles.dotActive : styles.dot}
            onClick={() => setIndex(i)}
          />
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Write `HeroSlider.module.css`**

```css
.slider {
  position: relative;
  height: 520px;
  overflow: hidden;
  background: var(--color-bg-light);
}

.imageWrap {
  position: absolute;
  inset: 0;
}

.image {
  object-fit: cover;
}

.content {
  position: relative;
  z-index: 1;
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding-left: 64px;
  max-width: 480px;
  color: var(--color-white);
  text-shadow: 0 1px 6px rgba(0, 0, 0, 0.4);
}

.content h2 {
  font-size: 36px;
  margin: 0 0 12px;
}

.button {
  margin-top: 20px;
  display: inline-block;
  background: var(--color-accent);
  color: var(--color-white);
  padding: 14px 28px;
  width: fit-content;
  font-weight: 600;
}

.dots {
  position: absolute;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 8px;
  z-index: 1;
}

.dot, .dotActive {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.5);
  cursor: pointer;
  padding: 0;
}

.dotActive {
  background: var(--color-white);
}
```

- [ ] **Step 3: Verify it compiles**

Run: `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add HeroSlider component"
```

---

### Task 7: Cart — reducer (unit tested) + context + drawer UI

**Files:**
- Create: `src/components/Cart/cartReducer.ts`
- Test: `src/components/Cart/cartReducer.test.ts`
- Create: `src/components/Cart/CartContext.tsx`
- Create: `src/components/Cart/CartDrawer.tsx`, `src/components/Cart/CartDrawer.module.css`

**Interfaces:**
- Produces: `CartItem` type, `cartReducer(state, action)`, `CartProvider`, `useCart()` hook returning `{ items, addItem, removeItem, setQuantity, total, isOpen, open, close }` — consumed by `ProductCard` (Task 8), `CheckoutForm` (Task 11), and `Header`/root layout (Task 9).

- [ ] **Step 1: Write the failing test for the reducer**

```typescript
// src/components/Cart/cartReducer.test.ts
import { describe, it, expect } from 'vitest';
import { cartReducer, type CartState } from './cartReducer';

const emptyState: CartState = { items: [] };

describe('cartReducer', () => {
  it('adds a new item', () => {
    const state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет «Счастье есть»', price: 4830, image: '/images/a.jpg' },
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0]).toMatchObject({ uid: '1', quantity: 1 });
  });

  it('increments quantity when adding an existing item', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0].quantity).toBe(2);
  });

  it('removes an item', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, { type: 'REMOVE_ITEM', uid: '1' });
    expect(state.items).toHaveLength(0);
  });

  it('sets an explicit quantity, removing the item if set to 0', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, { type: 'SET_QUANTITY', uid: '1', quantity: 3 });
    expect(state.items[0].quantity).toBe(3);

    state = cartReducer(state, { type: 'SET_QUANTITY', uid: '1', quantity: 0 });
    expect(state.items).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/components/Cart/cartReducer.test.ts`
Expected: FAIL — `cartReducer.ts` doesn't exist yet.

- [ ] **Step 3: Implement the reducer**

```typescript
// src/components/Cart/cartReducer.ts
export interface CartItem {
  uid: string;
  title: string;
  price: number;
  image: string;
  quantity: number;
}

export interface CartState {
  items: CartItem[];
}

export type CartAction =
  | { type: 'ADD_ITEM'; item: Omit<CartItem, 'quantity'> }
  | { type: 'REMOVE_ITEM'; uid: string }
  | { type: 'SET_QUANTITY'; uid: string; quantity: number }
  | { type: 'CLEAR' };

export function cartReducer(state: CartState, action: CartAction): CartState {
  switch (action.type) {
    case 'ADD_ITEM': {
      const existing = state.items.find((i) => i.uid === action.item.uid);
      if (existing) {
        return {
          items: state.items.map((i) =>
            i.uid === action.item.uid ? { ...i, quantity: i.quantity + 1 } : i
          ),
        };
      }
      return { items: [...state.items, { ...action.item, quantity: 1 }] };
    }
    case 'REMOVE_ITEM':
      return { items: state.items.filter((i) => i.uid !== action.uid) };
    case 'SET_QUANTITY': {
      if (action.quantity <= 0) {
        return { items: state.items.filter((i) => i.uid !== action.uid) };
      }
      return {
        items: state.items.map((i) =>
          i.uid === action.uid ? { ...i, quantity: action.quantity } : i
        ),
      };
    }
    case 'CLEAR':
      return { items: [] };
    default:
      return state;
  }
}

export function cartTotal(state: CartState): number {
  return state.items.reduce((sum, i) => sum + i.price * i.quantity, 0);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run src/components/Cart/cartReducer.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Write `CartContext.tsx`**

```tsx
'use client';

import { createContext, useContext, useEffect, useReducer, useState, type ReactNode } from 'react';
import { cartReducer, cartTotal, type CartItem, type CartState } from './cartReducer';

const STORAGE_KEY = 'pion-cart';

interface CartContextValue {
  items: CartItem[];
  total: number;
  isOpen: boolean;
  addItem: (item: Omit<CartItem, 'quantity'>) => void;
  removeItem: (uid: string) => void;
  setQuantity: (uid: string, quantity: number) => void;
  clear: () => void;
  open: () => void;
  close: () => void;
}

const CartContext = createContext<CartContextValue | null>(null);

export function CartProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(cartReducer, { items: [] } as CartState);
  const [isOpen, setIsOpen] = useState(false);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        const items = JSON.parse(raw) as CartItem[];
        items.forEach((item) => dispatch({ type: 'ADD_ITEM', item }));
        items.forEach((item) => {
          if (item.quantity > 1) dispatch({ type: 'SET_QUANTITY', uid: item.uid, quantity: item.quantity });
        });
      } catch {
        // ignore corrupt storage
      }
    }
    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state.items));
  }, [state.items, hydrated]);

  const value: CartContextValue = {
    items: state.items,
    total: cartTotal(state),
    isOpen,
    addItem: (item) => {
      dispatch({ type: 'ADD_ITEM', item });
      setIsOpen(true);
    },
    removeItem: (uid) => dispatch({ type: 'REMOVE_ITEM', uid }),
    setQuantity: (uid, quantity) => dispatch({ type: 'SET_QUANTITY', uid, quantity }),
    clear: () => dispatch({ type: 'CLEAR' }),
    open: () => setIsOpen(true),
    close: () => setIsOpen(false),
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
```

- [ ] **Step 6: Write `CartDrawer.tsx`**

```tsx
'use client';

import Image from 'next/image';
import { useCart } from './CartContext';
import styles from './CartDrawer.module.css';

export function CartDrawer() {
  const { items, total, isOpen, close, removeItem, setQuantity } = useCart();

  if (!isOpen) return null;

  return (
    <div className={styles.overlay} onClick={close}>
      <div className={styles.drawer} onClick={(e) => e.stopPropagation()}>
        <div className={styles.header}>
          <h3>Ваш заказ</h3>
          <button aria-label="Закрыть корзину" onClick={close}>×</button>
        </div>
        {items.length === 0 ? (
          <p className={styles.empty}>Корзина пуста</p>
        ) : (
          <ul className={styles.list}>
            {items.map((item) => (
              <li key={item.uid} className={styles.item}>
                <Image src={item.image} alt={item.title} width={64} height={64} />
                <div className={styles.itemInfo}>
                  <span>{item.title}</span>
                  <div className={styles.qtyRow}>
                    <button onClick={() => setQuantity(item.uid, item.quantity - 1)}>-</button>
                    <span>{item.quantity}</span>
                    <button onClick={() => setQuantity(item.uid, item.quantity + 1)}>+</button>
                  </div>
                </div>
                <span>{item.price * item.quantity} ₽</span>
                <button aria-label="Удалить" onClick={() => removeItem(item.uid)}>×</button>
              </li>
            ))}
          </ul>
        )}
        <div className={styles.footer}>
          <span>Итоговая сумма: {total} ₽</span>
          <a href="#checkout" className={styles.checkoutBtn} onClick={close}>Оформить заказ</a>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 7: Write `CartDrawer.module.css`**

```css
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  z-index: 100;
  display: flex;
  justify-content: flex-end;
}

.drawer {
  width: 400px;
  max-width: 100%;
  height: 100%;
  background: var(--color-white);
  padding: 24px;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.empty {
  margin-top: 40px;
  text-align: center;
  opacity: 0.6;
}

.list {
  list-style: none;
  margin: 16px 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.item {
  display: grid;
  grid-template-columns: 64px 1fr auto auto;
  gap: 12px;
  align-items: center;
}

.itemInfo {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
}

.qtyRow {
  display: flex;
  align-items: center;
  gap: 8px;
}

.footer {
  margin-top: auto;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-weight: 600;
}

.checkoutBtn {
  background: var(--color-black);
  color: var(--color-white);
  text-align: center;
  padding: 14px;
}
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add cart reducer, context, and drawer UI"
```

---

### Task 8: ProductCard + CategoryGrid + sort logic (unit tested)

**Files:**
- Create: `src/components/CategoryGrid/sortProducts.ts`
- Test: `src/components/CategoryGrid/sortProducts.test.ts`
- Create: `src/components/ProductCard/ProductCard.tsx`, `src/components/ProductCard/ProductCard.module.css`
- Create: `src/components/CategoryGrid/CategoryGrid.tsx`, `src/components/CategoryGrid/CategoryGrid.module.css`

**Interfaces:**
- Consumes: `Product` type from `@/lib/types`, `useCart()` from Task 7.
- Produces: `sortProducts(products, order)`, `<ProductCard product={Product} />`, `<CategoryGrid products={Product[]} title={string} subtitle={string} />` — used by category pages in Task 10.

- [ ] **Step 1: Write the failing test for sorting**

```typescript
// src/components/CategoryGrid/sortProducts.test.ts
import { describe, it, expect } from 'vitest';
import { sortProducts, type SortOrder } from './sortProducts';
import type { Product } from '@/lib/types';

const products: Product[] = [
  { uid: '1', title: 'Роза', description: '', price: 500, images: [], slug: 'roza' },
  { uid: '2', title: 'Астра', description: '', price: 1500, images: [], slug: 'astra' },
  { uid: '3', title: 'Ирис', description: '', price: 1000, images: [], slug: 'iris' },
];

describe('sortProducts', () => {
  it.each<[SortOrder, string[]]>([
    ['price-asc', ['Роза', 'Ирис', 'Астра']],
    ['price-desc', ['Астра', 'Ирис', 'Роза']],
    ['name-asc', ['Астра', 'Ирис', 'Роза']],
    ['name-desc', ['Роза', 'Ирис', 'Астра']],
  ])('sorts by %s', (order, expectedTitles) => {
    const sorted = sortProducts(products, order);
    expect(sorted.map((p) => p.title)).toEqual(expectedTitles);
  });

  it('does not mutate the input array', () => {
    const copy = [...products];
    sortProducts(products, 'price-desc');
    expect(products).toEqual(copy);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/components/CategoryGrid/sortProducts.test.ts`
Expected: FAIL — module doesn't exist.

- [ ] **Step 3: Implement `sortProducts.ts`**

```typescript
// src/components/CategoryGrid/sortProducts.ts
import type { Product } from '@/lib/types';

export type SortOrder = 'default' | 'price-asc' | 'price-desc' | 'name-asc' | 'name-desc';

export function sortProducts(products: Product[], order: SortOrder): Product[] {
  const copy = [...products];
  switch (order) {
    case 'price-asc':
      return copy.sort((a, b) => a.price - b.price);
    case 'price-desc':
      return copy.sort((a, b) => b.price - a.price);
    case 'name-asc':
      return copy.sort((a, b) => a.title.localeCompare(b.title, 'ru'));
    case 'name-desc':
      return copy.sort((a, b) => b.title.localeCompare(a.title, 'ru'));
    default:
      return copy;
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run src/components/CategoryGrid/sortProducts.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Write `ProductCard.tsx`**

```tsx
'use client';

import Image from 'next/image';
import { useCart } from '@/components/Cart/CartContext';
import type { Product } from '@/lib/types';
import styles from './ProductCard.module.css';

export function ProductCard({ product }: { product: Product }) {
  const { addItem } = useCart();

  return (
    <div className={styles.card}>
      <div className={styles.imageWrap}>
        {product.images[0] && (
          <Image src={product.images[0]} alt={product.title} fill className={styles.image} />
        )}
      </div>
      <h3 className={styles.title}>{product.title}</h3>
      <p className={styles.description}>{product.description}</p>
      <span className={styles.price}>{product.price.toLocaleString('ru-RU')} ₽</span>
      <button
        className={styles.addBtn}
        onClick={() =>
          addItem({
            uid: product.uid,
            title: product.title,
            price: product.price,
            image: product.images[0] || '',
          })
        }
      >
        Добавить в корзину
      </button>
    </div>
  );
}
```

- [ ] **Step 6: Write `ProductCard.module.css`**

```css
.card {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.imageWrap {
  position: relative;
  aspect-ratio: 1;
  background: var(--color-bg-light);
}

.image {
  object-fit: cover;
}

.title {
  font-size: 15px;
  margin: 8px 0 0;
}

.description {
  font-size: 12px;
  opacity: 0.7;
  margin: 0;
  min-height: 32px;
}

.price {
  font-weight: 700;
}

.addBtn {
  background: var(--color-accent);
  color: var(--color-white);
  border: none;
  padding: 12px;
  font-weight: 600;
  cursor: pointer;
}
```

- [ ] **Step 7: Write `CategoryGrid.tsx`**

```tsx
'use client';

import { useState, useMemo } from 'react';
import type { Product } from '@/lib/types';
import { sortProducts, type SortOrder } from './sortProducts';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import styles from './CategoryGrid.module.css';

const SORT_LABELS: Record<SortOrder, string> = {
  default: 'По умолчанию',
  'price-asc': 'Цена: по возрастанию',
  'price-desc': 'Цена: по убыванию',
  'name-asc': 'Название: А—Я',
  'name-desc': 'Название: Я—А',
};

export function CategoryGrid({
  products,
  title,
  subtitle,
}: {
  products: Product[];
  title: string;
  subtitle: string;
}) {
  const [order, setOrder] = useState<SortOrder>('default');
  const sorted = useMemo(() => sortProducts(products, order), [products, order]);

  return (
    <section className={styles.section}>
      <div className={styles.heading}>
        <h1>{title}</h1>
        <p>{subtitle}</p>
      </div>
      <div className={styles.toolbar}>
        <label>
          Порядок:{' '}
          <select value={order} onChange={(e) => setOrder(e.target.value as SortOrder)}>
            {Object.entries(SORT_LABELS).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </label>
      </div>
      <div className={styles.grid}>
        {sorted.map((product) => (
          <ProductCard key={product.uid} product={product} />
        ))}
      </div>
    </section>
  );
}
```

- [ ] **Step 8: Write `CategoryGrid.module.css`**

```css
.section {
  padding: 48px 40px;
}

.heading {
  text-align: center;
  margin-bottom: 24px;
}

.toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
  font-size: 13px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 32px;
}
```

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add ProductCard, CategoryGrid, and sortProducts with tests"
```

---

### Task 9: Root layout wiring + home page

**Files:**
- Modify: `src/app/layout.tsx`
- Create: `src/app/page.tsx`

**Interfaces:**
- Consumes: `Header` (Task 5), `Footer` (Task 5), `CartProvider`/`CartDrawer` (Task 7), `HeroSlider` (Task 6), `getSite()` (Task 2).

- [ ] **Step 1: Wire `CartProvider` and `CartDrawer` into the root layout**

```tsx
// src/app/layout.tsx
import type { Metadata } from 'next';
import { Montserrat } from 'next/font/google';
import { CartProvider } from '@/components/Cart/CartContext';
import { CartDrawer } from '@/components/Cart/CartDrawer';
import { Header } from '@/components/Header/Header';
import { Footer } from '@/components/Footer/Footer';
import './globals.css';

const montserrat = Montserrat({
  subsets: ['latin', 'cyrillic'],
  weight: ['300', '400', '500', '600', '700', '800'],
  variable: '--font-montserrat',
});

export const metadata: Metadata = {
  title: 'Салон цветов и подарков «Пион»',
  description: 'Букеты и подарки с доставкой по Перми',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ru" className={montserrat.variable}>
      <body>
        <CartProvider>
          <Header />
          {children}
          <Footer />
          <CartDrawer />
        </CartProvider>
      </body>
    </html>
  );
}
```

- [ ] **Step 2: Write the home page**

```tsx
// src/app/page.tsx
import { getSite, getCatalog } from '@/lib/content';
import { HeroSlider } from '@/components/HeroSlider/HeroSlider';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import styles from './page.module.css';

export default async function HomePage() {
  const site = getSite();
  const newProducts = (await getCatalog('bukety'))?.slice(0, 3) ?? [];

  return (
    <main>
      <HeroSlider slides={site.heroSlides} />

      <section className={styles.newSection}>
        <h2>Новинки</h2>
        <div className={styles.newGrid}>
          {newProducts.map((p) => <ProductCard key={p.uid} product={p} />)}
        </div>
      </section>

      <section className={styles.features}>
        {site.features.map((f) => (
          <div key={f.title} className={styles.feature}>
            <h3>{f.title}</h3>
            <p>{f.description}</p>
          </div>
        ))}
      </section>
    </main>
  );
}
```

- [ ] **Step 3: Write `src/app/page.module.css`**

```css
.newSection {
  padding: 48px 40px;
  text-align: center;
}

.newGrid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 32px;
  margin-top: 24px;
  text-align: left;
}

.features {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 24px;
  padding: 48px 40px;
  background: var(--color-bg-light);
}

.feature h3 {
  font-size: 15px;
}

.feature p {
  font-size: 13px;
  opacity: 0.7;
}

@media (max-width: 900px) {
  .features {
    grid-template-columns: repeat(2, 1fr);
  }
}
```

- [ ] **Step 4: Run the dev server and check the home page renders**

Run: `npm run dev` (then stop with Ctrl+C once verified — don't leave it running)
Expected: `http://localhost:3000` shows header, hero slider with a real photo, "Новинки" with 3 real bouquets, features grid, and footer — no console errors.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: wire cart/header/footer into root layout, build home page"
```

---

### Task 10: Catalog overview + dynamic category/page route

**Files:**
- Create: `src/app/catalog/page.tsx`
- Create: `src/app/[slug]/page.tsx`
- Create: `src/app/[slug]/not-found.tsx`

**Interfaces:**
- Consumes: `CATEGORY_SLUGS`, `PAGE_SLUGS`, `getCatalog`, `getPage` (Task 2); `CategoryGrid` (Task 8); `ContentBlocks` (Task 12 — this task can be done first since `ContentBlocks` only needs its prop type, defined already in Task 2's `types.ts`).

- [ ] **Step 1: Write the catalog overview page**

```tsx
// src/app/catalog/page.tsx
import Link from 'next/link';
import { CATEGORY_SLUGS } from '@/lib/content';

const CATEGORY_LABELS: Record<string, string> = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  flowers: 'Цветы',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  indoorflowers: 'Комнатные растения',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
};

export default function CatalogPage() {
  return (
    <main style={{ padding: '48px 40px' }}>
      <h1>Каталог</h1>
      <ul style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 16, listStyle: 'none', padding: 0 }}>
        {CATEGORY_SLUGS.map((slug) => (
          <li key={slug}>
            <Link href={`/${slug}`}>{CATEGORY_LABELS[slug] ?? slug}</Link>
          </li>
        ))}
      </ul>
    </main>
  );
}
```

- [ ] **Step 2: Write the dynamic `[slug]` route**

```tsx
// src/app/[slug]/page.tsx
import { notFound } from 'next/navigation';
import { CATEGORY_SLUGS, PAGE_SLUGS, getCatalog, getPage } from '@/lib/content';
import { CategoryGrid } from '@/components/CategoryGrid/CategoryGrid';
import { ContentBlocks } from '@/components/ContentBlocks/ContentBlocks';

export function generateStaticParams() {
  return [...CATEGORY_SLUGS, ...PAGE_SLUGS].map((slug) => ({ slug }));
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    return <CategoryGrid products={products} title={slug} subtitle="" />;
  }

  if ((PAGE_SLUGS as readonly string[]).includes(slug)) {
    const blocks = (await getPage(slug)) ?? [];
    return <ContentBlocks blocks={blocks} />;
  }

  notFound();
}
```

- [ ] **Step 3: Write `not-found.tsx`**

```tsx
// src/app/[slug]/not-found.tsx
export default function NotFound() {
  return (
    <main style={{ padding: '80px 40px', textAlign: 'center' }}>
      <h1>Страница не найдена</h1>
    </main>
  );
}
```

- [ ] **Step 4: Verify routes render**

Run: `npm run dev`, then visit `http://localhost:3000/bukety` and `http://localhost:3000/about` (stop the server with Ctrl+C once verified)
Expected: `/bukety` shows the product grid with real scraped products; `/about` shows whatever `ContentBlocks` renders (even a minimal version is fine — Task 12 finishes its styling); an unknown slug like `/does-not-exist` shows the not-found page.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add catalog overview page and dynamic category/page route"
```

---

### Task 11: Checkout form — validation (unit tested) + UI + stub confirmation

**Files:**
- Create: `src/components/CheckoutForm/validateCheckout.ts`
- Test: `src/components/CheckoutForm/validateCheckout.test.ts`
- Create: `src/components/CheckoutForm/CheckoutForm.tsx`, `src/components/CheckoutForm/CheckoutForm.module.css`
- Modify: `src/components/Cart/CartDrawer.tsx` (link `#checkout` to a route rendering `CheckoutForm`)
- Create: `src/app/checkout/page.tsx`

**Interfaces:**
- Consumes: `useCart()` (Task 7).
- Produces: `validateCheckout(values)` returning `{ valid: boolean; errors: Record<string, string> }`.

- [ ] **Step 1: Write the failing validation test**

```typescript
// src/components/CheckoutForm/validateCheckout.test.ts
import { describe, it, expect } from 'vitest';
import { validateCheckout, type CheckoutValues } from './validateCheckout';

const validValues: CheckoutValues = {
  name: 'Анна',
  email: 'anna@example.com',
  phone: '+79001234567',
  deliveryOption: '1',
  address: 'ул. Ленина, 1',
  paymentMethod: 'cash',
};

describe('validateCheckout', () => {
  it('accepts fully filled-in values', () => {
    expect(validateCheckout(validValues)).toEqual({ valid: true, errors: {} });
  });

  it('requires a name', () => {
    const result = validateCheckout({ ...validValues, name: '' });
    expect(result.valid).toBe(false);
    expect(result.errors.name).toBeDefined();
  });

  it('requires a valid email', () => {
    const result = validateCheckout({ ...validValues, email: 'not-an-email' });
    expect(result.valid).toBe(false);
    expect(result.errors.email).toBeDefined();
  });

  it('requires a valid Russian phone number', () => {
    const result = validateCheckout({ ...validValues, phone: '123' });
    expect(result.valid).toBe(false);
    expect(result.errors.phone).toBeDefined();
  });

  it('requires an address unless self-pickup is chosen', () => {
    const result = validateCheckout({ ...validValues, address: '' });
    expect(result.valid).toBe(false);
    expect(result.errors.address).toBeDefined();

    const pickup = validateCheckout({ ...validValues, address: '', deliveryOption: 'pickup' });
    expect(pickup.valid).toBe(true);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/components/CheckoutForm/validateCheckout.test.ts`
Expected: FAIL — module doesn't exist.

- [ ] **Step 3: Implement `validateCheckout.ts`**

```typescript
// src/components/CheckoutForm/validateCheckout.ts
export interface CheckoutValues {
  name: string;
  email: string;
  phone: string;
  deliveryOption: string;
  address: string;
  paymentMethod: string;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE_RE = /^\+?7\d{10}$/;

export function validateCheckout(values: CheckoutValues): { valid: boolean; errors: Record<string, string> } {
  const errors: Record<string, string> = {};

  if (!values.name.trim()) errors.name = 'Укажите ваше имя';
  if (!EMAIL_RE.test(values.email)) errors.email = 'Укажите корректный email';
  if (!PHONE_RE.test(values.phone.replace(/[\s()-]/g, ''))) errors.phone = 'Укажите корректный телефон';
  if (values.deliveryOption !== 'pickup' && !values.address.trim()) {
    errors.address = 'Укажите адрес доставки';
  }
  if (!values.paymentMethod) errors.paymentMethod = 'Выберите способ оплаты';

  return { valid: Object.keys(errors).length === 0, errors };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run src/components/CheckoutForm/validateCheckout.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Write `CheckoutForm.tsx`**

```tsx
'use client';

import { useState } from 'react';
import { useCart } from '@/components/Cart/CartContext';
import { validateCheckout, type CheckoutValues } from './validateCheckout';
import styles from './CheckoutForm.module.css';

const DELIVERY_OPTIONS = [
  { value: '1', label: 'До 5км от нашего магазина - 500 рублей' },
  { value: '2', label: 'До 7км от нашего магазина - 800 рублей' },
  { value: '3', label: 'До 9км от нашего магазина - 950 рублей' },
  { value: 'pickup', label: 'Самовывоз из нашего салона по адресу ул.Газеты Звезда, 27. При самовывозе - скидка 5%' },
];

const initialValues: CheckoutValues = {
  name: '', email: '', phone: '', deliveryOption: '1', address: '', paymentMethod: '',
};

export function CheckoutForm() {
  const { items, total, clear } = useCart();
  const [values, setValues] = useState<CheckoutValues>(initialValues);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState(false);

  function update<K extends keyof CheckoutValues>(key: K, value: CheckoutValues[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const result = validateCheckout(values);
    setErrors(result.errors);
    if (result.valid) {
      setSubmitted(true);
      clear();
    }
  }

  if (submitted) {
    return (
      <div className={styles.thanks}>
        <h2>Спасибо, мы с вами свяжемся!</h2>
        <p>Заказ принят. Наш флорист свяжется с вами для подтверждения деталей.</p>
      </div>
    );
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <h2>Оформить заказ</h2>

      <ul className={styles.summary}>
        {items.map((item) => (
          <li key={item.uid}>{item.title} × {item.quantity} — {item.price * item.quantity} ₽</li>
        ))}
      </ul>
      <p className={styles.total}>Сумма: {total} ₽</p>

      <label>
        Ваше имя
        <input value={values.name} onChange={(e) => update('name', e.target.value)} />
      </label>
      {errors.name && <span className={styles.error}>{errors.name}</span>}

      <label>
        Ваш Email
        <input type="email" value={values.email} onChange={(e) => update('email', e.target.value)} />
      </label>
      {errors.email && <span className={styles.error}>{errors.email}</span>}

      <label>
        Ваш телефон
        <input
          type="tel"
          placeholder="+7 (999) 999-9999"
          value={values.phone}
          onChange={(e) => update('phone', e.target.value)}
        />
      </label>
      {errors.phone && <span className={styles.error}>{errors.phone}</span>}

      <fieldset>
        <legend>Доставка</legend>
        {DELIVERY_OPTIONS.map((opt) => (
          <label key={opt.value} className={styles.radioLabel}>
            <input
              type="radio"
              name="deliveryOption"
              value={opt.value}
              checked={values.deliveryOption === opt.value}
              onChange={() => update('deliveryOption', opt.value)}
            />
            {opt.label}
          </label>
        ))}
      </fieldset>

      {values.deliveryOption !== 'pickup' && (
        <>
          <label>
            Адрес доставки
            <input value={values.address} onChange={(e) => update('address', e.target.value)} />
          </label>
          {errors.address && <span className={styles.error}>{errors.address}</span>}
        </>
      )}

      <fieldset>
        <legend>Способ оплаты</legend>
        <label className={styles.radioLabel}>
          <input
            type="radio"
            name="paymentMethod"
            checked={values.paymentMethod === 'card'}
            onChange={() => update('paymentMethod', 'card')}
          />
          Онлайн оплата картой
        </label>
        <label className={styles.radioLabel}>
          <input
            type="radio"
            name="paymentMethod"
            checked={values.paymentMethod === 'cash'}
            onChange={() => update('paymentMethod', 'cash')}
          />
          Наличными при самовывозе
        </label>
      </fieldset>
      {errors.paymentMethod && <span className={styles.error}>{errors.paymentMethod}</span>}

      <button type="submit" className={styles.submitBtn}>Оформить заказ</button>
    </form>
  );
}
```

- [ ] **Step 6: Write `CheckoutForm.module.css`**

```css
.form {
  max-width: 480px;
  margin: 0 auto;
  padding: 48px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.summary {
  list-style: none;
  padding: 0;
  font-size: 13px;
}

.total {
  font-weight: 700;
}

label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
}

input {
  padding: 10px;
  border: 1px solid var(--color-border);
}

.radioLabel {
  flex-direction: row;
  align-items: center;
  gap: 8px;
  font-weight: 400;
}

.error {
  color: #c0392b;
  font-size: 12px;
}

.submitBtn {
  margin-top: 12px;
  background: var(--color-black);
  color: var(--color-white);
  border: none;
  padding: 16px;
  font-weight: 600;
  cursor: pointer;
}

.thanks {
  max-width: 480px;
  margin: 80px auto;
  text-align: center;
}
```

- [ ] **Step 7: Add the checkout route**

```tsx
// src/app/checkout/page.tsx
import { CheckoutForm } from '@/components/CheckoutForm/CheckoutForm';

export default function CheckoutPage() {
  return (
    <main>
      <CheckoutForm />
    </main>
  );
}
```

- [ ] **Step 8: Point the cart drawer's checkout link at the new route**

In `src/components/Cart/CartDrawer.tsx`, change:

```tsx
<a href="#checkout" className={styles.checkoutBtn} onClick={close}>Оформить заказ</a>
```

to:

```tsx
import Link from 'next/link';
// ...
<Link href="/checkout" className={styles.checkoutBtn} onClick={close}>Оформить заказ</Link>
```

- [ ] **Step 9: Manually verify the flow**

Run: `npm run dev` (stop with Ctrl+C once verified). Add a product to the cart, open the drawer, click "Оформить заказ", submit the form with an invalid phone (expect an inline error), then with valid data (expect the "Спасибо" screen and an emptied cart).

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: add checkout form with validation and stub confirmation"
```

---

### Task 12: Bouquet builder popup — validation (unit tested) + multi-step UI

**Files:**
- Create: `src/components/BouquetBuilder/validateBouquetForm.ts`
- Test: `src/components/BouquetBuilder/validateBouquetForm.test.ts`
- Create: `src/components/BouquetBuilder/BouquetBuilderPopup.tsx`, `src/components/BouquetBuilder/BouquetBuilder.module.css`
- Modify: `src/app/page.tsx` (add the "Собери свой идеальный букет" CTA section that opens the popup)

**Interfaces:**
- Produces: `validateBouquetStep(step, values)`, `<BouquetBuilderPopup open={boolean} onClose={() => void} />`.

- [ ] **Step 1: Write the failing validation test**

```typescript
// src/components/BouquetBuilder/validateBouquetForm.test.ts
import { describe, it, expect } from 'vitest';
import { validateBouquetStep, type BouquetValues } from './validateBouquetForm';

const valid: BouquetValues = {
  bouquetType: 'Авторский букет',
  colorScheme: 'Яркая гамма',
  size: 'M (средний)',
  budget: 5000,
  readyBy: '',
  extras: '',
  comment: '',
  name: 'Ирина',
  phone: '+79001234567',
  email: 'irina@example.com',
};

describe('validateBouquetStep', () => {
  it('step 0 requires a bouquet type', () => {
    expect(validateBouquetStep(0, { ...valid, bouquetType: '' }).valid).toBe(false);
    expect(validateBouquetStep(0, valid).valid).toBe(true);
  });

  it('step 1 requires a color scheme', () => {
    expect(validateBouquetStep(1, { ...valid, colorScheme: '' }).valid).toBe(false);
    expect(validateBouquetStep(1, valid).valid).toBe(true);
  });

  it('step 2 requires a size', () => {
    expect(validateBouquetStep(2, { ...valid, size: '' }).valid).toBe(false);
  });

  it('step 3 requires budget within 3000-50000', () => {
    expect(validateBouquetStep(3, { ...valid, budget: 1000 }).valid).toBe(false);
    expect(validateBouquetStep(3, { ...valid, budget: 3000 }).valid).toBe(true);
    expect(validateBouquetStep(3, { ...valid, budget: 50000 }).valid).toBe(true);
  });

  it('final step requires name, valid phone and email', () => {
    expect(validateBouquetStep(6, { ...valid, name: '' }).valid).toBe(false);
    expect(validateBouquetStep(6, { ...valid, phone: '123' }).valid).toBe(false);
    expect(validateBouquetStep(6, { ...valid, email: 'bad' }).valid).toBe(false);
    expect(validateBouquetStep(6, valid).valid).toBe(true);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run src/components/BouquetBuilder/validateBouquetForm.test.ts`
Expected: FAIL — module doesn't exist.

- [ ] **Step 3: Implement `validateBouquetForm.ts`**

```typescript
// src/components/BouquetBuilder/validateBouquetForm.ts
export interface BouquetValues {
  bouquetType: string;
  colorScheme: string;
  size: string;
  budget: number;
  readyBy: string;
  extras: string;
  comment: string;
  name: string;
  phone: string;
  email: string;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE_RE = /^\+?7\d{10}$/;

export function validateBouquetStep(step: number, values: BouquetValues): { valid: boolean; error?: string } {
  switch (step) {
    case 0:
      return values.bouquetType ? { valid: true } : { valid: false, error: 'Выберите тип букета' };
    case 1:
      return values.colorScheme ? { valid: true } : { valid: false, error: 'Выберите цветовую гамму' };
    case 2:
      return values.size ? { valid: true } : { valid: false, error: 'Выберите размер букета' };
    case 3:
      return values.budget >= 3000 && values.budget <= 50000
        ? { valid: true }
        : { valid: false, error: 'Бюджет должен быть от 3 000 до 50 000 рублей' };
    case 4:
    case 5:
      return { valid: true };
    case 6: {
      if (!values.name.trim()) return { valid: false, error: 'Укажите ваше имя' };
      if (!PHONE_RE.test(values.phone.replace(/[\s()-]/g, ''))) {
        return { valid: false, error: 'Укажите корректный телефон' };
      }
      if (!EMAIL_RE.test(values.email)) return { valid: false, error: 'Укажите корректный email' };
      return { valid: true };
    }
    default:
      return { valid: true };
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run src/components/BouquetBuilder/validateBouquetForm.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Write `BouquetBuilderPopup.tsx`**

```tsx
'use client';

import { useState } from 'react';
import { validateBouquetStep, type BouquetValues } from './validateBouquetForm';
import styles from './BouquetBuilder.module.css';

const STEPS = [
  'Тип букета', 'Цветовая гамма', 'Размер', 'Бюджет', 'Сроки и подарки', 'Комментарий', 'Контакты',
];

const initialValues: BouquetValues = {
  bouquetType: '', colorScheme: '', size: '', budget: 10000,
  readyBy: '', extras: '', comment: '', name: '', phone: '', email: '',
};

export function BouquetBuilderPopup({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [step, setStep] = useState(0);
  const [values, setValues] = useState<BouquetValues>(initialValues);
  const [error, setError] = useState<string | undefined>();
  const [submitted, setSubmitted] = useState(false);

  if (!open) return null;

  function update<K extends keyof BouquetValues>(key: K, value: BouquetValues[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function handleNext() {
    const result = validateBouquetStep(step, values);
    if (!result.valid) {
      setError(result.error);
      return;
    }
    setError(undefined);
    if (step === STEPS.length - 1) {
      setSubmitted(true);
    } else {
      setStep((s) => s + 1);
    }
  }

  function handleBack() {
    setError(undefined);
    setStep((s) => Math.max(0, s - 1));
  }

  function handleCloseAndReset() {
    setStep(0);
    setValues(initialValues);
    setSubmitted(false);
    setError(undefined);
    onClose();
  }

  return (
    <div className={styles.overlay} onClick={handleCloseAndReset}>
      <div className={styles.modal} onClick={(e) => e.stopPropagation()}>
        <button aria-label="Закрыть диалоговое окно" className={styles.closeBtn} onClick={handleCloseAndReset}>×</button>

        {submitted ? (
          <div className={styles.thanks}>
            <h2>Спасибо, мы с вами свяжемся!</h2>
            <p>Флорист свяжется с вами, чтобы обсудить детали букета.</p>
          </div>
        ) : (
          <>
            <p className={styles.stepIndicator}>{step + 1}/{STEPS.length}</p>
            <h2>{STEPS[step]}</h2>

            {step === 0 && (
              <RadioGroup
                options={['Авторский букет', 'Монобукет', 'Коробка с цветами', 'Корзина цветов']}
                value={values.bouquetType}
                onChange={(v) => update('bouquetType', v)}
              />
            )}
            {step === 1 && (
              <RadioGroup
                options={['Яркая гамма', 'Нежная гамма', 'Доверяю флористу', 'Другое (указать в комментарии)']}
                value={values.colorScheme}
                onChange={(v) => update('colorScheme', v)}
              />
            )}
            {step === 2 && (
              <RadioGroup
                options={['S (маленький)', 'M (средний)', 'L (большой)']}
                value={values.size}
                onChange={(v) => update('size', v)}
              />
            )}
            {step === 3 && (
              <label className={styles.rangeLabel}>
                Бюджет букета (минимальная сумма - 3 000 рублей): {values.budget} ₽
                <input
                  type="range"
                  min={3000}
                  max={50000}
                  step={500}
                  value={values.budget}
                  onChange={(e) => update('budget', Number(e.target.value))}
                />
              </label>
            )}
            {step === 4 && (
              <>
                <label>
                  К какому дню должен быть готов букет?
                  <input value={values.readyBy} onChange={(e) => update('readyBy', e.target.value)} />
                </label>
                <label>
                  Необходимы ли открытка и дополнительные подарки?
                  <input value={values.extras} onChange={(e) => update('extras', e.target.value)} />
                </label>
              </>
            )}
            {step === 5 && (
              <label>
                Ваш комментарий
                <textarea value={values.comment} onChange={(e) => update('comment', e.target.value)} />
              </label>
            )}
            {step === 6 && (
              <>
                <label>
                  Ваше имя
                  <input value={values.name} onChange={(e) => update('name', e.target.value)} />
                </label>
                <label>
                  Телефон
                  <input
                    type="tel"
                    placeholder="(000) 000-00-00"
                    value={values.phone}
                    onChange={(e) => update('phone', e.target.value)}
                  />
                </label>
                <label>
                  Ваш e-mail
                  <input type="email" value={values.email} onChange={(e) => update('email', e.target.value)} />
                </label>
              </>
            )}

            {error && <p className={styles.error}>{error}</p>}

            <div className={styles.nav}>
              {step > 0 && <button type="button" onClick={handleBack}>← Назад</button>}
              <button type="button" className={styles.nextBtn} onClick={handleNext}>
                {step === STEPS.length - 1 ? 'Отправить' : 'Далее →'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function RadioGroup({
  options, value, onChange,
}: {
  options: string[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className={styles.radioGroup}>
      {options.map((opt) => (
        <label key={opt} className={styles.radioOption}>
          <input type="radio" checked={value === opt} onChange={() => onChange(opt)} />
          {opt}
        </label>
      ))}
    </div>
  );
}
```

- [ ] **Step 6: Write `BouquetBuilder.module.css`**

```css
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal {
  position: relative;
  background: var(--color-white);
  padding: 40px;
  width: 480px;
  max-width: 90vw;
  max-height: 90vh;
  overflow-y: auto;
}

.closeBtn {
  position: absolute;
  top: 12px;
  right: 12px;
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
}

.stepIndicator {
  font-size: 12px;
  opacity: 0.6;
}

.radioGroup {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 16px 0;
}

.radioOption {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.rangeLabel {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 14px;
  margin: 16px 0;
}

label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
  margin-bottom: 12px;
}

input, textarea {
  padding: 10px;
  border: 1px solid var(--color-border);
  font-family: inherit;
}

.error {
  color: #c0392b;
  font-size: 12px;
}

.nav {
  display: flex;
  justify-content: space-between;
  margin-top: 20px;
}

.nextBtn {
  background: var(--color-black);
  color: var(--color-white);
  border: none;
  padding: 12px 24px;
  cursor: pointer;
  margin-left: auto;
}

.thanks {
  text-align: center;
  padding: 24px 0;
}
```

- [ ] **Step 7: Wire the popup into the home page CTA**

In `src/app/page.tsx`, add state and a trigger button:

```tsx
'use client';
// NOTE: adding client state here means HomePage can no longer be an async
// server component. Move the data fetching (getSite/getCatalog calls) into
// a small server component wrapper, e.g. rename the current file's body to
// `HomePageContent` (server) and have a new client component `HomePageCta`
// that only renders the button + popup, imported into the page.
```

Concretely: keep `src/app/page.tsx` as the async server component from Task 9, and add a new client component:

```tsx
// src/components/BouquetBuilder/BouquetBuilderCta.tsx
'use client';

import { useState } from 'react';
import { BouquetBuilderPopup } from './BouquetBuilderPopup';

export function BouquetBuilderCta() {
  const [open, setOpen] = useState(false);
  return (
    <section style={{ padding: '48px 40px', textAlign: 'center', background: 'var(--color-bg-light)' }}>
      <h2>Соберите свой идеальный букет</h2>
      <p>Вы можете заказать букет, цветочную коробку, корзину, композицию по вашим параметрам и пожеланиям</p>
      <button onClick={() => setOpen(true)}>Заказать</button>
      <BouquetBuilderPopup open={open} onClose={() => setOpen(false)} />
    </section>
  );
}
```

Then in `src/app/page.tsx`, import and render `<BouquetBuilderCta />` after the features section.

- [ ] **Step 8: Manually verify the flow**

Run: `npm run dev` (stop with Ctrl+C once verified). Click "Заказать", step through all 7 steps (try clicking "Далее" without filling a required field to confirm the inline error shows), submit on the last step, confirm the "Спасибо" screen appears.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add multi-step bouquet builder popup with validation"
```

---

### Task 13: ContentBlocks renderer for static/promo pages

**Files:**
- Create: `src/components/ContentBlocks/ContentBlocks.tsx`, `src/components/ContentBlocks/ContentBlocks.module.css`

**Interfaces:**
- Consumes: `ContentBlock[]` type (Task 2), already wired into `src/app/[slug]/page.tsx` (Task 10).

- [ ] **Step 1: Write `ContentBlocks.tsx`**

```tsx
import Image from 'next/image';
import type { ContentBlock } from '@/lib/types';
import styles from './ContentBlocks.module.css';

export function ContentBlocks({ blocks }: { blocks: ContentBlock[] }) {
  return (
    <main className={styles.page}>
      {blocks.map((block, i) => {
        switch (block.type) {
          case 'heading':
            return <h1 key={i} className={styles.heading}>{block.text}</h1>;
          case 'paragraph':
            return <p key={i} className={styles.paragraph}>{block.text}</p>;
          case 'image':
            return (
              <div key={i} className={styles.imageWrap}>
                <Image src={block.src} alt={block.alt} width={800} height={533} className={styles.image} />
              </div>
            );
          case 'cta':
            return (
              <a key={i} href={block.href} className={styles.cta}>{block.text}</a>
            );
          default:
            return null;
        }
      })}
    </main>
  );
}
```

- [ ] **Step 2: Write `ContentBlocks.module.css`**

```css
.page {
  max-width: 860px;
  margin: 0 auto;
  padding: 48px 24px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.heading {
  font-size: 28px;
}

.paragraph {
  font-size: 15px;
  line-height: 1.7;
}

.imageWrap {
  position: relative;
  width: 100%;
}

.image {
  width: 100%;
  height: auto;
}

.cta {
  align-self: flex-start;
  background: var(--color-accent);
  color: var(--color-white);
  padding: 14px 28px;
  font-weight: 600;
}
```

- [ ] **Step 3: Verify a real page renders with real scraped content**

Run: `npm run dev` (stop with Ctrl+C once verified), visit `http://localhost:3000/about` and `http://localhost:3000/contacts`.
Expected: headings/paragraphs/images from the scraped `data/pages/about.json` / `data/pages/contacts.json` render in order, images load from `public/images/pages/...`.

If a page's `data/pages/<slug>.json` came out empty or thin from the Task 4 scrape (some Tilda pages use non-standard block markup the generic selector in Task 4 Step 1 misses), open that page live, inspect its DOM, and either adjust the selector or hand-add a few `ContentBlock` entries directly to that page's JSON file — it's static content, editing it by hand is fine.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add ContentBlocks renderer for static and promo pages"
```

---

### Task 14: Full-site responsive and visual QA pass

**Files:** none created — verification only, with small fixes to existing CSS files as needed.

- [ ] **Step 1: Build the production bundle**

Run: `npm run build`
Expected: build succeeds with no type or lint errors; static params for all 23 `[slug]` routes are generated (visible in the build output route list).

- [ ] **Step 2: Start the production server**

Run: `npm run start` (leave it running for the manual pass below, then Ctrl+C when done)

- [ ] **Step 3: Desktop pass — compare every page against the live site**

Open each of these local routes side-by-side with the same page on `https://pionperm.ru` at a desktop width (~1440px): `/`, `/catalog`, `/bukety`, `/korziny`, `/korobki`, `/flowers`, `/wedding`, `/balloons`, `/chocolate`, `/indoorflowers`, `/luchshee`, `/flame`, `/pions`, `/roses`, `/mixflower`, `/about`, `/delivery-and-payment`, `/flower-delivery`, `/contacts`, `/uds`, `/stock`, `/policy`, `/valentinesday`, `/new-year-2025`, `/doza_endorfina`, `/checkout`.

For each, confirm: header/footer match, real photos load (no broken images), colors/fonts match the design tokens, and text content is present and not obviously truncated or missing.

- [ ] **Step 4: Mobile pass**

Resize the browser to ~390px width and re-check `/`, `/bukety`, `/checkout`, and one static page (`/about`). Confirm the header collapses sensibly, the product grid goes to 1–2 columns, and the cart drawer and bouquet-builder popup remain usable (don't overflow the viewport).

- [ ] **Step 5: Fix any visual gaps found**

For each mismatch found in Steps 3–4, fix it in the relevant component's `.module.css` (or, for missing content, in the relevant `data/pages/*.json` / `data/catalog/*.json` file per Task 13 Step 3's guidance) and re-check that one page.

- [ ] **Step 6: Run the full test suite one more time**

Run: `npm test`
Expected: all unit tests (cartReducer, sortProducts, validateCheckout, validateBouquetForm) still pass.

- [ ] **Step 7: Commit any fixes**

```bash
git add -A
git commit -m "fix: visual QA pass across all pages, responsive fixes"
```
