'use client';

import { Gallery } from '@/components/Gallery/Gallery';
import { useCart } from '@/components/Cart/CartContext';
import type { Product } from '@/lib/types';
import styles from './ProductCard.module.css';

export function ProductCard({ product, isNew = false }: { product: Product; isNew?: boolean }) {
  const { addItem } = useCart();

  return (
    <div className={styles.card}>
      <div className={styles.imageWrap}>
        {/* Букеты с витрины снимают с нескольких сторон — карусель показывает
            все кадры прямо в карточке: своей страницы у них нет. */}
        <Gallery
          images={product.images}
          alt={product.title}
          sizes="(max-width: 900px) 50vw, 300px"
          compact
        />
        {isNew && <span className={styles.badge}>NEW</span>}
      </div>
      <h3 className={styles.title}>{product.title}</h3>
      <p className={styles.description}>{product.description}</p>
      <span className={styles.price}>{product.price.toLocaleString('ru-RU')} р.</span>
      <button
        type="button"
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
