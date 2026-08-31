import type { Product, PageSection, SiteData, CatalogTile, CategoryMeta } from './types';
import { dedupeProducts } from './dedupeProducts';
import siteJson from '../../data/site.json';
import catalogMetaJson from '../../data/catalog-meta.json';

// `flowers` and `indoorflowers` were originally listed as store categories,
// but live investigation (2026-08-11) found neither has a Tilda store-widget
// block (no `storepart`/`recid`/`t_store_init` anywhere in their HTML) — they
// are plain content pages, so they're scraped/served via the PAGE_SLUGS path
// instead.
// `valentinesday` and `new-year-2025` began life as scraped content pages:
// the first pass captured only their covers. On the live site both are store
// pages, so since 2026-08-17 they are categories fed from the Tilda export.
export const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'wedding', 'balloons',
  'chocolate', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
  'valentinesday', 'new-year-2025',
] as const;

export const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'doza_endorfina',
  'flowers', 'indoorflowers',
] as const;

export type CategorySlug = (typeof CATEGORY_SLUGS)[number];
export type PageSlug = (typeof PAGE_SLUGS)[number];

export const CATEGORY_LABELS: Record<CategorySlug, string> = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
  valentinesday: 'Букеты и боксы к 14 февраля',
  'new-year-2025': 'Новогодняя коллекция',
};

export function getSite(): SiteData {
  return siteJson as SiteData;
}

/** Photo tiles on the /catalog overview, scraped from the live grid. */
export function getCatalogTiles(): CatalogTile[] {
  return catalogMetaJson.tiles as CatalogTile[];
}

/** Cover/heading chrome for a category page; null for slugs without any. */
export function getCategoryMeta(slug: string): CategoryMeta | null {
  const all = catalogMetaJson.categories as Record<string, CategoryMeta>;
  return all[slug] ?? null;
}

/**
 * Whether to show only what a customer can actually buy.
 *
 * The imported catalogue holds everything the shop has in Tilda — 403 items —
 * of which roughly a quarter are switched on in the store and appear on
 * pionperm.ru. This is set to false on purpose: the site shows the full range,
 * including the items currently switched off. Set it to true to mirror the
 * shop instead; the data files carry every product either way.
 */
const ONLY_ACTIVE_IN_STORE = false;

export async function getCatalog(slug: string): Promise<Product[] | null> {
  if (!(CATEGORY_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/catalog/${slug}.json`);
  const all = mod.default as Product[];
  // Products imported before the `published` flag existed have none; treat a
  // missing flag as "on sale" rather than hiding them.
  const visible = ONLY_ACTIVE_IN_STORE ? all.filter((p) => p.published !== false) : all;
  // The store data is kept faithful; the duplicates and "Copy:" leftovers it
  // contains are filtered here, so a re-import cannot undo it.
  return dedupeProducts(visible);
}

export async function getPage(slug: string): Promise<PageSection[] | null> {
  if (!(PAGE_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/pages/${slug}.json`);
  return mod.default as PageSection[];
}
