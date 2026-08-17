/**
 * Rebuilds the catalogue from Tilda's own CSV export.
 *
 * Why not the scraper: the published site sits behind Variti, which refuses
 * every request from this network, and its public product API only ever
 * returns what a store block publishes — which is why seven categories came
 * out empty. The shop's own export has all of it, including the products that
 * are currently switched off in the store.
 *
 *   node scripts/import-tilda-csv.mjs <full-export.csv> [published-export.csv]
 *
 * The optional second file is an export taken WITHOUT "выключенные товары":
 * anything missing from it is switched off in the shop, and is marked
 * `published: false` so the site can tell the difference.
 */
import { readFile, writeFile, mkdir, stat } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';
import { parseCsvRecords } from './lib/parseCsv.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const [, , fullPath, publishedPath] = process.argv;

if (!fullPath) {
  console.error('usage: node scripts/import-tilda-csv.mjs <full-export.csv> [published-export.csv]');
  process.exit(1);
}

/**
 * Tilda's section names to our category pages. A product often sits in a
 * parent section and a child one ("Цветы;Розы"); the most specific mapped
 * section wins, so a rose lands on /roses rather than in the mixed bunch.
 */
const SECTION_TO_SLUG = [
  ['Пионы', 'pions'],
  ['Розы', 'roses'],
  ['Другие цветы', 'mixflower'],
  ['Букет невесты', 'wedding'],
  ['Корзины', 'korziny'],
  ['Коробки', 'korobki'],
  ['Шары', 'balloons'],
  ['Шоколад', 'chocolate'],
  ['Flame - территория ароматов', 'flame'],
  ['Вазы', 'luchshee'],
  ['Цветы в интерьере', 'luchshee'],
  ['Лучшее для дома', 'luchshee'],
  ['Букеты', 'bukety'],
  ['Цветы', 'mixflower'],
];

/** Seasonal sections that have their own page rather than a catalogue slug. */
const SKIP_SECTIONS = new Set(['Новогодняя коллекция 2025', '14 февраля']);

/**
 * The shop repeats one disclaimer in the "Text" tab of most products. It is
 * shop-wide small print, not a description of the bouquet, and our product
 * popup already prints it — so it must not end up as a card's composition.
 */
const DISCLAIMER = /не все цветы из состава сейчас в наличии/i;

const MAX_PHOTOS = 4;
const PHOTO_BUDGET = 150 * 1024;
const PHOTO_WIDTH = 900;

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

function sectionSlug(categoryField) {
  const sections = categoryField.split(';').map((s) => s.trim()).filter(Boolean);
  if (sections.length && sections.every((s) => SKIP_SECTIONS.has(s))) return null;
  for (const [name, slug] of SECTION_TO_SLUG) {
    if (sections.includes(name)) return slug;
  }
  return null;
}

/** Downloads a photo and stores it as WebP inside the same budget the site uses elsewhere. */
async function savePhoto(url, dest) {
  // Re-running the import should not re-fetch hundreds of photos.
  try {
    const { size } = await stat(dest);
    if (size > 0) return size;
  } catch {
    /* not downloaded yet */
  }

  const res = await fetch(url, { signal: AbortSignal.timeout(60000) });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const input = Buffer.from(await res.arrayBuffer());

  for (const [width, quality] of [[PHOTO_WIDTH, 82], [PHOTO_WIDTH, 72], [700, 70], [560, 68]]) {
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

// --------------------------------------------------------------------- read
const fullRows = parseCsvRecords(await readFile(fullPath, 'utf-8'));
const parents = fullRows.filter((r) => !r['Parent UID'].trim());

// A product with variants carries no price of its own; the cheapest variant is
// the "from" price the card should show.
const variantPrices = new Map();
for (const row of fullRows) {
  const parent = row['Parent UID'].trim();
  if (!parent) continue;
  const price = Math.round(parseFloat(row.Price) || 0);
  if (!price) continue;
  const seen = variantPrices.get(parent);
  if (seen === undefined || price < seen) variantPrices.set(parent, price);
}

let publishedUids = null;
if (publishedPath) {
  const rows = parseCsvRecords(await readFile(publishedPath, 'utf-8'));
  publishedUids = new Set(rows.map((r) => r['Tilda UID']).filter(Boolean));
}

// ------------------------------------------------------------------ convert
const byCategory = new Map();
const skipped = new Map();

for (const row of parents) {
  const title = row.Title.trim();
  if (!title) continue;

  const slug = sectionSlug(row.Category);
  if (!slug) {
    const label = row.Category.trim() || '(без раздела)';
    skipped.set(label, (skipped.get(label) ?? 0) + 1);
    continue;
  }

  const uid = row['Tilda UID'].trim();
  const price = Math.round(parseFloat(row.Price) || 0) || variantPrices.get(uid) || 0;

  const composition = row.Description.trim();
  const fallback = row.Text.trim();
  const description = composition || (DISCLAIMER.test(fallback) ? '' : fallback);

  if (!byCategory.has(slug)) byCategory.set(slug, []);
  byCategory.get(slug).push({
    uid,
    title,
    description,
    price,
    photoUrls: row.Photo.split(/\s+/).filter((u) => u.startsWith('http')).slice(0, MAX_PHOTOS),
    slug: slugify(title),
    published: publishedUids ? publishedUids.has(uid) : true,
  });
}

// ------------------------------------------------------------------写 write
let downloaded = 0;
let failed = 0;

for (const [category, items] of byCategory) {
  const dir = path.join(ROOT, 'public', 'images', 'catalog', category);
  await mkdir(dir, { recursive: true });

  const products = [];
  for (const item of items) {
    const images = [];
    for (const [i, url] of item.photoUrls.entries()) {
      // The uid keeps two products with the same name from sharing a file.
      const name = `${item.slug}-${item.uid}${i ? `-${i}` : ''}.webp`;
      try {
        await savePhoto(url, path.join(dir, name));
        images.push(`/images/catalog/${category}/${name}`);
        downloaded++;
      } catch (err) {
        failed++;
        console.log(`  photo failed (${item.title}): ${String(err).split('\n')[0]}`);
      }
    }

    products.push({
      uid: item.uid,
      title: item.title,
      description: item.description,
      price: item.price,
      images,
      slug: item.slug,
      published: item.published,
    });
  }

  await writeFile(
    path.join(ROOT, 'data', 'catalog', `${category}.json`),
    JSON.stringify(products, null, 2) + '\n',
    'utf-8',
  );
  const hidden = products.filter((p) => !p.published).length;
  console.log(`${category.padEnd(12)} ${String(products.length).padStart(4)} товаров  (выключено в Tilda: ${hidden})`);
}

console.log(`\nфото: ${downloaded} загружено, ${failed} не удалось`);
if (skipped.size) {
  console.log('\nразделы без страницы в нашем каталоге (не переносил):');
  for (const [name, n] of skipped) console.log(`  ${String(n).padStart(4)}  ${name}`);
}
