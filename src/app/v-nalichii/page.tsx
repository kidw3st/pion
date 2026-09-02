import type { Metadata } from 'next';
import { buildMetadata } from '@/lib/seo';
import { ShowcaseSection } from '@/components/Showcase/ShowcaseSection';
import styles from './page.module.css';

export const metadata: Metadata = buildMetadata({
  title: 'Букеты в наличии сегодня в Перми',
  description:
    'Букеты, которые прямо сейчас стоят на витрине салона «Пион» в Перми. Свежая сборка, фото каждого букета, самовывоз или доставка в день заказа.',
  path: '/v-nalichii/',
});

/**
 * Витрина салона целиком. Список приходит из CRM уже в браузере, поэтому в
 * статической странице лежит только заголовок и объяснение — карточки
 * подставляет ShowcaseSection.
 */
export default function ShowcasePage() {
  return (
    <main className={styles.main}>
      <h1 className={styles.title}>Букеты в наличии</h1>
      <p className={styles.lead}>
        Эти букеты собраны нашими флористами и стоят на витрине салона прямо сейчас. Каждый
        существует в одном экземпляре: как только букет купят, он пропадёт со страницы.
      </p>

      <ShowcaseSection variant="page" />
    </main>
  );
}
