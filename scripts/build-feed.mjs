/**
 * Собирает товарный фид public/feed/products.xml для 2ГИС и Яндекс Карт из
 * тех же данных, что и страницы каталога. Запускается в prebuild, поэтому
 * фид обновляется при каждой выкладке сайта; 2ГИС перечитывает его сам.
 *
 *   node scripts/build-feed.mjs
 *
 * Адрес фида для личного кабинета 2ГИС: https://pionperm.ru/feed/products.xml
 */
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { dedupeProducts } from './lib/dedupeProducts.mjs';
import { buildYml, feedCategories } from './lib/yml-feed.mjs';

const readJson = (file) => JSON.parse(readFileSync(file, 'utf8'));

/**
 * @param {{root: string, siteUrl: string, date: string}} input
 * @returns {string} YML-документ
 */
export function buildFeed({ root, siteUrl, date }) {
  const catalogDir = path.join(root, 'data', 'catalog');
  const sections = new Set(
    readdirSync(catalogDir)
      .filter((f) => f.endsWith('.json'))
      .map((f) => f.replace(/\.json$/, '')),
  );
  const meta = readJson(path.join(root, 'data', 'catalog-meta.json'));
  const flowersPage = readJson(path.join(root, 'data', 'pages', 'flowers.json'));
  const flowerTiles = flowersPage.find((block) => block.kind === 'tiles')?.tiles ?? [];

  const categories = feedCategories({ catalogTiles: meta.tiles, flowerTiles, sections });
  // Те же товары, что видит покупатель: без дублей и «Copy:» из выгрузки Tilda.
  const products = categories
    .filter((category) => category.section)
    .flatMap((category) =>
      dedupeProducts(readJson(path.join(catalogDir, `${category.section}.json`))).map((product) => ({
        ...product,
        section: category.section,
      })),
    );
  return buildYml({ siteUrl, date, categories, products });
}

/** Время по Перми (UTC+5, без перехода на летнее) в формате YML: 2026-10-05T20:00+05:00. */
function nowInPerm() {
  return new Date(Date.now() + 5 * 3600 * 1000).toISOString().slice(0, 16) + '+05:00';
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const yml = buildFeed({ root, siteUrl: process.env.SITE_URL || 'https://pionperm.ru', date: nowInPerm() });
  mkdirSync(path.join(root, 'public', 'feed'), { recursive: true });
  writeFileSync(path.join(root, 'public', 'feed', 'products.xml'), yml);
  console.log(`[feed] public/feed/products.xml: товаров ${(yml.match(/<offer /g) ?? []).length}`);
}
