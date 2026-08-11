import Link from 'next/link';
import { CATEGORY_SLUGS } from '@/lib/content';

const CATEGORY_LABELS: Record<string, string> = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  flowers: 'Цветы',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  indoorflowers: 'Комнатные растения',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
};

export default function CatalogPage() {
  return (
    <main style={{ padding: '48px 40px' }}>
      <h1>Каталог</h1>
      <ul style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 16, listStyle: 'none', padding: 0 }}>
        {CATEGORY_SLUGS.map((slug) => (
          <li key={slug}>
            <Link href={`/${slug}`}>{CATEGORY_LABELS[slug] ?? slug}</Link>
          </li>
        ))}
      </ul>
    </main>
  );
}
