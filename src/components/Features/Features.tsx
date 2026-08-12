import Image from 'next/image';
import type { Feature } from '@/lib/types';
import styles from './Features.module.css';

/**
 * The accent band of four selling points. On the live site each item is a
 * column in a single row (icon above title above copy); it only stacks
 * vertically on narrow viewports.
 */
export function Features({ features }: { features: Feature[] }) {
  return (
    <section className={styles.section}>
      <ul className={styles.list}>
        {features.map((f) => (
          <li key={f.title} className={styles.item}>
            <span className={styles.icon}>
              <Image src={f.icon} alt="" width={50} height={50} />
            </span>
            <h3 className={styles.title}>{f.title}</h3>
            <p className={styles.text}>{f.description}</p>
          </li>
        ))}
      </ul>
    </section>
  );
}
