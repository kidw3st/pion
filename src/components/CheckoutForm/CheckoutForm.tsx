'use client';

import { useEffect, useState } from 'react';
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
  const [sending, setSending] = useState(false);
  const [serverError, setServerError] = useState('');
  // Возврат с платёжной страницы банка: /checkout/?payment=success|fail
  const [paymentResult, setPaymentResult] = useState<'success' | 'fail' | null>(null);

  useEffect(() => {
    const param = new URLSearchParams(window.location.search).get('payment');
    if (param === 'success') {
      setPaymentResult('success');
      clear();
    } else if (param === 'fail') {
      setPaymentResult('fail');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const chosenOption = DELIVERY.options.find((o) => o.id === values.deliveryOption) ?? null;
  const totals = orderTotals(total, chosenOption);

  function update<K extends keyof CheckoutValues>(key: K, value: CheckoutValues[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const result = validateCheckout(values);
    setErrors(result.errors);
    if (!result.valid || sending) return;

    setSending(true);
    setServerError('');
    try {
      const res = await fetch('/pay/init.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          items: items.map((i) => ({ uid: i.uid, quantity: i.quantity })),
          delivery: values.deliveryOption,
          payment: values.paymentMethod,
          customer: {
            name: values.name,
            phone: values.phone,
            email: values.email,
            address: values.address,
          },
        }),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        setServerError(data.error ?? 'Не получилось отправить заказ — позвоните нам: +7 342 258 45 45');
        return;
      }

      if (data.paymentUrl) {
        // Корзина сохраняется до подтверждения оплаты: с неудачной оплаты
        // человек возвращается к заполненной корзине, а не к пустой.
        window.location.assign(data.paymentUrl);
        return;
      }

      setSubmitted(true);
      clear();
    } catch {
      setServerError('Не получилось отправить заказ — позвоните нам: +7 342 258 45 45');
    } finally {
      setSending(false);
    }
  }

  if (paymentResult === 'success') {
    return (
      <div className={styles.thanks}>
        <h1>Оплата прошла, спасибо!</h1>
        <p>Заказ оплачен и передан флористам. Мы свяжемся с вами для уточнения деталей доставки.</p>
      </div>
    );
  }

  if (submitted) {
    return (
      <div className={styles.thanks}>
        <h1>Спасибо, заказ принят!</h1>
        <p>Наш флорист свяжется с вами для подтверждения деталей.</p>
      </div>
    );
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit}>
      <h1>Оформить заказ</h1>

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
          Картой онлайн — безопасная оплата через Т-Банк
        </label>
        <label className={styles.radioLabel}>
          <input
            type="radio"
            name="paymentMethod"
            value="cash"
            checked={values.paymentMethod === 'cash'}
            onChange={() => update('paymentMethod', 'cash')}
          />
          Наличными или картой при получении
        </label>
      </fieldset>
      {errors.paymentMethod && <span className={styles.error}>{errors.paymentMethod}</span>}

      {paymentResult === 'fail' && (
        <p className={styles.error}>
          Оплата не прошла. Попробуйте ещё раз или позвоните нам: +7 342 258 45 45.
        </p>
      )}
      {serverError && <p className={styles.error}>{serverError}</p>}

      <button type="submit" className={styles.submitBtn} disabled={sending}>
        {sending ? 'Отправляем…' : 'Оформить заказ'}
      </button>

      {/* 152-ФЗ: форма собирает имя, телефон и адрес — согласие обязательно. */}
      <p className={styles.consent}>
        Нажимая «Оформить заказ», вы даёте согласие на обработку персональных данных и
        соглашаетесь с{' '}
        <a href="/policy" className={styles.consentLink}>
          политикой конфиденциальности
        </a>
        .
      </p>
    </form>
  );
}
