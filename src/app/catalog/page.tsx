import Link from 'next/link';
import { CATEGORY_SLUGS, CATEGORY_LABELS } from '@/lib/content';

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
