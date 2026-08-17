import type { Metadata } from 'next';
import Link from 'next/link';
import { getSite } from '@/lib/content';
import { buildMetadata } from '@/lib/seo';
import { HeroSlider } from '@/components/HeroSlider/HeroSlider';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import { Features } from '@/components/Features/Features';
import { BouquetBlock } from '@/components/BouquetBlock/BouquetBlock';
import { UdsBlock } from '@/components/UdsBlock/UdsBlock';
import styles from './page.module.css';

// The homepage keeps the site-wide default title (no "| Пион, Пермь" suffix on
// top of a name that already contains it), so it is set explicitly here.
export const metadata: Metadata = {
  ...buildMetadata({
    title: 'Салон цветов «Пион» — доставка букетов в Перми',
    description:
      'Авторские букеты, композиции и подарки с доставкой по Перми. Фото букета перед отправкой, оплата картой онлайн, самовывоз со скидкой 5%. Ежедневно 10:00–22:00.',
    path: '/',
  }),
  title: { absolute: 'Салон цветов «Пион» — доставка букетов в Перми' },
};

export default async function HomePage() {
  const site = getSite();

  // "Новинки" is the salon's own selection with its own copy and prices, so it
  // comes from site data rather than the first few items of a category. Shaped
  // into the catalogue's Product form to reuse the same card.
  const featured = site.newProducts.map((p) => ({
    uid: `new-${p.title}`,
    title: p.title,
    description: p.subtitle,
    price: p.price,
    images: p.image ? [p.image] : [],
    slug: '',
  }));

  return (
    <main>
      {/* The homepage opens on a photo slider, so the heading that names the
          page for search engines has no place in the design. */}
      <h1 className="srOnly">Доставка цветов и букетов в Перми — салон «Пион»</h1>

      <HeroSlider slides={site.heroSlides} />

      <section className={styles.newSection}>
        <h2 className={styles.newHeading}>Новинки</h2>
        <div className={styles.newGrid}>
          {featured.map((p) => (
            <ProductCard key={p.uid} product={p} isNew />
          ))}
        </div>
        <div className={styles.newMore}>
          <Link href="/bukety" className={styles.newMoreBtn}>
            Смотреть все букеты
          </Link>
        </div>
      </section>

      <Features features={site.features} />

      <BouquetBlock data={site.bouquetBlock} />

      <UdsBlock data={site.uds} />
    </main>
  );
}
