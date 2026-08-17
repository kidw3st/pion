'use client';

import { useState, useEffect } from 'react';
import Image from 'next/image';
import styles from './PageSections.module.css';

/**
 * Page header: the title over a slowly cross-fading stack of photos, matching
 * the cover block the live pages open with. Category pages use a taller cover
 * (870px on the live site) than the static pages (810px), so the height is a
 * prop; dots appear when there is more than one photo, as on the live covers.
 */
export function PageCover({
  title,
  subtitle,
  images,
  headingLevel = 'h1',
}: {
  title: string;
  subtitle: string;
  images: string[];
  /** A page may carry only one h1; a second cover on the same page uses h2. */
  headingLevel?: 'h1' | 'h2';
}) {
  const Heading = headingLevel;
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
          <Image
            src={src}
            alt={`${title} — салон цветов «Пион», Пермь${images.length > 1 ? `, фото ${i + 1}` : ''}`}
            fill
            priority={i === 0}
            className={styles.coverImage}
          />
        </div>
      ))}
      <div className={styles.coverOverlay} />
      <div className={styles.coverBody}>
        <Heading className={styles.coverTitle}>{title}</Heading>
        {subtitle && <p className={styles.coverSubtitle}>{subtitle}</p>}
      </div>

      {images.length > 1 && (
        <div className={styles.coverDots}>
          {images.map((src, i) => (
            <button
              key={src}
              type="button"
              aria-label={`Перейти к фото ${i + 1}`}
              className={i === index ? styles.coverDotActive : styles.coverDot}
              onClick={() => setIndex(i)}
            />
          ))}
        </div>
      )}
    </section>
  );
}
