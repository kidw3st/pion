import type { Product, ContentBlock, SiteData } from './types';
import siteJson from '../../data/site.json';

// `flowers` and `indoorflowers` were originally listed as store categories,
// but live investigation (2026-08-11) found neither has a Tilda store-widget
// block (no `storepart`/`recid`/`t_store_init` anywhere in their HTML) — they
// are plain content pages, so they're scraped/served via the PAGE_SLUGS path
// instead.
export const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'wedding', 'balloons',
  'chocolate', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
] as const;

export const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
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
};

export function getSite(): SiteData {
  return siteJson as SiteData;
}

export async function getCatalog(slug: string): Promise<Product[] | null> {
  if (!(CATEGORY_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/catalog/${slug}.json`);
  return mod.default as Product[];
}

export async function getPage(slug: string): Promise<ContentBlock[] | null> {
  if (!(PAGE_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/pages/${slug}.json`);
  return mod.default as ContentBlock[];
}
