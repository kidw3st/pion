'use client';

import { useState, useEffect, useCallback } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import type { HeroSlide } from '@/lib/types';
import styles from './HeroSlider.module.css';

function isExternalHref(href: string): boolean {
  return href.startsWith('http://') || href.startsWith('https://');
}

export function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const [index, setIndex] = useState(0);

  const next = useCallback(() => {
    setIndex((i) => (i + 1) % slides.length);
  }, [slides.length]);

  useEffect(() => {
    if (slides.length <= 1) return;
    const timer = setInterval(next, 5000);
    return () => clearInterval(timer);
  }, [next, slides.length, index]);

  if (slides.length === 0) return null;
  const slide = slides[index];

  return (
    <div className={styles.slider}>
      <div className={styles.imageWrap}>
        <Image src={slide.image} alt={slide.title} fill className={styles.image} priority />
      </div>
      <div className={styles.content}>
        <h2>{slide.title}</h2>
        <p>{slide.subtitle}</p>
        {isExternalHref(slide.buttonHref) ? (
          <a href={slide.buttonHref} target="_blank" rel="noreferrer noopener" className={styles.button}>
            {slide.buttonText}
          </a>
        ) : (
          <Link href={slide.buttonHref} className={styles.button}>{slide.buttonText}</Link>
        )}
      </div>
      <div className={styles.dots}>
        {slides.map((s, i) => (
          <button
            key={s.title + i}
            aria-label={`Перейти к слайду ${i + 1}`}
            className={i === index ? styles.dotActive : styles.dot}
            onClick={() => setIndex(i)}
          />
        ))}
      </div>
    </div>
  );
}
