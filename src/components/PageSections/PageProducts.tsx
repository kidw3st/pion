'use client';

import Image from 'next/image';
import { useCart } from '@/components/Cart/CartContext';
import styles from './PageSections.module.css';

/**
 * Goods that live on the page itself rather than in the Tilda store — the
 * indoor plants are published this way. They go into the same cart as
 * catalogue products, so the basket behaves identically wherever an item
 * was added from.
 */
export function PageProducts({
  items,
}: {
  items: { title: string; price: number; image: string | null }[];
}) {
  const { addItem, open } = useCart();

  const buy = (item: { title: string; price: number; image: string | null }) => {
    addItem({
      // No store id exists for these, so the name is the stable key.
      uid: `page-${item.title}`,
      title: item.title,
      price: item.price,
      image: item.image ?? '',
    });
    open();
  };

  return (
    <section className={styles.products}>
      <div className={styles.productsGrid}>
        {items.map((item) => (
          <div key={item.title} className={styles.product}>
            {item.image && (
              <div className={styles.productPhoto}>
                <Image src={item.image} alt={item.title} fill sizes="360px" className={styles.cover} />
              </div>
            )}
            <h3 className={styles.productTitle}>{item.title}</h3>
            <span className={styles.productPrice}>{item.price.toLocaleString('ru-RU')} р.</span>
            <button type="button" className={styles.productBtn} onClick={() => buy(item)}>
              Добавить в корзину
            </button>
          </div>
        ))}
      </div>
    </section>
  );
}
