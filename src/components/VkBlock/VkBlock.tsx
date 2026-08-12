import Image from 'next/image';
import type { VkBlock as VkBlockData } from '@/lib/types';
import styles from './VkBlock.module.css';

export function VkBlock({ data }: { data: VkBlockData }) {
  return (
    <section className={styles.section}>
      <div className={styles.head}>
        <h2 className={styles.title}>{data.title}</h2>
        <p className={styles.text}>{data.text}</p>
        <a href={data.href} target="_blank" rel="noreferrer noopener" className={styles.button}>
          {data.buttonText}
        </a>
      </div>

      <div className={styles.strip}>
        {data.images.map((src) => (
          <div key={src} className={styles.shot}>
            <Image src={src} alt="" fill className={styles.image} sizes="25vw" />
          </div>
        ))}
      </div>
    </section>
  );
}
