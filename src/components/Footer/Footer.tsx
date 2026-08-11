import Link from 'next/link';
import { getSite } from '@/lib/content';
import styles from './Footer.module.css';

export function Footer() {
  const site = getSite();
  return (
    <footer className={styles.footer}>
      <div className={styles.vkBlock}>
        <p>ПОДПИСЫВАЙТЕСЬ НА НАШУ ГРУППУ VK</p>
        <a href={site.social.find((s) => s.label === 'VK')?.href} target="_blank" rel="noreferrer">
          ПЕРЕЙТИ В ГРУППУ VK
        </a>
      </div>
      <div className={styles.columns}>
        {site.footer.columns.map((col) => (
          <div key={col.title} className={styles.column}>
            <h4>{col.title}</h4>
            <ul>
              {col.links.map((l) => (
                <li key={l.href}><Link href={l.href}>{l.label}</Link></li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className={styles.legal}>
        <p>{site.footer.legal}</p>
        <p>{site.footer.hours}</p>
      </div>
    </footer>
  );
}
