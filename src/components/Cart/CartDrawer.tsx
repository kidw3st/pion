'use client';

import Image from 'next/image';
import { useCart } from './CartContext';
import styles from './CartDrawer.module.css';

export function CartDrawer() {
  const { items, total, isOpen, close, removeItem, setQuantity } = useCart();

  if (!isOpen) return null;

  return (
    <div className={styles.overlay} onClick={close}>
      <div className={styles.drawer} onClick={(e) => e.stopPropagation()}>
        <div className={styles.header}>
          <h3>Ваш заказ</h3>
          <button aria-label="Закрыть корзину" onClick={close}>×</button>
        </div>
        {items.length === 0 ? (
          <p className={styles.empty}>Корзина пуста</p>
        ) : (
          <ul className={styles.list}>
            {items.map((item) => (
              <li key={item.uid} className={styles.item}>
                <Image src={item.image} alt={item.title} width={64} height={64} />
                <div className={styles.itemInfo}>
                  <span>{item.title}</span>
                  <div className={styles.qtyRow}>
                    <button onClick={() => setQuantity(item.uid, item.quantity - 1)}>-</button>
                    <span>{item.quantity}</span>
                    <button onClick={() => setQuantity(item.uid, item.quantity + 1)}>+</button>
                  </div>
                </div>
                <span>{item.price * item.quantity} ₽</span>
                <button aria-label="Удалить" onClick={() => removeItem(item.uid)}>×</button>
              </li>
            ))}
          </ul>
        )}
        <div className={styles.footer}>
          <span>Итоговая сумма: {total} ₽</span>
          <a href="#checkout" className={styles.checkoutBtn} onClick={close}>Оформить заказ</a>
        </div>
      </div>
    </div>
  );
}
