'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ProductCard } from '@/components/ProductCard/ProductCard';
import type { Product } from '@/lib/types';
import styles from './ShowcaseSection.module.css';

type ShowcaseFile = {
  updatedAt?: string;
  products?: { uid: string; title: string; price: number; description?: string; images: string[] }[];
};

/**
 * Букеты, которые прямо сейчас стоят на витрине салона.
 *
 * Список приходит из CRM: sync-showcase.php на сервере складывает витрину в
 * api/showcase.json, здесь она читается уже в браузере. Так новые букеты
 * появляются на сайте без пересборки — флорист просто ставит букет на
 * витрину в Posiflora.
 *
 * Файла может не быть (сайт на GitHub Pages, синхронизация ещё не
 * запускалась) — тогда блок молча не показывается.
 */
export function ShowcaseSection({
  variant = 'home',
  limit,
}: {
  variant?: 'home' | 'page';
  limit?: number;
}) {
  const [products, setProducts] = useState<Product[] | null>(null);

  useEffect(() => {
    const base = process.env.NEXT_PUBLIC_BASE_PATH ?? '';
    let alive = true;
    fetch(`${base}/api/showcase.json`, { cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data: ShowcaseFile | null) => {
        if (!alive) return;
        const list = (data?.products ?? []).map((p) => ({
          uid: p.uid,
          title: p.title,
          description: p.description || 'Готов к отправке сегодня',
          price: p.price,
          images: p.images ?? [],
          slug: '',
        }));
        setProducts(list);
      })
      .catch(() => alive && setProducts([]));
    return () => {
      alive = false;
    };
  }, []);

  // Пока грузим — ничего не рисуем, чтобы страница не «прыгала».
  if (products === null) return null;

  if (products.length === 0) {
    if (variant === 'home') return null;
    return (
      <section className={styles.section}>
        <p className={styles.empty}>
          Сейчас витрина обновляется. Позвоните нам — соберём букет специально для вас:{' '}
          <a href="tel:+73422584545" className={styles.phone}>
            +7 342 258 45 45
          </a>
        </p>
      </section>
    );
  }

  const shown = limit ? products.slice(0, limit) : products;

  return (
    <section className={styles.section}>
      {/* На отдельной странице заголовок уже есть в разметке страницы. */}
      {variant === 'home' && (
        <>
          <h2 className={styles.heading}>Букеты в наличии</h2>
          <p className={styles.subheading}>
            Собраны сегодня и ждут вас в салоне — можно забрать или заказать доставку
          </p>
        </>
      )}

      <div className={styles.grid}>
        {shown.map((p) => (
          <ProductCard key={p.uid} product={p} />
        ))}
      </div>

      {variant === 'home' && products.length > shown.length && (
        <div className={styles.more}>
          <Link href="/v-nalichii" className={styles.moreBtn}>
            Смотреть всю витрину
          </Link>
        </div>
      )}
    </section>
  );
}
