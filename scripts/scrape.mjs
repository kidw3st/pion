import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
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
  // Tilda pages keep background connections open (analytics, chat widgets),
  // so `networkidle` frequently times out even once the page is usable.
  // `load` + waiting for the first product card is more reliable.
  await page.goto(categoryUrl, { waitUntil: 'load', timeout: 60000 });
  await page.locator('.t-store__card').first().waitFor({ state: 'visible', timeout: 30000 }).catch(() => {});

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

async function main() {
  // The playwright-managed Chromium download is unreachable from this
  // network (cdn.playwright.dev times out), so drive the system-installed
  // Microsoft Edge (Chromium-based) instead via Playwright's `channel` option.
  const browser = await chromium.launch({ channel: 'msedge' });
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
