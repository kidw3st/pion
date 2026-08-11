import type { Product } from '@/lib/types';

export type SortOrder = 'default' | 'price-asc' | 'price-desc' | 'name-asc' | 'name-desc';

export function sortProducts(products: Product[], order: SortOrder): Product[] {
  const copy = [...products];
  switch (order) {
    case 'price-asc':
      return copy.sort((a, b) => a.price - b.price);
    case 'price-desc':
      return copy.sort((a, b) => b.price - a.price);
    case 'name-asc':
      return copy.sort((a, b) => a.title.localeCompare(b.title, 'ru'));
    case 'name-desc':
      return copy.sort((a, b) => b.title.localeCompare(a.title, 'ru'));
    default:
      return copy;
  }
}
