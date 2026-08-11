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
});
