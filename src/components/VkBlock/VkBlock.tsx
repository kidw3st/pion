import Image from 'next/image';
import type { VkBlock as VkBlockData } from '@/lib/types';
import styles from './VkBlock.module.css';

/**
 * VK promo band. The live block is a fixed 1200px canvas with everything
 * absolutely placed on it: heading and copy top-left, the round logo badge and
 * link below them, an oversized "PION цветы" watermark behind, and a row of
 * four 215px photos across the right. Below 1200px it falls back to a plain
 * stacked layout, since the fixed canvas cannot shrink.
 */
export function VkBlock({ data }: { data: VkBlockData }) {
  return (
    <section className={styles.section}>
      <div className={styles.canvas}>
        <h2 className={styles.title}>{data.title}</h2>
        <p className={styles.text}>{data.text}</p>

        {data.watermark && <span className={styles.watermark}>{data.watermark}</span>}

        <div className={styles.photos}>
          {data.images.map((src) => (
            <div key={src} className={styles.shot}>
              <Image src={src} alt="" fill sizes="215px" className={styles.image} />
            </div>
          ))}
        </div>

        <a
          href={data.href}
          target="_blank"
          rel="noreferrer noopener"
          className={styles.link}
          aria-label={data.buttonText}
        >
          {data.badge && (
            <span className={styles.badge}>
              <Image src={data.badge} alt="" width={80} height={81} />
            </span>
          )}
          <span className={styles.linkText}>{data.buttonText}</span>
        </a>
      </div>
    </section>
  );
}
