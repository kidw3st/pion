/**
 * Данные сайта, кроме каталога: тексты страниц и настройки (site.json).
 * Каталог — в src/lib/catalog.ts: этот модуль импортируют клиентские
 * компоненты, а каталог в браузер не нужен.
 */
import type { PageSection, SiteData } from './types';
import siteJson from '../../data/site.json';

export const PAGE_SLUGS = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'doza_endorfina',
  'flowers', 'indoorflowers',
] as const;

export type PageSlug = (typeof PAGE_SLUGS)[number];

export function getSite(): SiteData {
  return siteJson as SiteData;
}

export async function getPage(slug: string): Promise<PageSection[] | null> {
  if (!(PAGE_SLUGS as readonly string[]).includes(slug)) return null;
  const mod = await import(`../../data/pages/${slug}.json`);
  return mod.default as PageSection[];
}
