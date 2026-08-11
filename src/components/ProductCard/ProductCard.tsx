'use client';

import Image from 'next/image';
import { useCart } from '@/components/Cart/CartContext';
import type { Product } from '@/lib/types';
import styles from './ProductCard.module.css';

export function ProductCard({ product }: { product: Product }) {
  const { addItem } = useCart();

  return (
    <div className={styles.card}>
      <div className={styles.imageWrap}>
        {product.images[0] && (
          <Image src={product.images[0]} alt={product.title} fill className={styles.image} />
        )}
      </div>
      <h3 className={styles.title}>{product.title}</h3>
      <p className={styles.description}>{product.description}</p>
      <span className={styles.price}>{product.price.toLocaleString('ru-RU')} ₽</span>
      <button
        className={styles.addBtn}
        onClick={() =>
          addItem({
            uid: product.uid,
            title: product.title,
            price: product.price,
            image: product.images[0] || '',
          })
        }
      >
        Добавить в корзину
      </button>
    </div>
  );
}
