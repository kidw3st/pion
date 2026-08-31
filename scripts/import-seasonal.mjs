/**
 * Доимпорт двух сезонных витрин из выгрузки Tilda: «Новогодняя коллекция
 * 2025» и «14 февраля». На живом сайте это страницы-магазины; при первом
 * копировании скрейпер снял только их обложки. Товары идут в отдельные
 * catalog-файлы — существующие 11 разделов не трогаются.
 *
 *   node scripts/import-seasonal.mjs <full-export.csv> [published-export.csv]
 */
import { readFile, writeFile, mkdir, stat } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';
import { parseCsvRecords } from './lib/parseCsv.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const [, , fullPath, publishedPath] = process.argv;

if (!fullPath) {
  console.error('usage: node scripts/import-seasonal.mjs <full-export.csv> [published-export.csv]');
  process.exit(1);
}

const SEASONS = [
  { section: 'Новогодняя коллекция 2025', slug: 'new-year-2025' },
  { section: '14 февраля', slug: 'valentinesday' },
];

const MAX_PHOTOS = 4;
const PHOTO_BUDGET = 150 * 1024;
const DISCLAIMER = /не все цветы из состава сейчас в наличии/i;

function slugify(title) {
  const map = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
    й: 'i', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
    у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y',
    ь: '', э: 'e', ю: 'yu', я: 'ya',
  };
  return title
    .toLowerCase()
    .split('')
    .map((ch) => map[ch] ?? ch)
    .join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60) || 'tovar';
}

async function savePhoto(url, dest) {
  try {
    const { size } = await stat(dest);
    if (size > 0) return size;
  } catch {
    /* ещё не скачано */
  }
  const res = await fetch(url, { signal: AbortSignal.timeout(60000) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const input = Buffer.from(await res.arrayBuffer());
  for (const [width, quality] of [[900, 82], [900, 72], [700, 70], [560, 68]]) {
    const meta = await sharp(input).metadata();
    const pipe = meta.width && meta.width > width ? sharp(input).resize({ width }) : sharp(input);
    const out = await pipe.webp({ quality, effort: 5 }).toBuffer();
    if (out.length <= PHOTO_BUDGET || width === 560) {
      await writeFile(dest, out);
      return out.length;
    }
  }
  return 0;
}

const rows = parseCsvRecords(await readFile(fullPath, 'utf-8'));
const parents = rows.filter((r) => !r['Parent UID'].trim());

// У товара с вариантами своей цены нет — берём цену самого дешёвого варианта.
const variantPrices = new Map();
for (const row of rows) {
  const parent = row['Parent UID'].trim();
  if (!parent) continue;
  const price = Math.round(parseFloat(row.Price) || 0);
  if (!price) continue;
  const seen = variantPrices.get(parent);
  if (seen === undefined || price < seen) variantPrices.set(parent, price);
}

let publishedUids = null;
if (publishedPath) {
  const pub = parseCsvRecords(await readFile(publishedPath, 'utf-8'));
  publishedUids = new Set(pub.map((r) => r['Tilda UID']).filter(Boolean));
}

for (const { section, slug } of SEASONS) {
  const hit = parents.filter((r) =>
    r.Category.split(';').map((s) => s.trim()).includes(section),
  );

  const dir = path.join(ROOT, 'public', 'images', 'catalog', slug);
  await mkdir(dir, { recursive: true });

  const products = [];
  let downloaded = 0;
  let failed = 0;

  for (const row of hit) {
    const title = row.Title.trim();
    if (!title) continue;
    const uid = row['Tilda UID'].trim();
    const price = Math.round(parseFloat(row.Price) || 0) || variantPrices.get(uid) || 0;
    const productSlug = slugify(title);

    const composition = row.Description.trim();
    const fallback = row.Text.trim();
    const description = composition || (DISCLAIMER.test(fallback) ? '' : fallback);

    const urls = row.Photo.split(/\s+/).filter((u) => u.startsWith('http')).slice(0, MAX_PHOTOS);
    const images = [];
    for (const [i, url] of urls.entries()) {
      const name = `${productSlug}-${uid}${i ? `-${i}` : ''}.webp`;
      try {
        await savePhoto(url, path.join(dir, name));
        images.push(`/images/catalog/${slug}/${name}`);
        downloaded++;
      } catch (err) {
        failed++;
        console.log(`  фото не скачалось (${title}): ${String(err).split('\n')[0]}`);
      }
    }

    products.push({
      uid,
      title,
      description,
      price,
      images,
      slug: productSlug,
      published: publishedUids ? publishedUids.has(uid) : true,
    });
  }

  await writeFile(
    path.join(ROOT, 'data', 'catalog', `${slug}.json`),
    JSON.stringify(products, null, 2) + '\n',
    'utf-8',
  );
  console.log(
    `${slug.padEnd(14)} ${String(products.length).padStart(3)} товаров, фото: ${downloaded} ок / ${failed} мимо`,
  );
}
