/**
 * The store the catalogue is scraped from accumulates editing artefacts: items
 * left behind by Tilda's "duplicate product" button, which publish under the
 * copied name and compete with the original in search results.
 *
 * Two shapes of that show up:
 *  - a literal "Copy: …" prefix, which is never a real product name;
 *  - two live listings under one name, where one of them usually kept the photo
 *    and the composition while the other is a half-filled draft.
 *
 * This lives here rather than in src/ so the page rendering and the JSON the
 * site publishes for agents both clean the catalogue the same way. src/lib/
 * re-exports it with types.
 *
 * @typedef {{ uid: string, title: string, description: string, price: number, images: string[], slug: string }} CatalogProduct
 */

/** Names a product only ever has because it was duplicated in the store admin. */
const COPY_PREFIX = /^\s*copy\s*:/i;

/** @param {string} title */
function normaliseTitle(title) {
  return title
    .toLowerCase()
    .replace(/[«»"'`]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * How complete a listing is. Between two products sharing a name we keep the
 * fuller one: a photo matters most (a card without one is visibly broken),
 * then a composition, then a price.
 *
 * @param {CatalogProduct} product
 */
function completeness(product) {
  return (
    (product.images.length > 0 ? 1000 : 0) +
    Math.min(product.description.trim().length, 500) +
    (product.price > 0 ? 1 : 0)
  );
}

/**
 * @param {CatalogProduct[]} products
 * @returns {CatalogProduct[]}
 */
export function dedupeProducts(products) {
  /** @type {Map<string, CatalogProduct>} */
  const kept = new Map();
  /** @type {string[]} */
  const order = [];

  for (const product of products) {
    if (COPY_PREFIX.test(product.title)) continue;

    const key = normaliseTitle(product.title);
    if (key === '') continue;

    const existing = kept.get(key);
    if (!existing) {
      kept.set(key, product);
      order.push(key);
      continue;
    }

    // Ties keep the earlier listing, which is the one the shop has been
    // linking to for longer.
    if (completeness(product) > completeness(existing)) kept.set(key, product);
  }

  return order.map((key) => /** @type {CatalogProduct} */ (kept.get(key)));
}
