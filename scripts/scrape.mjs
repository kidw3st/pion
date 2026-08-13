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
      // Include `uid` in the filename, not just the title-derived slug: the
      // catalog has several distinct products (different uid, different
      // photo) that share an identical title (e.g. multiple "Букет «Для
      // любимой»" listings), which would otherwise collide on the same
      // `${productSlug}${ext}` path — and since downloadImage() skips
      // already-downloaded files, the second product would silently reuse
      // the first one's photo instead of getting its own.
      const ext = path.extname(new URL(imgUrl).pathname) || '.jpg';
      const fileName = `${productSlug}-${uid}${ext}`;
      const dest = path.join(ROOT, 'public', 'images', 'catalog', slug, fileName);
      await downloadImage(imgUrl, dest);
      images.push(`/images/catalog/${slug}/${fileName}`);
    }

    products.push({ uid, title, description, price, images, slug: productSlug });
  }

  return products;
}

// Scrape a static/promo page as a list of composed sections rather than a flat
// run of headings, paragraphs and images.
//
// The live pages are built from Tilda "records" (.r.t-rec), each of which is a
// designed block: a cover with the page title over a photo, a text section, a
// row of people cards, a pull quote beside a photo. Flattening those into
// heading/paragraph/image in document order threw the composition away — every
// image rendered full width, one under another, which made our copies roughly
// three times taller than the originals.
//
// The VK band and the footer (record type 396) repeat on every page and are
// rendered by our layout, so they are skipped here.
async function scrapePage(page, slug) {
  const maxAttempts = 8;
  let raw = [];

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    if (attempt === 1) {
      await page.goto(`${SITE}/${slug}`, { waitUntil: 'load', timeout: 60000 });
    } else {
      console.log(`${slug}: retrying after empty result (attempt ${attempt})`);
      await page.waitForTimeout(4000 + Math.random() * 3000);
      await page.reload({ waitUntil: 'load', timeout: 60000 });
    }
    await page.waitForTimeout(1200);

    // Lazy-loaded images only expose their real src once scrolled into view.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 110));
      }
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(1000);

    raw = await page.evaluate(() => {
      const visible = (el) => el && el.offsetParent !== null;

      // innerText normalised per line: keeps the deliberate line breaks in
      // headings without gluing words together the way collapsing whitespace
      // across the whole string does.
      const lines = (el) => {
        if (!el) return [];
        return (el.innerText || '')
          .split('\n')
          .map((l) => l.replace(/[^\S\n]+/g, ' ').trim())
          .filter(Boolean);
      };
      const textOf = (el) => lines(el).join('\n');

      const coverSrc = (el) => {
        const bg = el.getAttribute('data-content-cover-bg');
        if (bg) return bg;
        const m = (el.getAttribute('style') || '').match(/url\((['"]?)(.*?)\1\)/);
        return m ? m[2] : null;
      };

      // Picks the file a background element actually shows.
      //
      // `data-original` is the untouched upload, which is the right choice when
      // the block displays the whole picture. But where the design crops — the
      // team portraits are a 260x440 cut from a group photo — the original is a
      // different image entirely, so the served crop is what must be taken.
      // Tilda marks those with `/-/cover/` in the optimised URL.
      const paintedSrc = (el) => {
        const shown = (getComputedStyle(el).backgroundImage.match(/url\((['"]?)(.*?)\1\)/) || [])[2];
        if (shown && shown.includes('/-/cover/')) return shown;
        return el.getAttribute('data-original') || shown || null;
      };

      const out = [];
      const records = [...document.querySelectorAll('.r.t-rec')].filter(
        (r) => visible(r) && r.getBoundingClientRect().height > 60,
      );

      for (const rec of records) {
        const recordType = rec.getAttribute('data-record-type');
        if (recordType === '396') continue;

        // The contacts block (t718) is a form beside the salon's details, not
        // a run of prose — read its fields directly rather than flattening it.
        if (recordType === '718') {
          const text = lines(rec);
          // Take the visible label, not the href: the link target is digits
          // only ("+79082413741"), while the page shows it spaced for reading.
          const linkText = (sel) => (rec.querySelector(sel)?.textContent || '').trim();
          const tel = linkText('a[href^="tel:"]');
          const mail = linkText('a[href^="mailto:"]');
          const vk = [...rec.querySelectorAll('a')]
            .map((a) => a.getAttribute('href') || '')
            .find((h) => h.includes('vk.com')) || '';
          const address = text.find((l) => /Пермь/.test(l)) || '';
          const hoursStart = text.findIndex((l) => /Режим работы/i.test(l));
          const hours = hoursStart === -1 ? '' : text.slice(hoursStart, hoursStart + 3).join('\n');
          // Intro is what sits between the heading and the first contact detail.
          const intro = text
            .slice(1)
            .filter((l) => !/^\+?\d|@|Пермь|Режим работы|^пн|^сб|ОТПРАВИТЬ|Нажимая/i.test(l))
            .slice(0, 2)
            .join('\n');

          out.push({
            kind: 'contacts',
            title: text[0] || 'Контакты',
            intro,
            phone: tel,
            email: mail,
            address,
            hours,
            vkHref: vk,
          });
          continue;
        }

        const covers = [...rec.querySelectorAll('.t-cover__carrier')].map(coverSrc).filter(Boolean);

        // Photos come two ways. Some blocks use a real <img>; the text-and-photo
        // blocks (t480) paint theirs as a CSS background on a div, with the
        // full-size file in data-original — looking only at <img> lost every
        // one of those, which is why /stock, /uds and /delivery had no photos.
        const tagged = [...rec.querySelectorAll('img')]
          .filter((i) => i.getBoundingClientRect().width > 120)
          .map((i) => i.getAttribute('data-original') || i.src);
        const painted = [...rec.querySelectorAll('[data-original], [class*="bgimg"]')]
          .filter((e) => e.getBoundingClientRect().width > 120)
          .map(paintedSrc);
        const photos = [...new Set([...tagged, ...painted])].filter(
          (s) => s && !s.toLowerCase().endsWith('.svg'),
        );

        const titleEl = rec.querySelector('.t-title, [class*="__title"], h1, h2');
        const bodyEls = [...rec.querySelectorAll('.t-descr, .t-text, [class*="__descr"]')].filter(
          (e) => visible(e) && textOf(e),
        );
        const title = textOf(titleEl);
        const body = [...new Set(bodyEls.map(textOf))].join('\n\n');

        if (covers.length) {
          out.push({ kind: 'cover', title, subtitle: body, images: [...new Set(covers)] });
          continue;
        }

        // A people row repeats a short name + role pair several times over.
        const cardEls = [...rec.querySelectorAll('[class*="col"], [class*="item"]')].filter((c) => {
          const l = lines(c);
          return l.length === 2 && l[0].length < 40 && l[1].length < 40;
        });
        if (cardEls.length >= 2) {
          const seen = new Set();
          const items = [];
          for (const c of cardEls) {
            const [t, s] = lines(c);
            const key = `${t}|${s}`;
            if (seen.has(key)) continue;
            seen.add(key);

            // Portraits are painted as backgrounds too, same as the t480
            // blocks — an <img> lookup alone leaves every card photoless.
            const shot = [...c.querySelectorAll('[data-original], [class*="bgimg"]')].find(
              (e) => e.getBoundingClientRect().width > 60,
            );
            const image =
              (shot ? paintedSrc(shot) : null) ||
              c.querySelector('img')?.getAttribute('data-original') ||
              c.querySelector('img')?.src ||
              null;

            items.push({ title: t, subtitle: s, image: image || undefined });
          }
          if (items.length >= 2) {
            out.push({ kind: 'cards', items });
            continue;
          }
        }

        // A block carrying both copy and a photo is a textImage, not a choice
        // between the two — picking one used to silently drop the other.
        if (photos.length === 1 && body) {
          out.push({ kind: 'textImage', title, body, image: photos[0] });
          continue;
        }
        if (photos.length === 1 && title) {
          out.push({ kind: 'quote', text: title, image: photos[0] });
          continue;
        }
        if (photos.length > 1) {
          out.push({ kind: 'gallery', images: photos, title, body });
          continue;
        }
        if (title || body) out.push({ kind: 'text', title, body });
      }

      return out;
    });

    if (raw.length > 0) break;
  }

  // Pull referenced images local, de-duplicating by resolved URL so a photo
  // reused across sections is downloaded once.
  const localFor = new Map();
  let index = 0;
  const localise = async (src) => {
    const abs = new URL(src, SITE).href;
    if (localFor.has(abs)) return localFor.get(abs);
    const ext = path.extname(new URL(abs).pathname) || '.jpg';
    const fileName = `img-${index++}${ext}`;
    await downloadImage(abs, path.join(ROOT, 'public', 'images', 'pages', slug, fileName));
    const local = `/images/pages/${slug}/${fileName}`;
    localFor.set(abs, local);
    return local;
  };

  const sections = [];
  for (const section of raw) {
    if (section.kind === 'cover' || section.kind === 'gallery') {
      const images = [];
      for (const src of section.images) images.push(await localise(src));
      sections.push({ ...section, images });
    } else if (section.kind === 'cards') {
      const items = [];
      for (const item of section.items) {
        items.push(item.image ? { ...item, image: await localise(item.image) } : item);
      }
      sections.push({ ...section, items });
    } else if (section.kind === 'quote' || section.kind === 'textImage') {
      sections.push({ ...section, image: await localise(section.image) });
    } else {
      sections.push(section);
    }
  }

  return sections;
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
    // Slide headings are deliberately multi-line (Tilda authors break them with
    // <br>), and the break is part of the design — collapsing it both changes
    // the block's height and glues words together ("подпискаот салона").
    // innerText reflects what is actually rendered, line breaks included.
    function cleanText(el) {
      if (!el) return '';
      const raw = el.innerText ?? el.textContent ?? '';
      return raw
        .split('\n')
        .map((line) => line.replace(/[^\S\n]+/g, ' ').trim())
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
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

  // `node scripts/scrape.mjs home` refreshes only the homepage, which is quick
  // — useful when a fix only affects the hero and re-running every category and
  // page would take far longer for no benefit.
  const onlyHome = process.argv[2] === 'home';

  await mkdir(path.join(ROOT, 'data', 'catalog'), { recursive: true });
  await mkdir(path.join(ROOT, 'data', 'pages'), { recursive: true });
  for (const slug of onlyHome ? [] : CATEGORY_SLUGS) {
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

  for (const slug of onlyHome ? [] : PAGE_SLUGS) {
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
