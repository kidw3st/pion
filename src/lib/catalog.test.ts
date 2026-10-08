import { describe, expect, it } from 'vitest';
import { PAGE_SLUGS } from './content';
import { RESERVED_SLUGS } from '../../scripts/lib/catalog-export.mjs';
import {
  getAllProductParams,
  getCatalog,
  getCatalogTiles,
  getCategoryMeta,
  getNewProducts,
  getProduct,
  getSectionLabel,
  getSectionSlugs,
} from './catalog';

describe('каталог для страниц', () => {
  it('страницы сайта входят в занятые адреса — раздел их не займёт', () => {
    for (const slug of [...PAGE_SLUGS, 'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000']) {
      expect(RESERVED_SLUGS).toContain(slug);
    }
  });

  it('разделы и плитки из снимка', () => {
    expect(getSectionSlugs()).toHaveLength(13);
    expect(getSectionLabel('korobki')).toBe('Коробки с цветами');
    expect(getCatalogTiles()).toHaveLength(12);
    expect(getCategoryMeta('bukety')?.heading).toBe('БУКЕТЫ');
    expect(getCategoryMeta('nope')).toBeNull();
    expect(getCatalog('nope')).toBeNull();
  });

  it('в разделе нет снятых; у снятой коробки страница есть', () => {
    expect(getCatalog('korobki')!.every((p) => p.status === 'active' && p.price > 0)).toBe(true);
    const box = getAllProductParams().find((p) => p.slug === 'korobki' && getProduct('korobki', p.product)?.status === 'hidden');
    expect(box).toBeDefined();
    expect(getAllProductParams()).toHaveLength(487);
  });

  it('раздела «Новинки» в снимке ещё нет — главная берёт их из site.json', () => {
    expect(getNewProducts()).toBeNull();
  });
});
