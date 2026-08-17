import type { Product } from './types';
import { dedupeProducts as dedupe } from '../../scripts/lib/dedupeProducts.mjs';

/**
 * Drops the store's editing leftovers — "Copy: …" listings and second copies
 * of a product published under the same name. See the shared implementation in
 * scripts/lib/dedupeProducts.mjs, which the agent-facing JSON uses too so the
 * pages and the published catalogue never disagree about what exists.
 */
export function dedupeProducts(products: Product[]): Product[] {
  return dedupe(products) as Product[];
}
