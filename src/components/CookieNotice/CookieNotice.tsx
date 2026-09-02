'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import styles from './CookieNotice.module.css';

const STORAGE_KEY = 'pion-cookie-ok';

/**
 * Уведомление об использовании cookie и Яндекс.Метрики. Показывается до
 * первого «Хорошо», решение хранится в localStorage — сам баннер cookie не
 * ставит. Появляется после гидрации, чтобы сервер и клиент рисовали одно и
 * то же.
 */
export function CookieNotice() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    try {
      if (!localStorage.getItem(STORAGE_KEY)) setVisible(true);
    } catch {
      // Хранилище недоступно (приватный режим со строгими настройками) —
      // молчим, чтобы баннер не преследовал посетителя на каждой странице.
    }
  }, []);

  // Пока полоса на экране, плавающие кнопки поднимаются на её высоту: иначе
  // на узких экранах мессенджер накрывает последнюю строку текста.
  useEffect(() => {
    const root = document.documentElement;
    if (!visible) {
      root.style.removeProperty('--cookie-bar-h');
      return;
    }
    const bar = document.querySelector<HTMLElement>(`.${styles.banner}`);
    if (!bar) return;
    const apply = () => root.style.setProperty('--cookie-bar-h', `${bar.offsetHeight}px`);
    apply();
    const ro = new ResizeObserver(apply);
    ro.observe(bar);
    return () => {
      ro.disconnect();
      root.style.removeProperty('--cookie-bar-h');
    };
  }, [visible]);

  const accept = () => {
    try {
      localStorage.setItem(STORAGE_KEY, String(Date.now()));
    } catch {
      /* некуда сохранить — просто скрываем до конца визита */
    }
    setVisible(false);
  };

  if (!visible) return null;

  return (
    <div className={styles.banner} role="region" aria-label="Уведомление об использовании cookie">
      <p className={styles.text}>
        Мы используем cookie и Яндекс.Метрику, чтобы сайт работал и становился удобнее.
        Оставаясь здесь, вы соглашаетесь с{' '}
        <Link href="/policy" className={styles.link}>
          политикой конфиденциальности
        </Link>
        .
      </p>
      <button type="button" className={styles.button} onClick={accept}>
        Хорошо
      </button>
    </div>
  );
}
