'use client';

import { useState } from 'react';
import { useCart } from '@/components/Cart/CartContext';
import { validateCheckout, type CheckoutValues } from './validateCheckout';
import styles from './CheckoutForm.module.css';

const DELIVERY_OPTIONS = [
  { value: '1', label: 'До 5км от нашего магазина - 500 рублей' },
  { value: '2', label: 'До 7км от нашего магазина - 800 рублей' },
  { value: '3', label: 'До 9км от нашего магазина - 950 рублей' },
  { value: 'pickup', label: 'Самовывоз из нашего салона по адресу ул.Газеты Звезда, 27. При самовывозе - скидка 5%' },
];

const initialValues: CheckoutValues = {
  name: '', email: '', phone: '', deliveryOption: '1', address: '', paymentMethod: '',
};

export function CheckoutForm() {
  const { items, total, clear } = useCart();
  const [values, setValues] = useState<CheckoutValues>(initialValues);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState(false);

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
          <li key={item.uid}>{item.title} × {item.quantity} — {item.price * item.quantity} ₽</li>
        ))}
      </ul>
      <p className={styles.total}>Сумма: {total} ₽</p>

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
        {DELIVERY_OPTIONS.map((opt) => (
          <label key={opt.value} className={styles.radioLabel}>
            <input
              type="radio"
              name="deliveryOption"
              value={opt.value}
              checked={values.deliveryOption === opt.value}
              onChange={() => update('deliveryOption', opt.value)}
            />
            {opt.label}
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
