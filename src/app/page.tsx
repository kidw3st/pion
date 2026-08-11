import { getSite, getCatalog } from '@/lib/content';
import { HeroSlider } from '@/components/HeroSlider/HeroSlider';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import styles from './page.module.css';

export default async function HomePage() {
  const site = getSite();
  const newProducts = (await getCatalog('bukety'))?.slice(0, 3) ?? [];

  return (
    <main>
      <HeroSlider slides={site.heroSlides} />

      <section className={styles.newSection}>
        <h2>Новинки</h2>
        <div className={styles.newGrid}>
          {newProducts.map((p) => <ProductCard key={p.uid} product={p} />)}
        </div>
      </section>

      <section className={styles.features}>
        {site.features.map((f) => (
          <div key={f.title} className={styles.feature}>
            <h3>{f.title}</h3>
            <p>{f.description}</p>
          </div>
        ))}
      </section>
    </main>
  );
}
