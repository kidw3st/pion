import type { Product } from '@/lib/types';

export type ProductFilter = {
  /** Free text matched against the title and the composition line. */
  query: string;
  minPrice: number | null;
  maxPrice: number | null;
};

/**
 * Narrows a catalogue by budget and free text. The text is matched against the
 * composition as well as the title, because shoppers search by flower —
 * "пионы", "ромашки" — and that word usually appears only in the composition.
 */
export function filterProducts(products: Product[], filter: ProductFilter): Product[] {
  const query = filter.query.trim().toLowerCase();

  return products.filter((product) => {
    if (filter.minPrice !== null && product.price < filter.minPrice) return false;
    if (filter.maxPrice !== null && product.price > filter.maxPrice) return false;
    if (!query) return true;

    const haystack = `${product.title} ${product.description}`.toLowerCase();
    return haystack.includes(query);
  });
}

/** Cheapest and dearest price in a catalogue, used to seed the budget inputs. */
export function priceBounds(products: Product[]): { min: number; max: number } {
  if (products.length === 0) return { min: 0, max: 0 };
  const prices = products.map((p) => p.price);
  return { min: Math.min(...prices), max: Math.max(...prices) };
}
