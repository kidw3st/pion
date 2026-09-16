'use client';

import { Gallery } from '@/components/Gallery/Gallery';
import { useCart } from '@/components/Cart/CartContext';
import { EVENING_PERCENT, isShowcaseUid } from '@/lib/promo';
import { useEveningDiscount } from '@/lib/useEveningDiscount';
import type { Product } from '@/lib/types';
import styles from './ProductCard.module.css';

export function ProductCard({ product, isNew = false }: { product: Product; isNew?: boolean }) {
  const { addItem } = useCart();
  // Вечерняя скидка — только на витрину: у постоянного каталога цена своя.
  // В корзину кладём цену по прайсу: скидку считает сервер при оформлении,
  // иначе она снялась бы дважды.
  const evening = useEveningDiscount() && isShowcaseUid(product.uid);
  const price = evening
    ? product.price - Math.round((product.price * EVENING_PERCENT) / 100)
    : product.price;

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
      <span className={styles.price}>
        {price.toLocaleString('ru-RU')} р.
        {evening && (
          <s className={styles.priceWas}>{product.price.toLocaleString('ru-RU')} р.</s>
        )}
      </span>
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
