import type { Product, ContentBlock, SiteData } from './types';
import siteJson from '../../data/site.json';

export const CATEGORY_SLUGS = [
  'bukety', 'korziny', 'korobki', 'flowers', 'wedding', 'balloons',
  'chocolate', 'indoorflowers', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
] as const;

export const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
] as const;

export type CategorySlug = (typeof CATEGORY_SLUGS)[number];
export type PageSlug = (typeof PAGE_SLUGS)[number];

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
