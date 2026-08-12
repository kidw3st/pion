'use client';

import { useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import type { CatalogTile } from '@/lib/types';
import { BouquetBuilderPopup } from '@/components/BouquetBuilder/BouquetBuilderPopup';
import styles from './CatalogTiles.module.css';

/**
 * The /catalog overview: a full-bleed grid of photo tiles, four per row,
 * each a category link with its label over a darkened photo. The
 * "Создать уникальный букет" tile opens the bouquet-builder popup instead
 * of navigating (the live tile's href is #popup:individual).
 */
export function CatalogTiles({ tiles }: { tiles: CatalogTile[] }) {
  const [builderOpen, setBuilderOpen] = useState(false);

  return (
    <main className={styles.grid}>
      {tiles.map((tile) =>
        tile.href.startsWith('#') ? (
          <button
            key={tile.label}
            type="button"
            className={styles.tile}
            onClick={() => setBuilderOpen(true)}
          >
            <Image src={tile.image} alt="" fill sizes="25vw" className={styles.photo} />
            <span className={styles.shade} />
            <span className={styles.label}>{tile.label}</span>
          </button>
        ) : (
          <Link key={tile.label} href={tile.href} className={styles.tile}>
            <Image src={tile.image} alt="" fill sizes="25vw" className={styles.photo} />
            <span className={styles.shade} />
            <span className={styles.label}>{tile.label}</span>
          </Link>
        ),
      )}

      <BouquetBuilderPopup open={builderOpen} onClose={() => setBuilderOpen(false)} />
    </main>
  );
}
