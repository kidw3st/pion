import type { ContentBlock } from '@/lib/types';

export function ContentBlocks({ blocks }: { blocks: ContentBlock[] }) {
  return (
    <main style={{ padding: '48px 40px', maxWidth: '800px', margin: '0 auto' }}>
      {blocks.map((block, idx) => {
        switch (block.type) {
          case 'heading':
            return <h1 key={idx} style={{ marginBottom: '24px' }}>{block.text}</h1>;
          case 'paragraph':
            return <p key={idx} style={{ marginBottom: '16px', lineHeight: '1.6' }}>{block.text}</p>;
          case 'image':
            return (
              <div key={idx} style={{ marginBottom: '24px' }}>
                <img
                  src={block.src}
                  alt={block.alt}
                  style={{ maxWidth: '100%', height: 'auto', borderRadius: '8px' }}
                />
              </div>
            );
          case 'cta':
            return (
              <div key={idx} style={{ marginBottom: '24px' }}>
                <a
                  href={block.href}
                  style={{
                    display: 'inline-block',
                    padding: '12px 24px',
                    backgroundColor: '#007bff',
                    color: '#fff',
                    textDecoration: 'none',
                    borderRadius: '4px',
                  }}
                >
                  {block.text}
                </a>
              </div>
            );
          default:
            return null;
        }
      })}
    </main>
  );
}
