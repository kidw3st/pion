/**
 * Converts every photo under public/images to WebP and repoints the code and
 * data at the new files.
 *
 * Two things make the originals heavy: they are JPEG/PNG straight from the
 * source site, and they are far larger than anywhere they are displayed — a
 * 360px product card was being handed a 2000px photo. So each image is capped
 * at a width that matches how it is actually used before it is re-encoded.
 *
 * The static export has no image-resizing server, so this is the only place
 * where that can happen.
 *
 *   node scripts/optimize-images.mjs           # convert and rewrite references
 *   node scripts/optimize-images.mjs --dry-run # report what it would do
 *
 * Originals are deleted once their references are rewritten; they stay
 * recoverable from git history.
 */
import { readdir, stat, readFile, writeFile, unlink } from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';

const ROOT = path.resolve(import.meta.dirname, '..');
const IMAGES = path.join(ROOT, 'public', 'images');
const DRY_RUN = process.argv.includes('--dry-run');

const QUALITY = 82;
/** No single photo should cost more than this. */
const BUDGET = 150 * 1024;

/**
 * Widest the image is ever painted, times two for high-density screens.
 * Anything wider is wasted bytes.
 */
function maxWidthFor(relPath) {
  if (relPath.startsWith('/images/catalog/')) return 900; // 360px cards
  if (relPath.includes('/catalog-tiles/')) return 900; // quarter-width tiles
  if (/\/(hero|cover)/.test(relPath)) return 1920; // full-bleed

  // Homepage furniture, matched under /images/site/ only. Matching on the bare
  // name would also catch /images/pages/uds/img-0, which is a full-bleed page
  // cover and needs every pixel it has.
  if (/^\/images\/site\/(uds|bouquet-block|new-)/.test(relPath)) return 900;
  if (/^\/images\/site\/vk-/.test(relPath)) return 600; // 215px band photos
  if (/^\/images\/site\/logo/.test(relPath)) return 500;

  return 1600;
}

/**
 * Encodes to WebP, giving up quality and then pixels until the file fits the
 * budget. Photographs of bouquets hold up well down to the high sixties; below
 * that the petals start to band, so width goes next instead.
 */
async function encodeWithinBudget(source, dest, cap) {
  const attempts = [
    { width: cap, quality: QUALITY },
    { width: cap, quality: 72 },
    { width: Math.round(cap * 0.8), quality: 72 },
    { width: Math.round(cap * 0.65), quality: 70 },
    { width: Math.round(cap * 0.5), quality: 68 },
  ];

  let size = Infinity;
  for (const attempt of attempts) {
    const { width } = await sharp(source).metadata();
    const pipeline =
      width && width > attempt.width ? sharp(source).resize({ width: attempt.width }) : sharp(source);
    await pipeline.webp({ quality: attempt.quality, effort: 5 }).toFile(dest);
    size = (await stat(dest)).size;
    if (size <= BUDGET) break;
  }
  return size;
}

async function walk(dir) {
  const found = [];
  for (const name of await readdir(dir)) {
    const full = path.join(dir, name);
    if ((await stat(full)).isDirectory()) found.push(...(await walk(full)));
    else found.push(full);
  }
  return found;
}

/** Public URL of a file on disk, e.g. /images/site/logo.png */
const toUrl = (abs) => '/' + path.relative(path.join(ROOT, 'public'), abs).split(path.sep).join('/');

const files = await walk(IMAGES);
const convertible = files.filter((f) => /\.(jpe?g|png)$/i.test(f));

/** old public URL -> new public URL */
const rewrites = new Map();
let before = 0;
let after = 0;

const existingWebp = new Set(files.filter((f) => f.toLowerCase().endsWith('.webp')));
const skipped = [];

for (const file of convertible) {
  const oldUrl = toUrl(file);
  const newFile = file.replace(/\.(jpe?g|png)$/i, '.webp');
  const newUrl = toUrl(newFile);

  // Some folders hold both foo.jpg and a foo.webp that came down from the
  // source site — different photos under one name. Converting would silently
  // overwrite the one the pages actually reference, so leave it alone and say
  // so rather than destroying it.
  if (existingWebp.has(newFile)) {
    skipped.push(oldUrl);
    continue;
  }

  const originalSize = (await stat(file)).size;
  before += originalSize;

  const cap = maxWidthFor(oldUrl);

  if (DRY_RUN) {
    const { width } = await sharp(file).metadata();
    console.log(`${oldUrl}  ${(originalSize / 1024).toFixed(0)}KB  ${width}px -> max ${cap}px`);
    rewrites.set(oldUrl, newUrl);
    continue;
  }

  const size = await encodeWithinBudget(file, newFile, cap);
  if (size > BUDGET) console.log(`still over budget: ${newUrl} ${(size / 1024).toFixed(0)}KB`);

  after += size;
  rewrites.set(oldUrl, newUrl);
}

// ------------------------------------------------------- repoint references
// Every path is a literal string in the data files and a handful of
// components, so a plain replace is enough — and it is verifiable: anything
// left pointing at a .jpg after this ran is a reference we failed to find.
const textFiles = [
  ...(await walk(path.join(ROOT, 'data'))),
  ...(await walk(path.join(ROOT, 'src'))),
];

let touched = 0;
for (const file of textFiles) {
  if (!/\.(json|tsx?|css)$/.test(file)) continue;
  const original = await readFile(file, 'utf-8');
  let updated = original;
  for (const [oldUrl, newUrl] of rewrites) updated = updated.split(oldUrl).join(newUrl);
  if (updated !== original) {
    if (!DRY_RUN) await writeFile(file, updated, 'utf-8');
    touched++;
  }
}

if (!DRY_RUN) {
  for (const file of convertible) {
    if (!skipped.includes(toUrl(file))) await unlink(file);
  }
}

if (skipped.length) {
  console.log(`\nleft as-is (a .webp of that name already exists):\n  ${skipped.join('\n  ')}`);
}

console.log(
  DRY_RUN
    ? `\ndry run: ${convertible.length} images, ${textFiles.length} text files scanned`
    : `\n${convertible.length} images: ${(before / 1048576).toFixed(1)} MB -> ${(after / 1048576).toFixed(1)} MB ` +
        `(${Math.round((1 - after / before) * 100)}% smaller), ${touched} files repointed`,
);
