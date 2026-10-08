/**
 * Собирает товарный фид для 2ГИС и Яндекс Карт из тех же данных, что и
 * страницы каталога: public/feed/products.xml (YML) и public/feed/products.csv
 * (тот же список по образцу 2ГИС — для загрузки файлом). Запускается в
 * prebuild, поэтому фид обновляется при каждой выкладке сайта; 2ГИС
 * перечитывает его сам.
 *
 *   node scripts/build-feed.mjs
 *
 * Адрес фида для личного кабинета 2ГИС: https://pionperm.ru/feed/products.xml
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { catalogTiles, sectionProducts } from './lib/catalog-view.mjs';
import { buildCsv, buildYml, feedCategories } from './lib/yml-feed.mjs';

const readJson = (file) => JSON.parse(readFileSync(file, 'utf8'));

/**
 * @param {{root: string, siteUrl: string, date: string}} input
 * @returns {{yml: string, csv: string}} YML-документ и CSV-файл
 */
export function buildFeed({ root, siteUrl, date }) {
  // Каталог — снимок выгрузки, тот же, что читают страницы.
  const catalog = readJson(path.join(root, 'data', 'catalog-export.json'));
  const sections = new Set(catalog.sections.map((s) => s.slug));
  const flowersPage = readJson(path.join(root, 'data', 'pages', 'flowers.json'));
  const flowerTiles = flowersPage.find((block) => block.kind === 'tiles')?.tiles ?? [];

  const categories = feedCategories({ catalogTiles: catalogTiles(catalog), flowerTiles, sections });
  // Те же товары, что видит покупатель: букеты в продаже, каждый один раз —
  // в категории своего главного раздела (букет может стоять в нескольких).
  const products = categories
    .filter((category) => category.section)
    .flatMap((category) =>
      sectionProducts(catalog, category.section)
        .filter((product) => product.mainSection === category.section)
        .map((product) => ({ ...product, section: category.section })),
    );
  return {
    yml: buildYml({ siteUrl, date, categories, products }),
    csv: buildCsv({ siteUrl, categories, products }),
  };
}

/**
 * Время по Перми (UTC+5, без перехода на летнее) в классическом виде YML:
 * 2026-10-05 20:00. Вариант с «T» и поясом стандарт тоже допускает, но
 * классический понимает любой разборщик фидов.
 */
export function feedDate(now) {
  return new Date(now.getTime() + 5 * 3600 * 1000).toISOString().slice(0, 16).replace('T', ' ');
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const { yml, csv } = buildFeed({
    root,
    siteUrl: process.env.SITE_URL || 'https://pionperm.ru',
    date: feedDate(new Date()),
  });
  mkdirSync(path.join(root, 'public', 'feed'), { recursive: true });
  writeFileSync(path.join(root, 'public', 'feed', 'products.xml'), yml);
  writeFileSync(path.join(root, 'public', 'feed', 'products.csv'), csv);
  console.log(`[feed] public/feed/products.xml и products.csv: товаров ${(yml.match(/<offer /g) ?? []).length}`);
}
