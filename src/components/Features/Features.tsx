import Image from 'next/image';
import type { Feature } from '@/lib/types';
import styles from './Features.module.css';

export function Features({ features }: { features: Feature[] }) {
  return (
    <section className={styles.section}>
      <ul className={styles.list}>
        {features.map((f) => (
          <li key={f.title} className={styles.item}>
            <span className={styles.icon}>
              <Image src={f.icon} alt="" width={40} height={40} />
            </span>
            <div className={styles.body}>
              <h3 className={styles.title}>{f.title}</h3>
              <p className={styles.text}>{f.description}</p>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
