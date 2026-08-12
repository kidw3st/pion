'use client';

import { useState } from 'react';
import Image from 'next/image';
import type { BouquetBlock as BouquetBlockData } from '@/lib/types';
import { BouquetBuilderPopup } from '@/components/BouquetBuilder/BouquetBuilderPopup';
import styles from './BouquetBlock.module.css';

export function BouquetBlock({ data }: { data: BouquetBlockData }) {
  const [open, setOpen] = useState(false);

  return (
    <section className={styles.section}>
      <div className={styles.inner}>
        <div className={styles.photo}>
          <Image src={data.image} alt="" width={520} height={780} className={styles.image} />
        </div>
        <div className={styles.body}>
          <h2 className={styles.title}>{data.title}</h2>
          <p className={styles.text}>{data.text}</p>
          <button type="button" className={styles.button} onClick={() => setOpen(true)}>
            {data.buttonText}
          </button>
        </div>
      </div>

      <BouquetBuilderPopup open={open} onClose={() => setOpen(false)} />
    </section>
  );
}
