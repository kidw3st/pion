'use client';

import { createContext, useContext, useEffect, useReducer, useState, type ReactNode } from 'react';
import { cartReducer, cartTotal, type CartItem, type CartState } from './cartReducer';

const STORAGE_KEY = 'pion-cart';

interface CartContextValue {
  items: CartItem[];
  total: number;
  isOpen: boolean;
  addItem: (item: Omit<CartItem, 'quantity'>) => void;
  removeItem: (uid: string) => void;
  setQuantity: (uid: string, quantity: number) => void;
  clear: () => void;
  open: () => void;
  close: () => void;
}

const CartContext = createContext<CartContextValue | null>(null);

export function CartProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(cartReducer, { items: [] } as CartState);
  const [isOpen, setIsOpen] = useState(false);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        const items = JSON.parse(raw) as CartItem[];
        items.forEach((item) => dispatch({ type: 'ADD_ITEM', item }));
        items.forEach((item) => {
          if (item.quantity > 1) dispatch({ type: 'SET_QUANTITY', uid: item.uid, quantity: item.quantity });
        });
      } catch {
        // ignore corrupt storage
      }
    }
    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state.items));
  }, [state.items, hydrated]);

  const value: CartContextValue = {
    items: state.items,
    total: cartTotal(state),
    isOpen,
    addItem: (item) => {
      dispatch({ type: 'ADD_ITEM', item });
      setIsOpen(true);
    },
    removeItem: (uid) => dispatch({ type: 'REMOVE_ITEM', uid }),
    setQuantity: (uid, quantity) => dispatch({ type: 'SET_QUANTITY', uid, quantity }),
    clear: () => dispatch({ type: 'CLEAR' }),
    open: () => setIsOpen(true),
    close: () => setIsOpen(false),
  };

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
