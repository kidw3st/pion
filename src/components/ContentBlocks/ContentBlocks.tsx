import Image from 'next/image';
import type { ContentBlock } from '@/lib/types';
import styles from './ContentBlocks.module.css';

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
            return (
              <a key={i} href={block.href} className={styles.cta}>{block.text}</a>
            );
          default:
            return null;
        }
      })}
    </main>
  );
}
