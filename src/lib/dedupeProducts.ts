import type { Product } from './types';
import { dedupeProducts as dedupe } from '../../scripts/lib/dedupeProducts.mjs';

/**
 * Drops the store's editing leftovers — "Copy: …" listings and second copies
 * of a product published under the same name. See the shared implementation in
 * scripts/lib/dedupeProducts.mjs, which make-catalog-export.mjs uses to build
 * the catalog snapshot (data/catalog-export.json) the site reads.
 */
export function dedupeProducts(products: Product[]): Product[] {
  return dedupe(products) as Product[];
}
