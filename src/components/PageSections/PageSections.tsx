import Image from 'next/image';
import Link from 'next/link';
import type { PageSection } from '@/lib/types';
import { PageCover } from './PageCover';
import { ContactsSection } from './ContactsSection';
import { PageProducts } from './PageProducts';
import styles from './PageSections.module.css';

/** True for the section kinds whose title is the page's own name, not a subhead. */
function carriesTitle(section: PageSection): boolean {
  return (
    (section.kind === 'cover' && !!section.title) ||
    (section.kind === 'text' && !!section.title) ||
    (section.kind === 'textImage' && !!section.title) ||
    (section.kind === 'contacts' && !!section.title)
  );
}

export function PageSections({ sections }: { sections: PageSection[] }) {
  // Every page needs exactly one h1. Most open with a cover that supplies it;
  // the rest (the policy text, "Доза эндорфина") lead with a titled block, so
  // that first title is promoted and every later one stays an h2.
  const h1Index = sections.findIndex(carriesTitle);

  return (
    <main>
      {sections.map((section, i) => {
        const isPageTitle = i === h1Index;

        switch (section.kind) {
          case 'cover':
            return (
              <PageCover
                key={i}
                title={section.title}
                subtitle={section.subtitle}
                images={section.images}
                headingLevel={isPageTitle ? 'h1' : 'h2'}
              />
            );

          case 'text':
            return (
              <section key={i} className={styles.text}>
                <div className={styles.inner}>
                  {section.title &&
                    (isPageTitle ? (
                      <h1 className={styles.title}>{section.title}</h1>
                    ) : (
                      <h2 className={styles.title}>{section.title}</h2>
                    ))}
                  {section.body && <p className={styles.body}>{section.body}</p>}
                </div>
              </section>
            );

          case 'tiles':
            return (
              <section key={i} className={styles.tiles}>
                {section.tiles.map((tile) => (
                  <Link key={tile.href} href={tile.href} className={styles.tile}>
                    {tile.image && (
                      <Image src={tile.image} alt="" fill sizes="33vw" className={styles.cover} />
                    )}
                    <span className={styles.tileShade} />
                    <span className={styles.tileLabel}>{tile.label}</span>
                  </Link>
                ))}
              </section>
            );

          case 'products':
            return <PageProducts key={i} items={section.items} />;

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
                headingLevel={isPageTitle ? 'h1' : 'h2'}
              />
            );

          case 'textImage':
            return (
              <section key={i} className={styles.textImage}>
                <div className={styles.textImageInner}>
                  <div className={styles.textImageBody}>
                    {section.title &&
                      (isPageTitle ? (
                        <h1 className={styles.title}>{section.title}</h1>
                      ) : (
                        <h2 className={styles.title}>{section.title}</h2>
                      ))}
                    {section.body && <p className={styles.body}>{section.body}</p>}
                  </div>
                  <div className={styles.textImagePhoto}>
                    <Image
                      src={section.image}
                      alt={section.title || 'Салон цветов «Пион» в Перми'}
                      fill
                      sizes="560px"
                      className={styles.cover}
                    />
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
                    <Image
                      src={section.image}
                      alt="Букет от флористов салона цветов «Пион», Пермь"
                      width={860}
                      height={573}
                    />
                  </div>
                </div>
              </section>
            );

          case 'gallery':
            return (
              <section key={i} className={styles.gallery}>
                <div className={styles.galleryInner}>
                  {section.images.map((src, shot) => (
                    <div key={src} className={styles.galleryShot}>
                      <Image
                        src={src}
                        alt={`Работа флористов салона «Пион» — фото ${shot + 1}`}
                        fill
                        sizes="33vw"
                        className={styles.galleryImage}
                      />
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
