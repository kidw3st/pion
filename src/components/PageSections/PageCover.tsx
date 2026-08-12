'use client';

import { useState, useEffect } from 'react';
import Image from 'next/image';
import styles from './PageSections.module.css';

/**
 * Page header: the title over a slowly cross-fading stack of photos, matching
 * the cover block the live pages open with.
 */
export function PageCover({
  title,
  subtitle,
  images,
}: {
  title: string;
  subtitle: string;
  images: string[];
}) {
  const [index, setIndex] = useState(0);

  useEffect(() => {
    if (images.length <= 1) return;
    const timer = setInterval(() => setIndex((i) => (i + 1) % images.length), 6000);
    return () => clearInterval(timer);
  }, [images.length, index]);

  return (
    <section className={styles.cover}>
      {images.map((src, i) => (
        <div
          key={src}
          className={i === index ? styles.coverSlideActive : styles.coverSlide}
          aria-hidden={i === index ? undefined : true}
        >
          <Image src={src} alt="" fill priority={i === 0} className={styles.coverImage} />
        </div>
      ))}
      <div className={styles.coverOverlay} />
      <div className={styles.coverBody}>
        <h1 className={styles.coverTitle}>{title}</h1>
        {subtitle && <p className={styles.coverSubtitle}>{subtitle}</p>}
      </div>
    </section>
  );
}
