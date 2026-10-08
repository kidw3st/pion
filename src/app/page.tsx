import type { Metadata } from 'next';
import Link from 'next/link';
import { getSite } from '@/lib/content';
import { getNewProducts } from '@/lib/catalog';
import { buildMetadata, productPath } from '@/lib/seo';
import type { Product } from '@/lib/types';
import { HeroSlider } from '@/components/HeroSlider/HeroSlider';
import { ReputationBar } from '@/components/Reputation/ReputationBar';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import { Features } from '@/components/Features/Features';
import { BouquetBlock } from '@/components/BouquetBlock/BouquetBlock';
import { UdsBlock } from '@/components/UdsBlock/UdsBlock';
import { ShowcaseSection } from '@/components/Showcase/ShowcaseSection';
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

  // «Новинки» — раздел каталога novinki, его ведёт салон: первые три букета в
  // продаже, со ссылками на их страницы. Пока каталог не перенесён в базу
  // (этап 3), такого раздела нет, и карточки берутся из site.json, как раньше.
  const fromCatalog = getNewProducts(3);
  const featured: Product[] =
    fromCatalog ??
    site.newProducts.map((p) => ({
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

      {site.reputation && <ReputationBar data={site.reputation} />}

      {/* Живая витрина из CRM: появляется, только когда на витрине есть
          букеты, поэтому стоит выше постоянных «Новинок». */}
      <ShowcaseSection variant="home" limit={6} />

      {featured.length > 0 && (
        <section className={styles.newSection}>
          <h2 className={styles.newHeading}>Новинки</h2>
          <div className={styles.newGrid}>
            {featured.map((p) => (
              <ProductCard
                key={p.uid}
                product={p}
                isNew
                href={fromCatalog && p.mainSection ? productPath(p.mainSection, p.slug) : undefined}
              />
            ))}
          </div>
          <div className={styles.newMore}>
            <Link href="/bukety" className={styles.newMoreBtn}>
              Смотреть все букеты
            </Link>
          </div>
        </section>
      )}

      <Features features={site.features} />

      <BouquetBlock data={site.bouquetBlock} />

      <UdsBlock data={site.uds} />
    </main>
  );
}
