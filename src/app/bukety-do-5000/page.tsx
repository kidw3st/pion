import type { Metadata } from 'next';
import { getCatalog } from '@/lib/content';
import { buildMetadata, breadcrumbJsonLd, productListJsonLd } from '@/lib/seo';
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { CategoryGrid } from '@/components/CategoryGrid/CategoryGrid';
import { ShowcaseSection } from '@/components/Showcase/ShowcaseSection';
import styles from './page.module.css';

/** Порог подборки. Он же в названии плитки в data/catalog-meta.json. */
const MAX_PRICE = 5000;

const TITLE = 'Букеты до 5 000 ₽ с доставкой в Перми | Салон «Пион»';

export const metadata: Metadata = {
  ...buildMetadata({
    title: TITLE,
    description:
      'Букеты до 5 000 ₽ от салона «Пион» в Перми: собранные сегодня с витрины салона и авторские из каталога. Доставка по городу, самовывоз со скидкой 5%.',
    path: '/bukety-do-5000/',
  }),
  // Без суффикса макета: иначе заголовок не помещается в выдаче.
  title: { absolute: TITLE },
};

/**
 * Подборка недорогих букетов — вместо плитки «Комплимент», которая вела на
 * архивную коллекцию к 14 февраля.
 *
 * Два источника. Сверху — витрина из Posiflora: эти букеты собраны сегодня и
 * меняются в течение дня, поэтому читаются уже в браузере. Ниже — каталог:
 * постоянные позиции раздела «Букеты», отобранные по цене при сборке сайта.
 * Поштучные цветы сюда не попадают — розу за 540 ₽ букетом не назовёшь.
 */
export default async function BudgetBouquetsPage() {
  const bouquets = ((await getCatalog('bukety')) ?? [])
    .filter((p) => p.price > 0 && p.price <= MAX_PRICE)
    .sort((a, b) => a.price - b.price);

  return (
    <main className={styles.main}>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: 'Главная', path: '/' },
          { name: 'Каталог', path: '/catalog/' },
          { name: 'Букеты до 5 000 ₽', path: '/bukety-do-5000/' },
        ])}
      />
      {/* Адреса товаров в разметке — их настоящие страницы в разделе букетов. */}
      {bouquets.length > 0 && <JsonLd data={productListJsonLd(bouquets, '/bukety/')} />}

      <h1 className={styles.title}>Букеты до 5 000 ₽</h1>
      <p className={styles.lead}>
        Сверху — букеты, которые флористы собрали сегодня: они стоят в салоне, их можно забрать
        сразу или заказать с доставкой. Ниже — авторские букеты из каталога, соберём их под ваш
        заказ.
      </p>

      <ShowcaseSection variant="budget" maxPrice={MAX_PRICE} />

      <CategoryGrid
        category="bukety"
        products={bouquets}
        title="Из каталога"
        subtitle=""
        headingLevel="h2"
      />
    </main>
  );
}
