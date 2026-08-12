import { describe, it, expect } from 'vitest';
import { filterProducts, priceBounds, type ProductFilter } from './filterProducts';
import type { Product } from '@/lib/types';

const product = (uid: string, title: string, price: number, description = ''): Product => ({
  uid,
  title,
  description,
  price,
  images: [],
  slug: uid,
});

const catalog: Product[] = [
  product('1', 'Букет «Розовый рассвет»', 4000, 'Состав: розы, эустома'),
  product('2', 'Букет «Пионовое облако»', 9000, 'Состав: пионы, гортензия'),
  product('3', 'Букет «Солнечный»', 15000, 'Состав: подсолнухи, ромашки'),
];

const empty: ProductFilter = { query: '', minPrice: null, maxPrice: null };

describe('filterProducts', () => {
  it('returns everything when no filter is set', () => {
    expect(filterProducts(catalog, empty)).toHaveLength(3);
  });

  it('matches the query against the title', () => {
    const result = filterProducts(catalog, { ...empty, query: 'пионовое' });
    expect(result.map((p) => p.uid)).toEqual(['2']);
  });

  it('matches the query against the composition, not just the title', () => {
    const result = filterProducts(catalog, { ...empty, query: 'ромашки' });
    expect(result.map((p) => p.uid)).toEqual(['3']);
  });

  it('ignores case and surrounding spaces in the query', () => {
    expect(filterProducts(catalog, { ...empty, query: '  РОЗЫ  ' }).map((p) => p.uid)).toEqual(['1']);
  });

  it('keeps prices within an inclusive range', () => {
    const result = filterProducts(catalog, { ...empty, minPrice: 4000, maxPrice: 9000 });
    expect(result.map((p) => p.uid)).toEqual(['1', '2']);
  });

  it('applies query and price together', () => {
    const result = filterProducts(catalog, { ...empty, query: 'букет', maxPrice: 5000 });
    expect(result.map((p) => p.uid)).toEqual(['1']);
  });

  it('returns an empty list when nothing matches', () => {
    expect(filterProducts(catalog, { ...empty, query: 'орхидея' })).toEqual([]);
  });

  it('does not mutate the input array', () => {
    const before = [...catalog];
    filterProducts(catalog, { ...empty, maxPrice: 5000 });
    expect(catalog).toEqual(before);
  });
});

describe('priceBounds', () => {
  it('reports the cheapest and dearest product', () => {
    expect(priceBounds(catalog)).toEqual({ min: 4000, max: 15000 });
  });

  it('falls back to zero for an empty catalogue', () => {
    expect(priceBounds([])).toEqual({ min: 0, max: 0 });
  });
});
