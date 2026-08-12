import { notFound } from 'next/navigation';
import {
  CATEGORY_SLUGS,
  PAGE_SLUGS,
  CATEGORY_LABELS,
  getCatalog,
  getPage,
  getCategoryMeta,
} from '@/lib/content';
import { CategoryGrid } from '@/components/CategoryGrid/CategoryGrid';
import { PageCover } from '@/components/PageSections/PageCover';
import { PageSections } from '@/components/PageSections/PageSections';

export function generateStaticParams() {
  return [...CATEGORY_SLUGS, ...PAGE_SLUGS].map((slug) => ({ slug }));
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    const meta = getCategoryMeta(slug);
    const label = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;

    return (
      <main>
        {meta?.title && meta.covers.length > 0 && (
          <PageCover title={meta.title} subtitle={meta.sub ?? ''} images={meta.covers} height={870} />
        )}
        <CategoryGrid
          products={products}
          // The live site titles the grid with its own heading block only on
          // some categories; where it has none, fall back to the label so the
          // page is never a bare grid without a name.
          title={meta?.heading ?? (meta?.title ? '' : label)}
          subtitle={meta?.headingSub ?? ''}
          showNotFoundBand={meta?.hasNotFound ?? false}
        />
      </main>
    );
  }

  if ((PAGE_SLUGS as readonly string[]).includes(slug)) {
    const sections = (await getPage(slug)) ?? [];
    return <PageSections sections={sections} />;
  }

  notFound();
}
