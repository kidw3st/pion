import Link from 'next/link';
import Image from 'next/image';
import { getSite } from '@/lib/content';
import styles from './Footer.module.css';

export function Footer() {
  const site = getSite();
  const vkHref = site.social.find((s) => s.href.includes('vk.com'))?.href;

  return (
    <footer className={styles.footer}>
      <div className={styles.inner}>
        <div className={styles.brand}>
          <Link href="/" className={styles.logo} aria-label="Пион — на главную">
            <Image src="/images/site/logo-footer.png" alt="Пион" width={938} height={490} />
          </Link>
          <p className={styles.legal}>{site.footer.legal}</p>
          <p className={styles.legal}>{site.footer.hours}</p>
          <div className={styles.payment}>
            <span>Оплата картой</span>
            <Image src="/images/site/mir.svg" alt="МИР" width={60} height={20} />
          </div>
        </div>

        <div className={styles.columns}>
          {site.footer.columns.map((col) => (
            <div key={col.title} className={styles.column}>
              <h4 className={styles.columnTitle}>{col.title}</h4>
              <ul className={styles.columnList}>
                {col.links.map((l) => (
                  <li key={l.href}>
                    <Link href={l.href}>{l.label}</Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        {vkHref && (
          <a href={vkHref} target="_blank" rel="noreferrer noopener" className={styles.vkLink}>
            ПЕРЕЙТИ
            <br />В ГРУППУ VK
          </a>
        )}
      </div>
    </footer>
  );
}
