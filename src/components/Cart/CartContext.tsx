'use client';

import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useState, type ReactNode } from 'react';
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
        dispatch({ type: 'HYDRATE', items });
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

  const addItem = useCallback((item: Omit<CartItem, 'quantity'>) => {
    dispatch({ type: 'ADD_ITEM', item });
    setIsOpen(true);
  }, []);

  const removeItem = useCallback((uid: string) => {
    dispatch({ type: 'REMOVE_ITEM', uid });
  }, []);

  const setQuantity = useCallback((uid: string, quantity: number) => {
    dispatch({ type: 'SET_QUANTITY', uid, quantity });
  }, []);

  const clear = useCallback(() => {
    dispatch({ type: 'CLEAR' });
  }, []);

  const open = useCallback(() => {
    setIsOpen(true);
  }, []);

  const close = useCallback(() => {
    setIsOpen(false);
  }, []);

  const value = useMemo<CartContextValue>(() => ({
    items: state.items,
    total: cartTotal(state),
    isOpen,
    addItem,
    removeItem,
    setQuantity,
    clear,
    open,
    close,
  }), [state.items, isOpen, addItem, removeItem, setQuantity, clear, open, close]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
