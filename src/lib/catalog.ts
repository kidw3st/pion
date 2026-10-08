/**
 * Каталог для страниц сайта — из снимка выгрузки data/catalog-export.json
 * (формат админки, спецификация, раздел 3). Правила показа — в
 * scripts/lib/catalog-view.mjs, их же используют служебные файлы сборки.
 *
 * Это отдельный модуль, а не content.ts: content.ts импортируют клиентские
 * компоненты (шапка, подвал, корзина), и тогда весь каталог попал бы в код,
 * который скачивает браузер.
 */
import exportJson from '../../data/catalog-export.json';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  newProducts,
  productPages,
  relatedProducts,
  sectionMeta,
  sectionProducts,
} from '../../scripts/lib/catalog-view.mjs';
import { PAGE_SLUGS } from './content';
import type { CatalogTile, CategoryMeta, Product } from './types';

export interface CatalogSection {
  slug: string;
  label: string;
  tileImage: string;
  visible: boolean;
  coverTitle: string;
  coverSub: string;
  covers: string[];
  heading: string;
  headingSub: string;
  hasNotFound: boolean;
  seoTitle: string | null;
  seoDescription: string | null;
  products: string[];
}

export interface CatalogProduct extends Product {
  mainSection: string;
  status: 'active' | 'hidden';
}

export interface CatalogExport {
  version: string;
  changedAt: string | null;
  sections: CatalogSection[];
  tiles: ({ type: 'section'; slug: string } | { type: 'link' | 'popup'; label: string; href: string; image: string })[];
  products: CatalogProduct[];
  redirects: { from: string; to: string }[];
}

const data = exportJson as unknown as CatalogExport;

// Вторая линия защиты (первая — проверка выгрузки в prebuild): раздел с
// адресом обычной страницы сайта перекрыл бы её, поэтому сборка
// останавливается.
for (const section of data.sections) {
  if ((PAGE_SLUGS as readonly string[]).includes(section.slug)) {
    throw new Error(`Раздел «${section.label}» (${section.slug}) совпадает со страницей сайта — сборка остановлена.`);
  }
}

export function getSectionSlugs(): string[] {
  return data.sections.map((s) => s.slug);
}

export function getSection(slug: string): CatalogSection | null {
  return findSection(data, slug) as CatalogSection | null;
}

export function getSectionLabel(slug: string): string {
  return getSection(slug)?.label ?? slug;
}

/** Букеты в продаже из раздела, в его порядке; null — такого раздела нет. */
export function getCatalog(slug: string): CatalogProduct[] | null {
  return getSection(slug) ? (sectionProducts(data, slug) as CatalogProduct[]) : null;
}

/** Букет по адресу страницы: главный раздел и slug. Снятые тоже. */
export function getProduct(section: string, slug: string): CatalogProduct | null {
  return findProduct(data, section, slug) as CatalogProduct | null;
}

export function getRelatedProducts(product: CatalogProduct, limit = 4): CatalogProduct[] {
  return relatedProducts(data, product, limit) as CatalogProduct[];
}

/** Все страницы букетов — для статической генерации и карты сайта. */
export function getAllProductParams(): { slug: string; product: string }[] {
  return productPages(data) as { slug: string; product: string }[];
}

export function getAmbiguousTitles(): Set<string> {
  return ambiguousTitles(data) as Set<string>;
}

export function getCatalogTiles(): CatalogTile[] {
  return catalogTiles(data) as CatalogTile[];
}

export function getCategoryMeta(slug: string): CategoryMeta | null {
  const section = getSection(slug);
  return section ? (sectionMeta(section) as CategoryMeta) : null;
}

/** «Новинки» для главной; null — раздела ещё нет (до этапа 3). */
export function getNewProducts(limit = 3): CatalogProduct[] | null {
  return newProducts(data, limit) as CatalogProduct[] | null;
}
