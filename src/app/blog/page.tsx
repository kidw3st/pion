import type { Metadata } from 'next';
import Link from 'next/link';
import { buildMetadata } from '@/lib/seo';
import styles from './page.module.css';

export const metadata: Metadata = {
  ...buildMetadata({
    title: 'Блог салона «Пион»',
    description:
      'Заметки флористов салона «Пион» в Перми: как выбрать букет, сколько живут цветы, идеи для подарка.',
    path: '/blog/',
  }),
  // Пока раздел пуст, в поиске ему делать нечего.
  robots: { index: false, follow: true },
};

/**
 * Временная страница раздела.
 *
 * Сам блог будет жить здесь же на WordPress. До его установки вкладка в
 * шапке вела бы на 404, поэтому по адресу лежит эта заглушка. Настройка
 * DirectoryIndex в public/.htaccess отдаёт приоритет index.php, так что
 * WordPress перекроет её автоматически, как только появится в папке.
 */
export default function BlogPage() {
  return (
    <main className={styles.main}>
      <h1 className={styles.title}>Блог</h1>
      <p className={styles.lead}>
        Здесь скоро появятся заметки наших флористов: как выбрать букет к случаю, сколько живут
        разные цветы и что подарить, когда повод неочевиден.
      </p>
      <p className={styles.lead}>
        А пока свежие работы и живые отзывы мы выкладываем в{' '}
        <a href="https://vk.com/pionpermcveti" target="_blank" rel="noreferrer noopener" className={styles.link}>
          нашей группе ВКонтакте
        </a>
        .
      </p>

      <div className={styles.actions}>
        <Link href="/catalog" className={styles.button}>
          Перейти в каталог
        </Link>
        <Link href="/v-nalichii" className={styles.buttonGhost}>
          Букеты в наличии
        </Link>
      </div>
    </main>
  );
}
