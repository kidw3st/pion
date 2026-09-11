'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import styles from './Gallery.module.css';

/**
 * Карусель фотографий товара.
 *
 * У 200 букетов из 522 снято два-четыре кадра, и до этого посетитель видел
 * только первый: остальные лежали в данных мёртвым грузом. Здесь они
 * листаются — стрелками, точками, свайпом и клавишами.
 *
 * С одним фото карусель не рисует ни стрелок, ни точек и ведёт себя как
 * обычная картинка, поэтому её можно ставить везде, не проверяя количество.
 */
export function Gallery({
  images,
  alt,
  sizes,
  priority = false,
  compact = false,
  className,
}: {
  images: string[];
  alt: string;
  sizes: string;
  priority?: boolean;
  /** Тесный вариант для карточек: квадрат и без ленты миниатюр. */
  compact?: boolean;
  className?: string;
}) {
  const [index, setIndex] = useState(0);
  const touchStartX = useRef<number | null>(null);
  const frameRef = useRef<HTMLDivElement>(null);

  const count = images.length;
  const go = useCallback(
    (next: number) => setIndex(((next % count) + count) % count),
    [count],
  );

  // Стрелки на клавиатуре работают, когда галерея в фокусе, — иначе они
  // перехватывали бы прокрутку страницы у всех подряд.
  useEffect(() => {
    const el = frameRef.current;
    if (!el || count < 2) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        go(index - 1);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        go(index + 1);
      }
    };
    el.addEventListener('keydown', onKey);
    return () => el.removeEventListener('keydown', onKey);
  }, [count, go, index]);

  if (count === 0) {
    return <div className={`${styles.frame} ${styles.empty} ${className ?? ''}`} />;
  }

  return (
    <div className={`${styles.gallery} ${compact ? styles.compact : ''} ${className ?? ''}`}>
      <div
        ref={frameRef}
        className={styles.frame}
        tabIndex={count > 1 ? 0 : -1}
        role={count > 1 ? 'group' : undefined}
        aria-label={count > 1 ? `${alt}: фото ${index + 1} из ${count}` : undefined}
        onTouchStart={(e) => {
          touchStartX.current = e.touches[0]?.clientX ?? null;
        }}
        onTouchEnd={(e) => {
          const start = touchStartX.current;
          touchStartX.current = null;
          if (start === null || count < 2) return;
          const delta = (e.changedTouches[0]?.clientX ?? start) - start;
          // Меньше 40 пикселей — это не свайп, а дрожь пальца при прокрутке.
          if (Math.abs(delta) < 40) return;
          go(delta < 0 ? index + 1 : index - 1);
        }}
      >
        {images.map((src, i) => (
          <Image
            key={src}
            src={src}
            // Первый кадр подписан названием, остальные — с номером: так
            // поиск по картинкам понимает, что это тот же товар с другого
            // ракурса, а не дубль.
            alt={i === 0 ? alt : `${alt} — фото ${i + 1}`}
            fill
            sizes={sizes}
            className={`${styles.photo} ${i === index ? styles.photoActive : ''}`}
            priority={priority && i === 0}
            // Соседний кадр грузим заранее, остальные — по мере надобности.
            loading={priority && i === 0 ? undefined : 'lazy'}
            aria-hidden={i === index ? undefined : true}
          />
        ))}

        {count > 1 && (
          <>
            <button
              type="button"
              className={`${styles.arrow} ${styles.arrowPrev}`}
              onClick={() => go(index - 1)}
              aria-label="Предыдущее фото"
            >
              <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                <path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>
            <button
              type="button"
              className={`${styles.arrow} ${styles.arrowNext}`}
              onClick={() => go(index + 1)}
              aria-label="Следующее фото"
            >
              <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                <path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </button>

            <div className={styles.dots}>
              {images.map((src, i) => (
                <button
                  key={src}
                  type="button"
                  className={`${styles.dot} ${i === index ? styles.dotActive : ''}`}
                  onClick={() => go(i)}
                  aria-label={`Фото ${i + 1}`}
                  aria-current={i === index || undefined}
                />
              ))}
            </div>
          </>
        )}
      </div>

      {count > 1 && (
        <ul className={styles.thumbs}>
          {images.map((src, i) => (
            <li key={src}>
              <button
                type="button"
                className={`${styles.thumb} ${i === index ? styles.thumbActive : ''}`}
                onClick={() => go(i)}
                aria-label={`Показать фото ${i + 1}`}
              >
                <Image src={src} alt="" fill sizes="120px" className={styles.thumbImg} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
