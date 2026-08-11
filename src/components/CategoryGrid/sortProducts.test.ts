import { describe, it, expect } from 'vitest';
import { sortProducts, type SortOrder } from './sortProducts';
import type { Product } from '@/lib/types';

const products: Product[] = [
  { uid: '1', title: 'Роза', description: '', price: 500, images: [], slug: 'roza' },
  { uid: '2', title: 'Астра', description: '', price: 1500, images: [], slug: 'astra' },
  { uid: '3', title: 'Ирис', description: '', price: 1000, images: [], slug: 'iris' },
];

describe('sortProducts', () => {
  it.each<[SortOrder, string[]]>([
    ['price-asc', ['Роза', 'Ирис', 'Астра']],
    ['price-desc', ['Астра', 'Ирис', 'Роза']],
    ['name-asc', ['Астра', 'Ирис', 'Роза']],
    ['name-desc', ['Роза', 'Ирис', 'Астра']],
  ])('sorts by %s', (order, expectedTitles) => {
    const sorted = sortProducts(products, order);
    expect(sorted.map((p) => p.title)).toEqual(expectedTitles);
  });

  it('does not mutate the input array', () => {
    const copy = [...products];
    sortProducts(products, 'price-desc');
    expect(products).toEqual(copy);
  });
});
