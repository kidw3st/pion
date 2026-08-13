'use client';

import { useState } from 'react';
import Link from 'next/link';
import { VkIcon } from '@/components/Header/SocialIcons';
import styles from './PageSections.module.css';

/**
 * Contacts block: the salon's details beside a message form, matching the
 * layout the live page uses.
 *
 * The form validates and then thanks the sender — like the rest of this site
 * it has no backend to post to, so it never claims the message was delivered.
 */
export function ContactsSection({
  title,
  intro,
  phone,
  email,
  address,
  hours,
  vkHref,
}: {
  title: string;
  intro: string;
  phone: string;
  email: string;
  address: string;
  hours: string;
  vkHref: string;
}) {
  const [name, setName] = useState('');
  const [contact, setContact] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return setError('Укажите, как к вам обращаться');
    if (!contact.trim()) return setError('Оставьте телефон или email для ответа');
    setError('');
    setSent(true);
  };

  return (
    <section className={styles.contacts}>
      <div className={styles.contactsInner}>
        <div className={styles.contactsInfo}>
          <h1 className={styles.contactsTitle}>{title}</h1>
          {intro && <p className={styles.contactsIntro}>{intro}</p>}

          <dl className={styles.contactsList}>
            {phone && (
              <div className={styles.contactsRow}>
                <dt>Телефон</dt>
                <dd>
                  <a href={`tel:${phone.replace(/[^+\d]/g, '')}`}>{phone}</a>
                </dd>
              </div>
            )}
            {email && (
              <div className={styles.contactsRow}>
                <dt>Почта</dt>
                <dd>
                  <a href={`mailto:${email}`}>{email}</a>
                </dd>
              </div>
            )}
            {address && (
              <div className={styles.contactsRow}>
                <dt>Адрес</dt>
                <dd>{address}</dd>
              </div>
            )}
            {hours && (
              <div className={styles.contactsRow}>
                <dt>Режим работы</dt>
                <dd className={styles.contactsHours}>{hours.replace(/^Режим работы:?\s*/i, '')}</dd>
              </div>
            )}
          </dl>

          {vkHref && (
            <a
              href={vkHref}
              target="_blank"
              rel="noreferrer noopener"
              className={styles.contactsVk}
              aria-label="Группа ВКонтакте"
            >
              <VkIcon />
              <span>Мы во ВКонтакте</span>
            </a>
          )}
        </div>

        <div className={styles.contactsFormWrap}>
          {sent ? (
            <div className={styles.contactsThanks}>
              <h2>Спасибо!</h2>
              <p>Мы получили ваше сообщение и свяжемся с вами.</p>
            </div>
          ) : (
            <form className={styles.contactsForm} onSubmit={submit}>
              <label>
                Ваше имя
                <input value={name} onChange={(e) => setName(e.target.value)} />
              </label>
              <label>
                Телефон или email
                <input value={contact} onChange={(e) => setContact(e.target.value)} />
              </label>
              <label>
                Сообщение
                <textarea rows={4} value={message} onChange={(e) => setMessage(e.target.value)} />
              </label>
              {error && <span className={styles.contactsError}>{error}</span>}
              <button type="submit" className={styles.contactsSubmit}>
                ОТПРАВИТЬ
              </button>
              <p className={styles.contactsConsent}>
                Нажимая на кнопку, вы даёте согласие на обработку персональных данных и соглашаетесь
                с <Link href="/policy">политикой конфиденциальности</Link>.
              </p>
            </form>
          )}
        </div>
      </div>
    </section>
  );
}
