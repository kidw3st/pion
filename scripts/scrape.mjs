import { chromium } from 'playwright';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { downloadImage } from './lib/downloadImage.mjs';

const SITE = 'https://pionperm.ru';
const ROOT = path.resolve(import.meta.dirname, '..');

// Pull the product image URL off a Tilda store card. Tilda does NOT render an
// <img> tag for card thumbnails — it uses a div.t-store__card__bgimg with a
// full-res URL in the `data-original` attribute and a resized thumbnail set
// via an inline `background-image: url(...)` style. Prefer data-original
// (full resolution); fall back to parsing the inline style if it's missing.
async function extractImageSrc(card) {
  const bgImg = card.locator('.t-store__card__bgimg').first();
  const dataOriginal = await bgImg.getAttribute('data-original').catch(() => null);
  if (dataOriginal) return dataOriginal;

  const style = await bgImg.getAttribute('style').catch(() => null);
  if (style) {
    const match = style.match(/url\((['"]?)(.*?)\1\)/);
    if (match) return match[2];
  }

  // Last-resort fallback in case a future Tilda block version renders a
  // plain <img> after all.
  return card.locator('img').first().getAttribute('src').catch(() => null);
}

export async function scrapeCategory(page, slug, categoryUrl = `${SITE}/${slug}`) {
  // Tilda's own store-widget JS (tilda-cart-1.1.min.js) fetches product data
  // from store.tildacdn.com client-side. That fetch intermittently fails in
  // this environment ("Failed to fetch" / net::ERR_TIMED_OUT — confirmed via
  // live console diagnostic on /korziny), and when it does, Tilda's widget
  // gives up after its own internal retry and renders the "nothing found"
  // empty-state message (`.js-store-empty-part-msg`) — indistinguishable
  // from a genuinely empty category unless the whole page load is retried.
  // So: reload up to `maxAttempts` times whenever we land on zero cards with
  // that empty-state message showing, before accepting the category as
  // actually empty. Raw `fetch()` to store.tildacdn.com from plain Node
  // timed out on roughly 2 of every 5 requests when measured directly; a
  // real page load can need more than one such request to succeed (product
  // list + filters) and was observed failing several times in a row during
  // live validation (even for `bukety`, which scraped cleanly on the very
  // first try in Task 3) — so this uses 8 attempts with a several-second
  // backoff between reloads rather than the brief's suggested 3, to keep
  // the odds of an all-attempts failure low even during a bad network
  // window. A category still showing 0 cards + the empty-state message
  // after all 8 attempts is reported as empty; re-run the scraper for that
  // one slug if that turns out to be wrong.
  const maxAttempts = 8;
  let cards = [];
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt === 1) {
      // Tilda pages keep background connections open (analytics, chat
      // widgets), so `networkidle` frequently times out even once the page
      // is usable. `load` + waiting for the first product card is more
      // reliable.
      await page.goto(categoryUrl, { waitUntil: 'load', timeout: 60000 });
    } else {
      console.log(`${slug}: retrying after empty result (attempt ${attempt})`);
      await page.waitForTimeout(4000 + Math.random() * 3000);
      await page.reload({ waitUntil: 'load', timeout: 60000 });
    }
    await page.locator('.t-store__card').first().waitFor({ state: 'visible', timeout: 30000 }).catch(() => {});

    // Click "Load more" until it's gone (Tilda store lazy-loads products).
    while (true) {
      const loadMore = page.locator('.js-store-load-more-btn').first();
      if (!(await loadMore.isVisible().catch(() => false))) break;
      await loadMore.click();
      await page.waitForTimeout(600);
    }

    cards = await page.locator('.t-store__card').all();
    if (cards.length > 0) break;

    const emptyMsgVisible = await page
      .locator('.js-store-empty-part-msg')
      .first()
      .isVisible()
      .catch(() => false);
    if (!emptyMsgVisible) {
      // Zero cards but no empty-state message either — doesn't match the
      // known transient-fetch-failure signature, so don't burn retries on
      // what's likely a genuinely different situation (e.g. selector miss).
      break;
    }
  }

  const products = [];

  for (const card of cards) {
    const title = (await card.locator('.t-store__card__title').first().innerText().catch(() => '')).trim();
    if (!title) continue;
    const description = (await card.locator('.t-store__card__descr').first().innerText().catch(() => '')).trim();
    const priceText = (await card.locator('.t-store__card__price-value').first().innerText().catch(() => '0'));
    const price = Math.round(parseFloat(priceText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0);
    const imgSrc = await extractImageSrc(card);

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

// Generic scraper for the site's static/promo pages (about, delivery info,
// stock, etc). Tilda pages don't use semantic <h1-3>/<p> for their real
// content — text lives in divs carrying the shared `.t-title` / `.t-text` /
// `.t-descr` classes regardless of which numbered block type rendered them
// (confirmed by inspecting the live DOM: raw HTML has zero <h1-3> and zero
// top-level <p> tags on /about, /contacts, /delivery-and-payment, /stock).
//
// Two more things the live DOM revealed that the naive selectors don't
// handle:
//   1. Every page embeds the same global "quick order" cart form and popup
//      markup (Tilda's shared store widget), which contains form-field
//      labels like "Ваше имя" / "Ваш телефон" that would otherwise pollute
//      every page's scraped text. We exclude it, along with the header menu
//      (.t228) and tooltip nav (.t978), which repeat the same nav text.
//   2. Tilda renders the same header/logo text 4-6 times in the DOM (one
//      copy per responsive breakpoint variant), so headings are deduped by
//      exact text, keeping the first occurrence.
async function scrapePage(page, slug) {
  // Same transient Tilda-fetch failure mode as scrapeCategory() can leave a
  // page's content blocks (or the global store-widget markup embedded on
  // every page) half-initialized on first load. There's no store-specific
  // empty-state message to key off here, so just retry a plain reload
  // whenever we come up with zero blocks. See scrapeCategory() for the
  // empirical basis of attempt count / backoff (store.tildacdn.com times
  // out on roughly 2 of every 5 requests in this environment).
  const maxAttempts = 8;
  let rawBlocks = [];
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt === 1) {
      await page.goto(`${SITE}/${slug}`, { waitUntil: 'load', timeout: 60000 });
    } else {
      console.log(`${slug}: retrying after empty result (attempt ${attempt})`);
      await page.waitForTimeout(4000 + Math.random() * 3000);
      await page.reload({ waitUntil: 'load', timeout: 60000 });
    }
    await page.waitForTimeout(1000);

    rawBlocks = await page.evaluate(() => {
    function cleanText(el) {
      // Tilda sometimes puts a per-button/per-block <style> tag as a
      // sibling inside the same element whose text we want; textContent
      // would otherwise include the raw CSS.
      const clone = el.cloneNode(true);
      clone.querySelectorAll('style, script').forEach((s) => s.remove());
      return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    const root = document.querySelector('#allrecords') || document.body;
    const NOISE_SELECTOR =
      'form, .t706, [class*="cartwin"], [class*="cartpage"], [id*="popup"], [class*="popup"], .t228, .t978';
    function isNoise(el) {
      return !!el.closest(NOISE_SELECTOR);
    }
    function extractBgSrc(el) {
      const original = el.getAttribute('data-original');
      if (original) return original;
      const style = el.getAttribute('style') || '';
      const match = style.match(/url\((['"]?)(.*?)\1\)/);
      return match ? match[2] : null;
    }

    const out = [];
    const seenHeadings = new Set();
    const nodes = root.querySelectorAll('.t-title, .t-text, .t-descr, img, [class*="bgimg"]');
    nodes.forEach((el) => {
      if (isNoise(el)) return;

      if (el.tagName === 'IMG') {
        // Tilda lazy-loads: `src` starts as a tiny placeholder/thumbnail,
        // the full-res URL lives in `data-original` (same pattern as the
        // store card thumbnails handled by extractImageSrc()).
        const src = el.getAttribute('data-original') || el.getAttribute('src');
        if (src && !src.toLowerCase().endsWith('.svg')) {
          out.push({ type: 'image', src, alt: el.getAttribute('alt') || '' });
        }
        return;
      }

      const cls = el.className ? el.className.toString() : '';
      if (cls.includes('bgimg')) {
        const src = extractBgSrc(el);
        if (src) out.push({ type: 'image', src, alt: '' });
        return;
      }

      const text = cleanText(el);
      if (!text) return;
      if (cls.includes('t-title')) {
        if (seenHeadings.has(text)) return;
        seenHeadings.add(text);
        out.push({ type: 'heading', text });
      } else {
        out.push({ type: 'paragraph', text });
      }
    });
    return out;
  });

    if (rawBlocks.length > 0) break;
  }

  // Responsive variants of the same block frequently reuse the exact same
  // image URL; dedupe by resolved absolute URL so we don't download/list it
  // twice under different index names.
  const seenImg = new Set();
  const blocks = [];
  let imgIndex = 0;
  for (const block of rawBlocks) {
    if (block.type !== 'image') {
      blocks.push(block);
      continue;
    }
    const abs = new URL(block.src, SITE).href;
    if (seenImg.has(abs)) continue;
    seenImg.add(abs);

    const ext = path.extname(new URL(abs).pathname) || '.jpg';
    const fileName = `img-${imgIndex++}${ext}`;
    const dest = path.join(ROOT, 'public', 'images', 'pages', slug, fileName);
    await downloadImage(abs, dest);
    blocks.push({ type: 'image', src: `/images/pages/${slug}/${fileName}`, alt: block.alt });
  }
  return blocks;
}

// Homepage scraper for the hero slider and (via main()) the "Новинки"
// section. Two things found by inspecting the live homepage DOM that the
// brief's naive selectors miss:
//   1. There is no <img> in the hero slides at all — like the store card
//      thumbnails, the cover photo is a `.t-cover__carrier` div with the
//      full-res URL in `data-content-cover-bg` (falling back to parsing the
//      inline `background-image` style), so `slide.querySelector('img')`
//      would return null for every slide and the brief's `if (title && img)`
//      guard would silently drop all of them.
//   2. The homepage actually contains *two* `.t734` slider blocks with
//      near-identical markup (`.t-slds__wrapper .t-slds__item`) — Tilda
//      renders one variant gated to `min-width:640px` and keeps a second,
//      `display:none`-at-desktop variant for other breakpoints, both in the
//      DOM at once. A selector with no visibility check pulls 15 slide
//      elements from both blocks. We keep only elements whose containing
//      `.t-rec` is actually visible (`offsetParent !== null`), which reduces
//      that to the 8 slides Edge actually renders at our scrape viewport.
//      That set still contains 2 looped/cloned slides at the end (the
//      slider library duplicates the first couple of slides for seamless
//      infinite scroll), so slides are also deduped by title+href.
async function scrapeHome(page) {
  // Same transient Tilda-fetch failure mode as scrapeCategory()/scrapePage()
  // can leave the slider half-initialized on first load; retry a plain
  // reload whenever we come up with zero slides.
  const maxAttempts = 8;
  let rawSlides = [];
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt === 1) {
      await page.goto(`${SITE}/`, { waitUntil: 'load', timeout: 60000 });
    } else {
      console.log(`home: retrying after empty result (attempt ${attempt})`);
      await page.waitForTimeout(4000 + Math.random() * 3000);
      await page.reload({ waitUntil: 'load', timeout: 60000 });
    }
    await page.waitForTimeout(1000);

    rawSlides = await page.evaluate(() => {
    function cleanText(el) {
      if (!el) return '';
      const clone = el.cloneNode(true);
      clone.querySelectorAll('style, script').forEach((s) => s.remove());
      return clone.textContent.replace(/\s+/g, ' ').trim();
    }
    function isVisible(el) {
      const rec = el.closest('.r.t-rec') || el;
      return rec.offsetParent !== null && getComputedStyle(rec).display !== 'none';
    }
    function extractCoverSrc(item) {
      const cover = item.querySelector('.t-cover__carrier');
      if (!cover) return null;
      const bg = cover.getAttribute('data-content-cover-bg');
      if (bg) return bg;
      const style = cover.getAttribute('style') || '';
      const match = style.match(/url\((['"]?)(.*?)\1\)/);
      return match ? match[2] : null;
    }

    // Note: deliberately NOT scoping to an ancestor `.t-slds__wrapper` — in
    // testing that descendant-combinator selector intermittently matched
    // zero elements after prior page navigations even though `.t-slds__item`
    // elements were confirmed present and attached (Tilda's slider JS
    // appears to rebuild/reparent the wrapper on re-init in a way that
    // doesn't always keep the wrapper class on a direct ancestor). Matching
    // `.t-slds__item` directly is reliable in both a fresh load and after
    // other pages were scraped first in the same browser session.
    const items = Array.from(
      document.querySelectorAll('.t-slds__item, .t954__slide')
    ).filter(isVisible);

    const slides = [];
    const seen = new Set();
    items.forEach((item) => {
      const title = cleanText(item.querySelector('.t954__title, .t734__title, [class*="title"]'));
      const subtitle = cleanText(item.querySelector('.t954__descr, .t734__descr, [class*="descr"]'));
      const btnTextEl = item.querySelector('.t-btnflex__text');
      const btnLink = btnTextEl ? btnTextEl.closest('a') : item.querySelector('a.t-btn, a[class*="btn"]');
      const buttonText = cleanText(btnTextEl) || cleanText(btnLink) || 'Подробнее';
      const buttonHref = btnLink?.getAttribute('href') || '/catalog';
      const image = extractCoverSrc(item);
      if (!title || !image) return;

      const key = `${title}|${buttonHref}`;
      if (seen.has(key)) return;
      seen.add(key);
      slides.push({ title, subtitle, buttonText, buttonHref, image });
    });
    return slides;
  });

    if (rawSlides.length > 0) break;
  }

  const heroSlides = [];
  for (const [i, slide] of rawSlides.entries()) {
    const abs = new URL(slide.image, SITE).href;
    const ext = path.extname(new URL(abs).pathname) || '.jpg';
    const fileName = `hero-${i}${ext}`;
    const dest = path.join(ROOT, 'public', 'images', 'site', fileName);
    await downloadImage(abs, dest);
    heroSlides.push({ ...slide, image: `/images/site/${fileName}` });
  }

  return { heroSlides };
}

const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'flowers', 'wedding', 'balloons',
  'chocolate', 'indoorflowers', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
];

const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
];

async function main() {
  // The playwright-managed Chromium download is unreachable from this
  // network (cdn.playwright.dev times out), so drive a system-installed
  // Chromium-based browser instead via Playwright's `channel` option.
  // Configurable via env var (Task 3 review feedback: don't hardcode one
  // browser) — defaults to Microsoft Edge, which is what's available here.
  const browserChannel = process.env.SCRAPE_BROWSER_CHANNEL || 'msedge';
  const browser = await chromium.launch({ channel: browserChannel });
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
  const site = JSON.parse(await readFile(siteJsonPath, 'utf-8'));
  site.heroSlides = heroSlides;

  const bukety = JSON.parse(
    await readFile(path.join(ROOT, 'data', 'catalog', 'bukety.json'), 'utf-8')
  );
  site.newProducts = bukety.slice(0, 3).map((p) => ({
    title: p.title,
    subtitle: p.description,
    price: p.price,
    image: p.images[0] || '',
  }));

  await writeFile(siteJsonPath, JSON.stringify(site, null, 2), 'utf-8');
  console.log(`home: ${heroSlides.length} hero slides, ${site.newProducts.length} new products`);

  await browser.close();
}

// Only auto-run when executed directly (`node scripts/scrape.mjs`), not when
// imported (e.g. by tests/smoke scripts importing scrapeCategory etc).
const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
  });
}
