'use client';

import { useState } from 'react';
import { validateBouquetStep, type BouquetValues } from './validateBouquetForm';
import styles from './BouquetBuilder.module.css';

const STEPS = [
  'Тип букета', 'Цветовая гамма', 'Размер', 'Бюджет', 'Сроки и подарки', 'Комментарий', 'Контакты',
];

const initialValues: BouquetValues = {
  bouquetType: '', colorScheme: '', size: '', budget: 10000,
  readyBy: '', extras: '', comment: '', name: '', phone: '', email: '',
};

export function BouquetBuilderPopup({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [step, setStep] = useState(0);
  const [values, setValues] = useState<BouquetValues>(initialValues);
  const [error, setError] = useState<string | undefined>();
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState(false);

  if (!open) return null;

  function update<K extends keyof BouquetValues>(key: K, value: BouquetValues[K]) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function handleNext() {
    const result = validateBouquetStep(step, values);
    if (!result.valid) {
      if (step === 6 && result.errors) {
        setFieldErrors(result.errors);
        setError(undefined);
      } else {
        setError(result.error);
        setFieldErrors({});
      }
      return;
    }
    setError(undefined);
    setFieldErrors({});
    if (step === STEPS.length - 1) {
      setSubmitted(true);
    } else {
      setStep((s) => s + 1);
    }
  }

  function handleBack() {
    setError(undefined);
    setFieldErrors({});
    setStep((s) => Math.max(0, s - 1));
  }

  function handleCloseAndReset() {
    setStep(0);
    setValues(initialValues);
    setSubmitted(false);
    setError(undefined);
    setFieldErrors({});
    onClose();
  }

  return (
    <div className={styles.overlay} onClick={handleCloseAndReset}>
      <div className={styles.modal} onClick={(e) => e.stopPropagation()}>
        <button aria-label="Закрыть диалоговое окно" className={styles.closeBtn} onClick={handleCloseAndReset}>×</button>

        {submitted ? (
          <div className={styles.thanks}>
            <h2>Спасибо, мы с вами свяжемся!</h2>
            <p>Флорист свяжется с вами, чтобы обсудить детали букета.</p>
          </div>
        ) : (
          <>
            <p className={styles.stepIndicator}>{step + 1}/{STEPS.length}</p>
            <h2>{STEPS[step]}</h2>

            {step === 0 && (
              <RadioGroup
                options={['Авторский букет', 'Монобукет', 'Коробка с цветами', 'Корзина цветов']}
                value={values.bouquetType}
                onChange={(v) => update('bouquetType', v)}
              />
            )}
            {step === 1 && (
              <RadioGroup
                options={['Яркая гамма', 'Нежная гамма', 'Доверяю флористу', 'Другое (указать в комментарии)']}
                value={values.colorScheme}
                onChange={(v) => update('colorScheme', v)}
              />
            )}
            {step === 2 && (
              <RadioGroup
                options={['S (маленький)', 'M (средний)', 'L (большой)']}
                value={values.size}
                onChange={(v) => update('size', v)}
              />
            )}
            {step === 3 && (
              <label className={styles.rangeLabel}>
                Бюджет букета (минимальная сумма - 3 000 рублей): {values.budget} ₽
                <input
                  type="range"
                  min={3000}
                  max={50000}
                  step={500}
                  value={values.budget}
                  onChange={(e) => update('budget', Number(e.target.value))}
                />
              </label>
            )}
            {step === 4 && (
              <>
                <label className={styles.formLabel}>
                  К какому дню должен быть готов букет?
                  <input value={values.readyBy} onChange={(e) => update('readyBy', e.target.value)} />
                </label>
                <label className={styles.formLabel}>
                  Необходимы ли открытка и дополнительные подарки?
                  <input value={values.extras} onChange={(e) => update('extras', e.target.value)} />
                </label>
              </>
            )}
            {step === 5 && (
              <label className={styles.formLabel}>
                Ваш комментарий
                <textarea value={values.comment} onChange={(e) => update('comment', e.target.value)} />
              </label>
            )}
            {step === 6 && (
              <>
                <label className={styles.formLabel}>
                  Ваше имя
                  <input value={values.name} onChange={(e) => update('name', e.target.value)} />
                  {fieldErrors.name && <span className={styles.fieldError}>{fieldErrors.name}</span>}
                </label>
                <label className={styles.formLabel}>
                  Телефон
                  <input
                    type="tel"
                    placeholder="(000) 000-00-00"
                    value={values.phone}
                    onChange={(e) => update('phone', e.target.value)}
                  />
                  {fieldErrors.phone && <span className={styles.fieldError}>{fieldErrors.phone}</span>}
                </label>
                <label className={styles.formLabel}>
                  Ваш e-mail
                  <input type="email" value={values.email} onChange={(e) => update('email', e.target.value)} />
                  {fieldErrors.email && <span className={styles.fieldError}>{fieldErrors.email}</span>}
                </label>
              </>
            )}

            {error && step !== 6 && <p className={styles.error}>{error}</p>}

            <div className={styles.nav}>
              {step > 0 && <button type="button" onClick={handleBack}>← Назад</button>}
              <button type="button" className={styles.nextBtn} onClick={handleNext}>
                {step === STEPS.length - 1 ? 'Отправить' : 'Далее →'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function RadioGroup({
  options, value, onChange,
}: {
  options: string[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className={styles.radioGroup}>
      {options.map((opt) => (
        <label key={opt} className={styles.radioOption}>
          <input type="radio" checked={value === opt} onChange={() => onChange(opt)} />
          {opt}
        </label>
      ))}
    </div>
  );
}
