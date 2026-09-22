'use client';

import { useState } from 'react';
import styles from './CheckoutForm.module.css';

/** Что сервер рассказал про покупателя и его баллы. */
export type UdsState = {
  token: string;
  name: string;
  tier: string;
  balance: number;
  maxPoints: number;
  /** Сколько баллов реально спишется — считает сервер, не браузер. */
  points: number;
  /** Итог к оплате деньгами после списания. */
  total: number;
  cashBack: number;
  note?: string;
};

type CartLine = { uid: string; quantity: number };

const rub = (value: number) => `${value.toLocaleString('ru-RU')} ₽`;

/**
 * Оплата бонусами UDS.
 *
 * Баланс и суммы приходят с сервера: он спрашивает UDS и считает заказ по
 * своему прайсу. Браузер ничего не вычисляет — иначе можно было бы
 * подобрать себе цену.
 *
 * Код из приложения нужен один раз: в ответ сервер даёт номерок, по которому
 * покупатель опознаётся дальше. Сам код живёт минуты и до возврата со
 * страницы банка не доживает.
 */
export function UdsPoints({
  items,
  delivery,
  value,
  onChange,
}: {
  items: CartLine[];
  delivery: string;
  value: UdsState | null;
  onChange: (state: UdsState | null) => void;
}) {
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function ask(body: Record<string, unknown>) {
    setBusy(true);
    setError('');
    try {
      const res = await fetch('/pay/uds-check.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          items: items.map((i) => ({ uid: i.uid, quantity: i.quantity })),
          delivery,
          ...body,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(data.error ?? 'Не получилось связаться с UDS');
        return;
      }
      onChange(data as UdsState);
    } catch {
      setError('Не получилось связаться с UDS');
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    onChange(null);
    setCode('');
    setError('');
  }

  if (!open) {
    return (
      <button type="button" className={styles.udsToggle} onClick={() => setOpen(true)}>
        Оплатить бонусами UDS
      </button>
    );
  }

  return (
    <div className={styles.uds}>
      {!value ? (
        <>
          <p className={styles.udsHint}>
            Откройте приложение UDS, нажмите «Списать баллы» и введите код, который там
            появится. Код действует несколько минут.
          </p>
          <div className={styles.udsRow}>
            <input
              value={code}
              inputMode="numeric"
              placeholder="Код из приложения"
              onChange={(e) => setCode(e.target.value)}
              aria-label="Код из приложения UDS"
            />
            <button
              type="button"
              onClick={() => (code.trim() ? ask({ code: code.trim() }) : setError('Введите код'))}
              disabled={busy}
            >
              {busy ? 'Проверяем…' : 'Проверить'}
            </button>
          </div>
        </>
      ) : (
        <>
          <p className={styles.udsHint}>
            {value.name ? `${value.name}, у вас ` : 'У вас '}
            <strong>{rub(value.balance)}</strong> бонусов
            {value.tier ? ` · уровень «${value.tier}»` : ''}.
            {value.maxPoints > 0
              ? ` На этот заказ можно потратить до ${rub(value.maxPoints)}.`
              : ' Потратить их на этот заказ не получится.'}
          </p>

          {value.maxPoints > 0 && (
            <div className={styles.udsRow}>
              <input
                type="number"
                min={0}
                max={value.maxPoints}
                step={1}
                value={value.points}
                onChange={(e) =>
                  onChange({ ...value, points: Math.max(0, Math.floor(Number(e.target.value) || 0)) })
                }
                onBlur={(e) =>
                  ask({
                    token: value.token,
                    points: Math.max(0, Math.floor(Number(e.target.value) || 0)),
                  })
                }
                aria-label="Сколько бонусов списать"
              />
              <button
                type="button"
                onClick={() => ask({ token: value.token, points: value.maxPoints })}
                disabled={busy}
              >
                {busy ? 'Считаем…' : 'Списать максимум'}
              </button>
            </div>
          )}

          {value.note && <p className={styles.udsHint}>{value.note}</p>}

          <button type="button" className={styles.udsToggle} onClick={reset}>
            Не списывать бонусы
          </button>
        </>
      )}

      {error && <span className={styles.error}>{error}</span>}
    </div>
  );
}
