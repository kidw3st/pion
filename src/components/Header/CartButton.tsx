'use client';

import { useCart } from '@/components/Cart/CartContext';
import styles from './CartButton.module.css';

export function CartButton() {
  const { items, open } = useCart();
  const count = items.reduce((sum, item) => sum + item.quantity, 0);

  return (
    <button type="button" className={styles.cartBtn} onClick={open} aria-label="Открыть корзину">
      <span>КОРЗИНА</span>
      {count > 0 && <span className={styles.badge}>{count}</span>}
    </button>
  );
}
