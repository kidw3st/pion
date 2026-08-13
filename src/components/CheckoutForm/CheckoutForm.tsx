'use client';

import { useState } from 'react';
import { useCart } from '@/components/Cart/CartContext';
import { getSite } from '@/lib/content';
import { validateCheckout, type CheckoutValues } from './validateCheckout';
import { orderTotals } from './orderTotals';
import styles from './CheckoutForm.module.css';

// Delivery zones and prices come from data/site.json so the checkout, the
// delivery page and the agent-facing data can never quote different numbers.
const DELIVERY = getSite().delivery;

const initialValues: CheckoutValues = {
  name: '', email: '', phone: '', deliveryOption: DELIVERY.options[0].id, address: '', paymentMethod: '',
};

const rub = (value: number) => `${value.toLocaleString('ru-RU')} ₽`;

export function CheckoutForm() {
  const { items, total, clear } = useCart();
  const [values, setValues] = useState<CheckoutValues>(initialValues);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState(false);

  const chosenOption = DELIVERY.options.find((o) => o.id === values.deliveryOption) ?? null;
  const totals = orderTotals(total, chosenOption);

  function update<K extends keyof CheckoutValues>(key: K, value: CheckoutValues[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const result = validateCheckout(values);
    setErrors(result.errors);
    if (result.valid) {
      setSubmitted(true);
      clear();
    }
  }

  if (submitted) {
    return (
      <div className={styles.thanks}>
        <h2>Спасибо, мы с вами свяжемся!</h2>
        <p>Заказ принят. Наш флорист свяжется с вами для подтверждения деталей.</p>
      </div>
    );
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <h2>Оформить заказ</h2>

      <ul className={styles.summary}>
        {items.map((item) => (
          <li key={item.uid}>
            {item.title} × {item.quantity} — {rub(item.price * item.quantity)}
          </li>
        ))}
      </ul>

      <div className={styles.totals}>
        <div className={styles.totalsRow}>
          <span>Товары</span>
          <span>{rub(totals.goods)}</span>
        </div>
        <div className={styles.totalsRow}>
          <span>{chosenOption?.id === 'pickup' ? 'Самовывоз' : 'Доставка'}</span>
          <span>{totals.delivery === 0 ? 'бесплатно' : rub(totals.delivery)}</span>
        </div>
        {totals.discount > 0 && (
          <div className={styles.totalsRow}>
            <span>Скидка за самовывоз {chosenOption?.discountPercent}%</span>
            <span>−{rub(totals.discount)}</span>
          </div>
        )}
        <div className={styles.totalsFinal}>
          <span>Итого к оплате</span>
          <span>{rub(totals.total)}</span>
        </div>
        <p className={styles.totalsNote}>{DELIVERY.note}</p>
      </div>

      <label>
        Ваше имя
        <input value={values.name} onChange={(e) => update('name', e.target.value)} />
      </label>
      {errors.name && <span className={styles.error}>{errors.name}</span>}

      <label>
        Ваш Email
        <input type="email" value={values.email} onChange={(e) => update('email', e.target.value)} />
      </label>
      {errors.email && <span className={styles.error}>{errors.email}</span>}

      <label>
        Ваш телефон
        <input
          type="tel"
          placeholder="+7 (999) 999-9999"
          value={values.phone}
          onChange={(e) => update('phone', e.target.value)}
        />
      </label>
      {errors.phone && <span className={styles.error}>{errors.phone}</span>}

      <fieldset>
        <legend>Доставка</legend>
        {DELIVERY.options.map((opt) => (
          <label key={opt.id} className={styles.radioLabel}>
            <input
              type="radio"
              name="deliveryOption"
              value={opt.id}
              checked={values.deliveryOption === opt.id}
              onChange={() => update('deliveryOption', opt.id)}
            />
            <span>
              {opt.label}
              {' — '}
              <strong>
                {opt.priceRub === 0 ? 'бесплатно' : rub(opt.priceRub)}
                {opt.discountPercent ? `, скидка ${opt.discountPercent}%` : ''}
              </strong>
            </span>
          </label>
        ))}
      </fieldset>

      {values.deliveryOption !== 'pickup' && (
        <>
          <label>
            Адрес доставки
            <input value={values.address} onChange={(e) => update('address', e.target.value)} />
          </label>
          {errors.address && <span className={styles.error}>{errors.address}</span>}
        </>
      )}

      <fieldset>
        <legend>Способ оплаты</legend>
        <label className={styles.radioLabel}>
          <input
            type="radio"
            name="paymentMethod"
            value="card"
            checked={values.paymentMethod === 'card'}
            onChange={() => update('paymentMethod', 'card')}
          />
          Онлайн оплата картой
        </label>
        <label className={styles.radioLabel}>
          <input
            type="radio"
            name="paymentMethod"
            value="cash"
            checked={values.paymentMethod === 'cash'}
            onChange={() => update('paymentMethod', 'cash')}
          />
          Наличными при самовывозе
        </label>
      </fieldset>
      {errors.paymentMethod && <span className={styles.error}>{errors.paymentMethod}</span>}

      <button type="submit" className={styles.submitBtn}>Оформить заказ</button>
    </form>
  );
}
