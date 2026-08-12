import { notFound } from 'next/navigation';
import { CATEGORY_SLUGS, PAGE_SLUGS, CATEGORY_LABELS, getCatalog, getPage } from '@/lib/content';
import { CategoryGrid } from '@/components/CategoryGrid/CategoryGrid';
import { PageSections } from '@/components/PageSections/PageSections';

export function generateStaticParams() {
  return [...CATEGORY_SLUGS, ...PAGE_SLUGS].map((slug) => ({ slug }));
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    const title = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;
    return <CategoryGrid products={products} title={title} subtitle="" />;
  }

  if ((PAGE_SLUGS as readonly string[]).includes(slug)) {
    const sections = (await getPage(slug)) ?? [];
    return <PageSections sections={sections} />;
  }

  notFound();
}
