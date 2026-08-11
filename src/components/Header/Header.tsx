import Link from 'next/link';
import { getSite } from '@/lib/content';
import { CartButton } from './CartButton';
import styles from './Header.module.css';

export function Header() {
  const site = getSite();
  return (
    <header className={styles.header}>
      <div className={styles.top}>
        <Link href="/" className={styles.logo}>ПИОН</Link>
        <nav className={styles.nav}>
          <ul className={styles.navList}>
            {site.nav.map((item) => (
              <li key={item.href}>
                <Link href={item.href}>{item.label}</Link>
              </li>
            ))}
          </ul>
        </nav>
        <div className={styles.contacts}>
          <a href={`tel:${site.phone.replace(/[^+\d]/g, '')}`} className={styles.phone}>
            {site.phone}
          </a>
          <span className={styles.address}>{site.address}</span>
        </div>
        <ul className={styles.social}>
          {site.social.map((s) => (
            <li key={s.href}>
              <a href={s.href} target="_blank" rel="noreferrer">{s.label}</a>
            </li>
          ))}
        </ul>
        <CartButton />
        <Link href="/catalog" className={styles.orderBtn}>СДЕЛАТЬ ЗАКАЗ</Link>
      </div>
    </header>
  );
}
