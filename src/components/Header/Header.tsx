import Link from 'next/link';
import Image from 'next/image';
import { getSite } from '@/lib/content';
import { VkIcon, TelegramIcon, WhatsappIcon } from './SocialIcons';
import styles from './Header.module.css';

// Icon per social network, matched on the link target rather than the label
// so a label change in site.json can't silently drop the icon.
function socialIcon(href: string) {
  if (href.includes('vk.com')) return <VkIcon />;
  if (href.includes('t.me')) return <TelegramIcon />;
  if (href.includes('wa.me')) return <WhatsappIcon />;
  return null;
}

export function Header() {
  const site = getSite();
  return (
    <header className={styles.header}>
      <Link href="/" className={styles.logo} aria-label="Пион — на главную">
        <Image src="/images/site/logo.png" alt="Пион" width={938} height={490} priority />
      </Link>

      <nav className={styles.nav}>
        <ul className={styles.navList}>
          {site.nav.map((item) => (
            <li key={item.href}>
              <Link href={item.href}>{item.label}</Link>
            </li>
          ))}
        </ul>
      </nav>

      <div className={styles.right}>
        <div className={styles.contacts}>
          <a href={`tel:${site.phone.replace(/[^+\d]/g, '')}`} className={styles.phone}>
            {site.phone}
          </a>
          <span className={styles.address}>{site.address}</span>
        </div>

        <ul className={styles.social}>
          {site.social.map((s) => (
            <li key={s.href}>
              <a href={s.href} target="_blank" rel="noreferrer noopener" aria-label={s.label}>
                {socialIcon(s.href)}
              </a>
            </li>
          ))}
        </ul>

        <Link href="/catalog" className={styles.orderBtn}>
          СДЕЛАТЬ ЗАКАЗ
        </Link>
      </div>
    </header>
  );
}
