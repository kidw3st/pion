import Image from 'next/image';
import type { PageSection } from '@/lib/types';
import { PageCover } from './PageCover';
import { ContactsSection } from './ContactsSection';
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

          case 'contacts':
            return (
              <ContactsSection
                key={i}
                title={section.title}
                intro={section.intro}
                phone={section.phone}
                email={section.email}
                address={section.address}
                hours={section.hours}
                vkHref={section.vkHref}
              />
            );

          case 'textImage':
            return (
              <section key={i} className={styles.textImage}>
                <div className={styles.textImageInner}>
                  <div className={styles.textImageBody}>
                    {section.title && <h2 className={styles.title}>{section.title}</h2>}
                    {section.body && <p className={styles.body}>{section.body}</p>}
                  </div>
                  <div className={styles.textImagePhoto}>
                    <Image src={section.image} alt="" fill sizes="560px" className={styles.cover} />
                  </div>
                </div>
              </section>
            );

          case 'cards':
            return (
              <section key={i} className={styles.cards}>
                <div className={styles.cardsInner}>
                  {section.items.map((item) => (
                    <div key={item.title + item.subtitle} className={styles.card}>
                      {item.image && (
                        <div className={styles.cardPhoto}>
                          <Image
                            src={item.image}
                            alt={item.title}
                            fill
                            sizes="260px"
                            className={styles.cover}
                          />
                        </div>
                      )}
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
