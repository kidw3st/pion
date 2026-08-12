import Image from 'next/image';
import type { PageSection } from '@/lib/types';
import { PageCover } from './PageCover';
import styles from './PageSections.module.css';

export function PageSections({ sections }: { sections: PageSection[] }) {
  return (
    <main>
      {sections.map((section, i) => {
        switch (section.kind) {
          case 'cover':
            return (
              <PageCover
                key={i}
                title={section.title}
                subtitle={section.subtitle}
                images={section.images}
              />
            );

          case 'text':
            return (
              <section key={i} className={styles.text}>
                <div className={styles.inner}>
                  {section.title && <h2 className={styles.title}>{section.title}</h2>}
                  {section.body && <p className={styles.body}>{section.body}</p>}
                </div>
              </section>
            );

          case 'cards':
            return (
              <section key={i} className={styles.cards}>
                <div className={styles.cardsInner}>
                  {section.items.map((item) => (
                    <div key={item.title + item.subtitle} className={styles.card}>
                      <h3 className={styles.cardTitle}>{item.title}</h3>
                      <p className={styles.cardSubtitle}>{item.subtitle}</p>
                    </div>
                  ))}
                </div>
              </section>
            );

          case 'quote':
            return (
              <section key={i} className={styles.quote}>
                <div className={styles.inner}>
                  <p className={styles.quoteText}>{section.text}</p>
                  <div className={styles.quoteImage}>
                    <Image src={section.image} alt="" width={860} height={573} />
                  </div>
                </div>
              </section>
            );

          case 'gallery':
            return (
              <section key={i} className={styles.gallery}>
                <div className={styles.galleryInner}>
                  {section.images.map((src) => (
                    <div key={src} className={styles.galleryShot}>
                      <Image src={src} alt="" fill sizes="33vw" className={styles.galleryImage} />
                    </div>
                  ))}
                </div>
              </section>
            );

          default:
            return null;
        }
      })}
    </main>
  );
}
