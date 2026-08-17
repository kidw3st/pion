import Image from 'next/image';
import Link from 'next/link';
import type { UdsBlock as UdsBlockData } from '@/lib/types';
import styles from './UdsBlock.module.css';

export function UdsBlock({ data }: { data: UdsBlockData }) {
  const [first, second, third] = data.images;

  return (
    <section className={styles.section}>
      <div className={styles.inner}>
        <div className={styles.collage}>
          {first && (
            <Image
              src={first}
              alt="Букеты салона «Пион» — участника программы лояльности UDS"
              width={420}
              height={300}
              className={styles.shotOne}
            />
          )}
          {second && (
            <Image
              src={second}
              alt="Композиция из свежих цветов от салона «Пион»"
              width={300}
              height={200}
              className={styles.shotTwo}
            />
          )}
          {third && (
            <Image
              src={third}
              alt="Подарочная упаковка букета в салоне «Пион», Пермь"
              width={300}
              height={200}
              className={styles.shotThree}
            />
          )}
        </div>

        <div className={styles.body}>
          <h2 className={styles.title}>{data.title}</h2>
          <p className={styles.subtitle}>{data.subtitle}</p>
          <Link href={data.buttonHref} className={styles.button}>
            {data.buttonText}
          </Link>
        </div>
      </div>
    </section>
  );
}
