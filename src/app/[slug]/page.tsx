import { notFound } from 'next/navigation';
import { CATEGORY_SLUGS, PAGE_SLUGS, getCatalog, getPage } from '@/lib/content';
import { CategoryGrid } from '@/components/CategoryGrid/CategoryGrid';
import { ContentBlocks } from '@/components/ContentBlocks/ContentBlocks';

export function generateStaticParams() {
  return [...CATEGORY_SLUGS, ...PAGE_SLUGS].map((slug) => ({ slug }));
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    return <CategoryGrid products={products} title={slug} subtitle="" />;
  }

  if ((PAGE_SLUGS as readonly string[]).includes(slug)) {
    const blocks = (await getPage(slug)) ?? [];
    return <ContentBlocks blocks={blocks} />;
  }

  notFound();
}
