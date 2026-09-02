'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import type { SiteData } from '@/lib/types';
import { VkIcon, TelegramIcon, WhatsappIcon } from './SocialIcons';
import styles from './MobileNav.module.css';

function socialIcon(href: string) {
  if (href.includes('vk.com')) return <VkIcon />;
  if (href.includes('t.me')) return <TelegramIcon />;
  if (href.includes('wa.me')) return <WhatsappIcon />;
  return null;
}

type Props = Pick<SiteData, 'nav' | 'phone' | 'address' | 'social'>;

/**
 * Шапка для телефонов: логотип, кнопка звонка и бургер. До этого на узких
 * экранах все ссылки разворачивались в шесть рядов и занимали четверть
 * экрана, а телефон был спрятан совсем.
 */
export function MobileNav({ nav, phone, address, social }: Props) {
  const [open, setOpen] = useState(false);
  const tel = `tel:${phone.replace(/[^+\d]/g, '')}`;

  // Пока панель открыта, страница под ней не прокручивается, а Escape закрывает.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = prev;
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className={styles.wrap}>
      <a href={tel} className={styles.callBtn} aria-label={`Позвонить ${phone}`}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.57 3.5.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.2.2 2.4.57 3.5a1 1 0 0 1-.25 1l-2.2 2.3Z"
            fill="currentColor"
          />
        </svg>
      </a>

      <button
        type="button"
        className={styles.burger}
        aria-label={open ? 'Закрыть меню' : 'Открыть меню'}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className={open ? `${styles.bar} ${styles.barTop}` : styles.bar} />
        <span className={open ? `${styles.bar} ${styles.barHidden}` : styles.bar} />
        <span className={open ? `${styles.bar} ${styles.barBottom}` : styles.bar} />
      </button>

      {open && <div className={styles.overlay} onClick={() => setOpen(false)} aria-hidden="true" />}

      <div className={open ? `${styles.panel} ${styles.panelOpen}` : styles.panel} hidden={!open}>
        {/* Бургер остаётся под панелью, поэтому закрывать нужно отсюда. */}
        <button
          type="button"
          className={styles.close}
          aria-label="Закрыть меню"
          onClick={() => setOpen(false)}
        >
          <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          </svg>
        </button>

        <ul className={styles.links}>
          {nav.map((item) => (
            <li key={item.href}>
              <Link href={item.href} onClick={() => setOpen(false)}>
                {item.label}
              </Link>
            </li>
          ))}
        </ul>

        <Link href="/catalog" className={styles.orderBtn} onClick={() => setOpen(false)}>
          СДЕЛАТЬ ЗАКАЗ
        </Link>

        <div className={styles.contacts}>
          <a href={tel} className={styles.phone}>
            {phone}
          </a>
          <span className={styles.address}>{address}</span>
        </div>

        <ul className={styles.social}>
          {social.map((s) => (
            <li key={s.href}>
              <a href={s.href} target="_blank" rel="noreferrer noopener" aria-label={s.label}>
                {socialIcon(s.href)}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
