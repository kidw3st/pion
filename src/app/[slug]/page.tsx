import type { Metadata } from 'next';
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
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { buildMetadata, breadcrumbJsonLd, productListJsonLd } from '@/lib/seo';

export function generateStaticParams() {
  return [...CATEGORY_SLUGS, ...PAGE_SLUGS].map((slug) => ({ slug }));
}

/** Descriptions for the static pages, written to answer what a searcher wants. */
const PAGE_SEO: Record<string, { title: string; description: string }> = {
  about: {
    title: 'О салоне «Пион» в Перми',
    description:
      'Салон цветов и подарков «Пион» на ул. Газеты Звезда, 27 в Перми. Команда флористов, авторские букеты из свежесрезанных цветов.',
  },
  'delivery-and-payment': {
    title: 'Доставка и оплата',
    description:
      'Доставка букетов по Перми и самовывоз со скидкой 5% из салона на ул. Газеты Звезда, 27. Оплата картой онлайн или наличными.',
  },
  'flower-delivery': {
    title: 'Доставка цветов по Перми',
    description: 'Доставка цветов и букетов по Перми от салона «Пион». Фото букета перед отправкой.',
  },
  contacts: {
    title: 'Контакты',
    description:
      'Салон цветов «Пион»: Пермь, ул. Газеты Звезда, 27, тел. +7 342 258 45 45. Ежедневно с 10:00 до 22:00.',
  },
  uds: {
    title: 'Система лояльности UDS',
    description: 'Программа лояльности UDS салона цветов «Пион» в Перми: бонусы за покупки букетов и подарков.',
  },
  stock: {
    title: 'Акции и скидки',
    description:
      'Действующие акции салона цветов «Пион» в Перми: скидки на декор, искусственные цветы и вечерние букеты.',
  },
  policy: {
    title: 'Политика конфиденциальности',
    description: 'Политика обработки персональных данных салона цветов и подарков «Пион», Пермь.',
  },
};

export function generateMetadata({ params }: { params: { slug: string } }): Metadata {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const label = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;
    return buildMetadata({
      title: `${label} — купить с доставкой в Перми`,
      description: `${label} от салона «Пион» в Перми. Авторские композиции из свежих цветов, фото букета перед доставкой, самовывоз со скидкой 5%.`,
      path: `/${slug}/`,
    });
  }

  const page = PAGE_SEO[slug];
  if (page) return buildMetadata({ ...page, path: `/${slug}/` });

  return buildMetadata({
    title: 'Салон цветов «Пион»',
    description: 'Букеты, композиции и подарки с доставкой по Перми.',
    path: `/${slug}/`,
  });
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    const meta = getCategoryMeta(slug);
    const label = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;

    return (
      <main>
        <JsonLd
          data={breadcrumbJsonLd([
            { name: 'Главная', path: '/' },
            { name: 'Каталог', path: '/catalog/' },
            { name: label, path: `/${slug}/` },
          ])}
        />
        {products.length > 0 && <JsonLd data={productListJsonLd(products, `/${slug}/`)} />}

        {meta?.title && meta.covers.length > 0 && (
          <PageCover title={meta.title} subtitle={meta.sub ?? ''} images={meta.covers} />
        )}
        <CategoryGrid
          products={products}
          title={meta?.heading ?? (meta?.title ? '' : label)}
          subtitle={meta?.headingSub ?? ''}
          showNotFoundBand={meta?.hasNotFound ?? false}
        />
      </main>
    );
  }

  if ((PAGE_SLUGS as readonly string[]).includes(slug)) {
    const sections = (await getPage(slug)) ?? [];
    const label = PAGE_SEO[slug]?.title ?? slug;
    return (
      <>
        <JsonLd
          data={breadcrumbJsonLd([
            { name: 'Главная', path: '/' },
            { name: label, path: `/${slug}/` },
          ])}
        />
        <PageSections sections={sections} />
      </>
    );
  }

  notFound();
}
