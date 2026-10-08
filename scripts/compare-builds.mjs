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
