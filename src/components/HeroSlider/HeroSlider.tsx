'use client';

import { useState, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import type { HeroSlide } from '@/lib/types';
import styles from './HeroSlider.module.css';

/**
 * Anything that is not a path of ours points somewhere else. A bare
 * "instagram.com/pionperm" counts: treated as internal it becomes a link to a
 * page of this site that does not exist, which is how the slider's Instagram
 * button used to 404 instead of opening Instagram.
 */
function isExternalHref(href: string): boolean {
  return !href.startsWith('/') && !href.startsWith('#');
}

/** Gives a protocol-less external link the scheme the browser needs. */
function externalUrl(href: string): string {
  return /^[a-z][a-z0-9+.-]*:/i.test(href) ? href : `https://${href}`;
}

// Chevron used by the live slider's side arrows: a stroked polyline in a
// 17.3x33 box, pointing left; the right arrow is the same shape mirrored.
function Chevron() {
  return (
    <svg viewBox="0 0 17.3 33" width="17" height="32" fill="none" aria-hidden="true">
      <polyline points="16.5,0.5 0.5,16.5 16.5,32.5" stroke="currentColor" strokeWidth="1" />
    </svg>
  );
}

export function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const [index, setIndex] = useState(0);

  const go = useCallback(
    (delta: number) => {
      setIndex((i) => (i + delta + slides.length) % slides.length);
    },
    [slides.length],
  );

  useEffect(() => {
    if (slides.length <= 1) return;
    const timer = setInterval(() => go(1), 5000);
    return () => clearInterval(timer);
  }, [go, slides.length, index]);

  if (slides.length === 0) return null;
  const slide = slides[index];

  return (
    <div className={styles.slider}>
      <div className={styles.imageWrap}>
        <Image src={slide.image} alt={slide.title} fill className={styles.image} priority />
        <div className={styles.overlay} />
      </div>

      <div className={styles.inner}>
        <div className={styles.content}>
          <h2 className={slide.benefits ? styles.offerTitle : styles.title}>{slide.title}</h2>
          {slide.benefits && <p className={styles.benefits}>{slide.benefits}</p>}
          {slide.subtitle && <p className={styles.subtitle}>{slide.subtitle}</p>}

          <div className={styles.actions}>
            {isExternalHref(slide.buttonHref) ? (
              <a
                href={externalUrl(slide.buttonHref)}
                target="_blank"
                rel="noreferrer noopener"
                className={styles.button}
              >
                {slide.buttonText}
              </a>
            ) : (
              <Link href={slide.buttonHref} className={styles.button}>
                {slide.buttonText}
              </Link>
            )}

            {slide.secondaryButtonText && slide.secondaryButtonHref && (
              <a href={slide.secondaryButtonHref} className={styles.buttonSecondary}>
                {slide.secondaryButtonText}
              </a>
            )}
          </div>
        </div>
      </div>

      {slides.length > 1 && (
        <>
          <button
            type="button"
            className={`${styles.arrow} ${styles.arrowLeft}`}
            onClick={() => go(-1)}
            aria-label="Предыдущий слайд"
          >
            <Chevron />
          </button>
          <button
            type="button"
            className={`${styles.arrow} ${styles.arrowRight}`}
            onClick={() => go(1)}
            aria-label="Следующий слайд"
          >
            <Chevron />
          </button>

          <div className={styles.dots}>
            {slides.map((s, i) => (
              <button
                key={s.title + i}
                type="button"
                aria-label={`Перейти к слайду ${i + 1}`}
                className={i === index ? styles.dotActive : styles.dot}
                onClick={() => setIndex(i)}
              />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
