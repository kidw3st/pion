'use client';

import { useState, useMemo } from 'react';
import type { Product } from '@/lib/types';
import { sortProducts, type SortOrder } from './sortProducts';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import styles from './CategoryGrid.module.css';

const SORT_LABELS: Record<SortOrder, string> = {
  default: 'По умолчанию',
  'price-asc': 'Цена: по возрастанию',
  'price-desc': 'Цена: по убыванию',
  'name-asc': 'Название: А—Я',
  'name-desc': 'Название: Я—А',
};

export function CategoryGrid({
  products,
  title,
  subtitle,
}: {
  products: Product[];
  title: string;
  subtitle: string;
}) {
  const [order, setOrder] = useState<SortOrder>('default');
  const sorted = useMemo(() => sortProducts(products, order), [products, order]);

  return (
    <section className={styles.section}>
      <div className={styles.heading}>
        <h1>{title}</h1>
        <p>{subtitle}</p>
      </div>
      <div className={styles.toolbar}>
        <label>
          Порядок:{' '}
          <select value={order} onChange={(e) => setOrder(e.target.value as SortOrder)}>
            {Object.entries(SORT_LABELS).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </label>
      </div>
      <div className={styles.grid}>
        {sorted.map((product) => (
          <ProductCard key={product.uid} product={product} />
        ))}
      </div>
    </section>
  );
}
