import { getSite, getCatalog } from '@/lib/content';
import { HeroSlider } from '@/components/HeroSlider/HeroSlider';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import { Features } from '@/components/Features/Features';
import { BouquetBlock } from '@/components/BouquetBlock/BouquetBlock';
import { UdsBlock } from '@/components/UdsBlock/UdsBlock';
import styles from './page.module.css';

export default async function HomePage() {
  const site = getSite();
  const newProducts = (await getCatalog('bukety'))?.slice(0, 3) ?? [];

  return (
    <main>
      <HeroSlider slides={site.heroSlides} />

      <section className={styles.newSection}>
        <h2 className={styles.newHeading}>Новинки</h2>
        <div className={styles.newGrid}>
          {newProducts.map((p) => (
            <ProductCard key={p.uid} product={p} isNew />
          ))}
        </div>
      </section>

      <Features features={site.features} />

      <BouquetBlock data={site.bouquetBlock} />

      <UdsBlock data={site.uds} />
    </main>
  );
}
