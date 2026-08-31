'use client';

import { useState } from 'react';
import { getSite } from '@/lib/content';
import { TelegramIcon, WhatsappIcon } from '@/components/Header/SocialIcons';
import styles from './MessengerFab.module.css';

/**
 * Плавающая кнопка мессенджеров — аналог блока на живом сайте: одна круглая
 * кнопка чата, по нажатию раскрывающая WhatsApp и Telegram. Слева внизу,
 * чтобы не толкаться с корзиной и «наверх» справа.
 */
export function MessengerFab() {
  const [open, setOpen] = useState(false);
  const site = getSite();

  const wa = site.social.find((s) => s.href.includes('wa.me'))?.href;
  const tg = site.social.find((s) => s.href.includes('t.me'))?.href;

  if (!wa && !tg) return null;

  return (
    <div className={styles.wrap}>
      {open && (
        <div className={styles.list}>
          {wa && (
            <a
              href={wa}
              target="_blank"
              rel="noreferrer noopener"
              className={`${styles.item} ${styles.whatsapp}`}
              aria-label="Написать в WhatsApp"
            >
              <WhatsappIcon />
            </a>
          )}
          {tg && (
            <a
              href={tg}
              target="_blank"
              rel="noreferrer noopener"
              className={`${styles.item} ${styles.telegram}`}
              aria-label="Написать в Telegram"
            >
              <TelegramIcon />
            </a>
          )}
        </div>
      )}

      <button
        type="button"
        className={styles.fab}
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-label={open ? 'Скрыть мессенджеры' : 'Написать нам в мессенджер'}
      >
        {open ? (
          <span className={styles.closeGlyph} aria-hidden="true">
            ×
          </span>
        ) : (
          <svg viewBox="0 0 24 24" width="26" height="26" fill="none" aria-hidden="true">
            <path
              d="M12 4C7 4 3.5 7.2 3.5 11.2c0 2.2 1.1 4.1 2.9 5.4L5.6 20l3.6-1.7c.9.2 1.8.4 2.8.4 5 0 8.5-3.2 8.5-7.2S17 4 12 4Z"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinejoin="round"
            />
          </svg>
        )}
      </button>
    </div>
  );
}
