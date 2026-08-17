import { describe, it, expect } from 'vitest';
import { cartReducer, type CartState } from './cartReducer';

const emptyState: CartState = { items: [] };

describe('cartReducer', () => {
  it('adds a new item', () => {
    const state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет «Счастье есть»', price: 4830, image: '/images/a.jpg' },
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0]).toMatchObject({ uid: '1', quantity: 1 });
  });

  it('increments quantity when adding an existing item', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0].quantity).toBe(2);
  });

  it('removes an item', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, { type: 'REMOVE_ITEM', uid: '1' });
    expect(state.items).toHaveLength(0);
  });

  it('sets an explicit quantity, removing the item if set to 0', () => {
    let state = cartReducer(emptyState, {
      type: 'ADD_ITEM',
      item: { uid: '1', title: 'Букет', price: 4830, image: '/images/a.jpg' },
    });
    state = cartReducer(state, { type: 'SET_QUANTITY', uid: '1', quantity: 3 });
    expect(state.items[0].quantity).toBe(3);

    state = cartReducer(state, { type: 'SET_QUANTITY', uid: '1', quantity: 0 });
    expect(state.items).toHaveLength(0);
  });

  it('hydrates state from stored items (idempotent)', () => {
    const storedItems = [
      { uid: '1', title: 'Букет 1', price: 1000, image: '/img1.jpg', quantity: 2 },
      { uid: '2', title: 'Букет 2', price: 2000, image: '/img2.jpg', quantity: 1 },
    ];
    let state = cartReducer(emptyState, {
      type: 'HYDRATE',
      items: storedItems,
    });
    expect(state.items).toHaveLength(2);
    expect(state.items[0]).toMatchObject({ uid: '1', quantity: 2 });
    expect(state.items[1]).toMatchObject({ uid: '2', quantity: 1 });

    // Calling HYDRATE again should replace state, not double items (idempotent)
    state = cartReducer(state, {
      type: 'HYDRATE',
      items: storedItems,
    });
    expect(state.items).toHaveLength(2);
    expect(state.items[0].quantity).toBe(2);
  });
});

describe('HYDRATE with untrusted storage', () => {
  const good = { uid: '1', title: 'Букет', price: 4830, image: '/images/a.webp', quantity: 2 };
  const hydrate = (items: unknown) =>
    cartReducer({ items: [] }, { type: 'HYDRATE', items: items as never });

  it('drops a payload that is not an array at all', () => {
    expect(hydrate({ evil: true }).items).toEqual([]);
    expect(hydrate('[]').items).toEqual([]);
    expect(hydrate(null).items).toEqual([]);
  });

  it('keeps valid items and drops malformed neighbours', () => {
    const state = hydrate([
      good,
      { uid: '2', title: 'Без цены', image: '/i.webp', quantity: 1 },
      { uid: '3', title: 'Цена строкой', price: '100', image: '/i.webp', quantity: 1 },
      null,
      42,
    ]);
    expect(state.items).toEqual([good]);
  });

  it('rejects non-finite, negative and fractional numbers', () => {
    const state = hydrate([
      { ...good, uid: 'a', price: Infinity },
      { ...good, uid: 'b', price: -5 },
      { ...good, uid: 'c', quantity: 1.5 },
      { ...good, uid: 'd', quantity: 0 },
    ]);
    expect(state.items).toEqual([]);
  });

  it('caps a hand-edited quantity and deduplicates uids', () => {
    const state = hydrate([{ ...good, quantity: 1e9 }, { ...good, title: 'Дубль' }]);
    expect(state.items).toHaveLength(1);
    expect(state.items[0].quantity).toBe(99);
    expect(state.items[0].title).toBe('Букет');
  });
});
