'use client';

import { useState } from 'react';
import Image from 'next/image';
import { useCart } from '@/components/Cart/CartContext';
import { BouquetBuilderPopup } from '@/components/BouquetBuilder/BouquetBuilderPopup';
import type { Product } from '@/lib/types';
import { sortProducts, type SortOrder } from './sortProducts';
import styles from './CategoryGrid.module.css';

const PAGE_SIZE = 36;

/**
 * Store card matching the live catalog card: photo, 20px title, grey
 * composition line, "N р." price, then two stacked buttons — a filled
 * "Описание" opening the product popup and an outlined "Купить похожий"
 * that adds the product to the cart.
 */
function StoreCard({
  product,
  onShow,
  onBuy,
}: {
  product: Product;
  onShow: () => void;
  onBuy: () => void;
}) {
  return (
    <div className={styles.card}>
      <button type="button" className={styles.cardPhotoBtn} onClick={onShow}>
        {product.images[0] && (
          <Image src={product.images[0]} alt={product.title} fill sizes="360px" className={styles.cardPhoto} />
        )}
      </button>
      <h3 className={styles.cardTitle}>{product.title}</h3>
      {product.description && <p className={styles.cardDescr}>{product.description}</p>}
      <span className={styles.cardPrice}>{product.price.toLocaleString('ru-RU')} р.</span>
      <div className={styles.cardButtons}>
        <button type="button" className={styles.btnPrimary} onClick={onShow}>
          Описание
        </button>
        <button type="button" className={styles.btnSecondary} onClick={onBuy}>
          Купить похожий
        </button>
      </div>
    </div>
  );
}

/** Product detail popup: photo beside title, price and composition, with an add-to-cart button. */
function ProductPopup({ product, onClose, onBuy }: { product: Product; onClose: () => void; onBuy: () => void }) {
  return (
    <div className={styles.popupOverlay} onClick={onClose} role="dialog" aria-modal="true">
      <div className={styles.popup} onClick={(e) => e.stopPropagation()}>
        <button type="button" className={styles.popupClose} onClick={onClose} aria-label="Закрыть">
          ×
        </button>
        <div className={styles.popupPhoto}>
          {product.images[0] && (
            <Image src={product.images[0]} alt={product.title} fill sizes="50vw" className={styles.cardPhoto} />
          )}
        </div>
        <div className={styles.popupBody}>
          <h3 className={styles.popupTitle}>{product.title}</h3>
          <span className={styles.popupPrice}>{product.price.toLocaleString('ru-RU')} р.</span>
          {product.description && <p className={styles.popupDescr}>{product.description}</p>}
          <p className={styles.popupNote}>
            Возможно, не все цветы из состава сейчас в наличии, либо не сезон. Согласуем с вами все
            изменения после оформления заказа и сделаем намного лучше!
          </p>
          <button type="button" className={styles.btnPrimary} onClick={onBuy}>
            Добавить в корзину
          </button>
        </div>
      </div>
    </div>
  );
}

export function CategoryGrid({
  products,
  title,
  subtitle,
  showNotFoundBand = false,
}: {
  products: Product[];
  title: string;
  subtitle: string;
  showNotFoundBand?: boolean;
}) {
  const [order, setOrder] = useState<SortOrder>('default');
  const [visible, setVisible] = useState(PAGE_SIZE);
  const [shown, setShown] = useState<Product | null>(null);
  const [builderOpen, setBuilderOpen] = useState(false);
  const { addItem, open } = useCart();

  const sorted = sortProducts(products, order);
  const slice = sorted.slice(0, visible);

  const buy = (p: Product) => {
    addItem({ uid: p.uid, title: p.title, price: p.price, image: p.images[0] || '' });
    setShown(null);
    open();
  };

  return (
    <>
      {(title || subtitle) && (
        <section className={styles.heading}>
          {title && <h1 className={styles.headingTitle}>{title}</h1>}
          {subtitle && <p className={styles.headingSub}>{subtitle}</p>}
        </section>
      )}

      <section className={styles.store}>
        {sorted.length === 0 ? (
          <p className={styles.empty}>В этой категории сейчас нет товаров</p>
        ) : (
          <div className={styles.inner}>
            <div className={styles.toolbar}>
              <label className={styles.sortLabel}>
                Порядок:{' '}
                <select
                  className={styles.sortSelect}
                  value={order}
                  onChange={(e) => setOrder(e.target.value as SortOrder)}
                >
                  <option value="default">По умолчанию</option>
                  <option value="price-asc">Цена: по возрастанию</option>
                  <option value="price-desc">Цена: по убыванию</option>
                  <option value="name-asc">Название: А—Я</option>
                  <option value="name-desc">Название: Я—А</option>
                </select>
              </label>
            </div>

            <div className={styles.grid}>
              {slice.map((p) => (
                <StoreCard key={p.uid} product={p} onShow={() => setShown(p)} onBuy={() => buy(p)} />
              ))}
            </div>

            {visible < sorted.length && (
              <div className={styles.moreWrap}>
                <button
                  type="button"
                  className={styles.moreBtn}
                  onClick={() => setVisible((v) => v + PAGE_SIZE)}
                >
                  Загрузить еще
                </button>
              </div>
            )}
          </div>
        )}
      </section>

      {showNotFoundBand && (
        <section className={styles.notFound}>
          <div className={styles.notFoundInner}>
            <p className={styles.notFoundText}>
              Не нашли, что искали?
              <br />
              Мы создадим уникальный букет согласно вашим пожеланиям!
            </p>
            <button type="button" className={styles.notFoundBtn} onClick={() => setBuilderOpen(true)}>
              Заказать
            </button>
          </div>
        </section>
      )}

      {shown && <ProductPopup product={shown} onClose={() => setShown(null)} onBuy={() => buy(shown)} />}
      <BouquetBuilderPopup open={builderOpen} onClose={() => setBuilderOpen(false)} />
    </>
  );
}
