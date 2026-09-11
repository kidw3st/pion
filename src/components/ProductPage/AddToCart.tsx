'use client';

import { useCart } from '@/components/Cart/CartContext';
import type { Product } from '@/lib/types';
import styles from './AddToCart.module.css';

/**
 * Кнопка покупки на странице товара. Кладёт в ту же корзину, что и карточки в
 * разделе (uid, название, цена, первое фото), и сразу открывает её — чтобы
 * было видно, что заказ принят.
 */
export function AddToCart({ product }: { product: Product }) {
  const { addItem, open } = useCart();

  return (
    <button
      type="button"
      className={styles.button}
      onClick={() => {
        addItem({
          uid: product.uid,
          title: product.title,
          price: product.price,
          image: product.images[0] || '',
        });
        open();
      }}
    >
      Добавить в корзину
    </button>
  );
}
