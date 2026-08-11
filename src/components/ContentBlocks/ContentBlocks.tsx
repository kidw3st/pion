import Image from 'next/image';
import Link from 'next/link';
import type { ContentBlock } from '@/lib/types';
import styles from './ContentBlocks.module.css';

function isExternalHref(href: string): boolean {
  return href.startsWith('http://') || href.startsWith('https://');
}

export function ContentBlocks({ blocks }: { blocks: ContentBlock[] }) {
  return (
    <main className={styles.page}>
      {blocks.map((block, i) => {
        switch (block.type) {
          case 'heading':
            return <h1 key={i} className={styles.heading}>{block.text}</h1>;
          case 'paragraph':
            return <p key={i} className={styles.paragraph}>{block.text}</p>;
          case 'image':
            return (
              <div key={i} className={styles.imageWrap}>
                <Image src={block.src} alt={block.alt} width={800} height={533} className={styles.image} />
              </div>
            );
          case 'cta':
            return isExternalHref(block.href) ? (
              <a key={i} href={block.href} target="_blank" rel="noreferrer noopener" className={styles.cta}>
                {block.text}
              </a>
            ) : (
              <Link key={i} href={block.href} className={styles.cta}>{block.text}</Link>
            );
          default:
            return null;
        }
      })}
    </main>
  );
}
