import type { SiteData } from '@/lib/types';
import styles from './ReputationBar.module.css';

/**
 * Награда и рейтинг салона.
 *
 * Премия 2ГИС «Лучший цветочный салон 2026» и 4,9 при 258 оценках — самое
 * сильное доказательство на этом рынке, и до сих пор его видел только тот, кто
 * зашёл в 2ГИС. Посетитель сайта не знал ничего. Ссылка ведёт на саму
 * карточку: заявление, которое можно проверить в один клик, работает, а
 * непроверяемое — нет.
 *
 * Цифры лежат в data/site.json — они меняются, и править их должно быть
 * одно место, а не разметка.
 */
export function ReputationBar({ data }: { data: NonNullable<SiteData['reputation']> }) {
  return (
    <section className={styles.bar} aria-label="Оценки и награды салона">
      <div className={styles.inner}>
        <div className={styles.award}>
          <svg className={styles.icon} viewBox="0 0 24 24" aria-hidden="true" width="34" height="34">
            <path
              d="M12 2.5l2.6 5.3 5.9.85-4.25 4.15 1 5.85L12 15.9l-5.25 2.75 1-5.85L3.5 8.65l5.9-.85L12 2.5z"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.4"
              strokeLinejoin="round"
            />
          </svg>
          <p className={styles.awardText}>{data.award}</p>
        </div>

        <div className={styles.rating}>
          <span className={styles.ratingValue}>{data.rating}</span>
          <span className={styles.ratingOf}>из 5</span>
          <span className={styles.ratingMeta}>
            {data.ratingsCount} оценок в {data.source}
          </span>
        </div>

        <a
          className={styles.link}
          href={data.url}
          target="_blank"
          rel="noreferrer noopener"
        >
          Посмотреть отзывы
        </a>
      </div>
    </section>
  );
}
