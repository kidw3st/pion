import { chromium } from 'playwright';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { downloadImage } from './lib/downloadImage.mjs';

const SITE = 'https://pionperm.ru';
const ROOT = path.resolve(import.meta.dirname, '..');

// Fetch `url` with retries. Tilda's own backend (pionperm.ru and its
// store.tildacdn.com product API) is occasionally unreachable from this
// environment (confirmed live: intermittent `ConnectTimeoutError` /
// "fetch failed" on plain Node `fetch()` to store.tildacdn.com, roughly one
// in several requests) — considerably rarer than the old browser-click
// approach's failures, but not zero, so every network call here is wrapped
// in a short retry loop with a per-attempt timeout so a hung request can't
// stall the whole scrape.
async function fetchWithRetry(url, { attempts = 4, delayMs = 2500, timeoutMs = 15000 } = {}) {
  let lastErr;
  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      const res = await fetch(url, { signal: AbortSignal.timeout(timeoutMs) });
      if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
      return res;
    } catch (err) {
      lastErr = err;
      if (attempt < attempts) {
        console.log(`  retry ${attempt}/${attempts - 1} for ${url}: ${err.message}`);
        await new Promise((resolve) => setTimeout(resolve, delayMs));
      }
    }
  }
  throw lastErr;
}

function slugify(title) {
  return title
    .toLowerCase()
    .replace(/[«»"]/g, '')
    .replace(/[^a-zа-я0-9]+/gi, '-')
    .replace(/^-+|-+$/g, '');
}

// Scrape a Tilda store-widget category by calling its backend JSON API
// directly instead of driving a browser and clicking "Load more". This
// replaces the earlier Playwright/click-based implementation, which was
// hitting intermittent "Failed to fetch" errors inside Tilda's own
// store-widget JS (tilda-cart-1.1.min.js) that were indistinguishable from a
// genuinely empty category without repeated full page reloads.
//
// The category page's raw HTML (plain `fetch`, no browser) embeds the
// widget's init options as `options={recid:'...',storepart:'...',...}`,
// which are the two IDs the product-list API needs:
//   https://store.tildacdn.com/api/getproductslist/?storepartuid=<storepart>&recid=<recid>&slice=<N>
// `slice` pages through the catalog (36 products per page, confirmed live on
// `bukety`: slice=1 -> 36 products + {total:104,nextslice:2}). Not every
// CATEGORY_SLUGS page is backed by this widget at all — verified live for
// all 13 original candidate slugs, `flowers` and `indoorflowers` have no
// `storepart`/`recid` anywhere in their HTML — so this returns `null` for
// those and the caller falls back to `scrapePage()`.
//
// A handful of the confirmed store categories (`korziny`, `korobki`,
// `wedding`, `balloons`, `chocolate`, `luchshee`, `flame`) returned
// `total:0` from this API repeatedly across many separate calls, at
// different times, and that was independently corroborated by loading each
// page in a real browser and seeing Tilda's own "Ничего не найдено"
// (nothing found) empty-state render (or, for a couple, no store section
// render at all) — i.e. this matches the live site's actual current state,
// not a fetch failure (which throws, rather than returning valid JSON with
// total:0). Categories with genuinely zero products right now produce a
// correct empty array; see the task report for the full list.
export async function scrapeCategory(slug, categoryUrl = `${SITE}/${slug}`) {
  const html = await (await fetchWithRetry(categoryUrl)).text();
  const storepart = html.match(/storepart:'(\d+)'/)?.[1];
  const recid = html.match(/recid:'(\d+)'/)?.[1];
  if (!storepart || !recid) return null;

  const rawProducts = [];
  let slice = 1;
  let total = Infinity;
  const maxSlices = 200; // sanity cap so a pagination quirk can't loop forever
  for (let i = 0; i < maxSlices; i++) {
    const apiUrl = `https://store.tildacdn.com/api/getproductslist/?storepartuid=${storepart}&recid=${recid}&slice=${slice}`;
    const json = await (await fetchWithRetry(apiUrl)).json();
    if (slice === 1) total = Number(json.total) || 0;

    const batch = Array.isArray(json.products) ? json.products : [];
    if (batch.length === 0) break;
    rawProducts.push(...batch);

    if (!json.nextslice || rawProducts.length >= total) break;
    slice = json.nextslice;
  }

  const products = [];
  for (const item of rawProducts) {
    const title = (item.title || '').trim();
    if (!title) continue;
    const description = (item.descr || '').trim();
    const price = Math.round(parseFloat(item.price) || 0);
    const uid = String(item.uid);
    const productSlug = slugify(title);

    let gallery = [];
    try {
      gallery = JSON.parse(item.gallery || '[]');
    } catch {
      gallery = [];
    }
    const imgUrl = gallery[0]?.img;

    const images = [];
    if (imgUrl) {
      const ext = path.extname(new URL(imgUrl).pathname) || '.jpg';
      const fileName = `${productSlug}${ext}`;
      const dest = path.join(ROOT, 'public', 'images', 'catalog', slug, fileName);
      await downloadImage(imgUrl, dest);
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
        // the full-res URL lives in `data-original` (same lazy-load pattern
        // the old browser-based store-card scraper used to handle).
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

// `flowers` and `indoorflowers` were dropped from this list (and added to
// PAGE_SLUGS below) after live investigation found neither has a Tilda
// store-widget block — see scrapeCategory()'s doc comment and
// src/lib/content.ts for the same change on the app side.
const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'wedding', 'balloons',
  'chocolate', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
];

const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
  'flowers', 'indoorflowers',
];

async function main() {
  // The playwright-managed Chromium download is unreachable from this
  // network (cdn.playwright.dev times out), so drive a system-installed
  // Chromium-based browser instead via Playwright's `channel` option.
  // Configurable via env var (Task 3 review feedback: don't hardcode one
  // browser) — defaults to Microsoft Edge, which is what's available here.
  // Still needed for scrapePage()/scrapeHome(), which need a real rendered
  // DOM; scrapeCategory() no longer touches the browser at all.
  const browserChannel = process.env.SCRAPE_BROWSER_CHANNEL || 'msedge';
  const browser = await chromium.launch({ channel: browserChannel });
  const page = await browser.newPage();

  await mkdir(path.join(ROOT, 'data', 'catalog'), { recursive: true });
  await mkdir(path.join(ROOT, 'data', 'pages'), { recursive: true });
  for (const slug of CATEGORY_SLUGS) {
    const products = await scrapeCategory(slug);
    if (products === null) {
      // Defensive fallback: shouldn't trigger given the static
      // reclassification above, but if a category ever loses its store
      // widget, scrape it as a content page instead of erroring out.
      console.log(`${slug}: no store widget found, falling back to scrapePage()`);
      const blocks = await scrapePage(page, slug);
      await writeFile(
        path.join(ROOT, 'data', 'pages', `${slug}.json`),
        JSON.stringify(blocks, null, 2),
        'utf-8'
      );
      console.log(`${slug}: ${blocks.length} blocks (page fallback)`);
      continue;
    }
    await writeFile(
      path.join(ROOT, 'data', 'catalog', `${slug}.json`),
      JSON.stringify(products, null, 2),
      'utf-8'
    );
    console.log(`${slug}: ${products.length} products`);
  }

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
