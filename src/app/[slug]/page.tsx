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

/**
 * Title and description for every static page. Titles stay inside the 30–65
 * characters a result page shows before it truncates, and each description
 * carries the facts a searcher decides on — what it is, where, and how to get
 * it — in the 120–160 characters Google displays.
 */
const PAGE_SEO: Record<string, { title: string; description: string }> = {
  about: {
    title: 'О нас — салон цветов «Пион» в Перми',
    description:
      'Салон цветов и подарков «Пион» на ул. Газеты Звезда, 27 в Перми. Команда флористов, авторские букеты из свежесрезанных цветов, доставка по городу.',
  },
  'delivery-and-payment': {
    title: 'Доставка и оплата букетов в Перми | Пион',
    description:
      'Доставка букетов по Перми и самовывоз со скидкой 5% из салона на ул. Газеты Звезда, 27. Оплата картой онлайн или наличными при получении.',
  },
  'flower-delivery': {
    title: 'Цветочная подписка в Перми | Салон «Пион»',
    description:
      'Цветочная подписка от салона «Пион»: свежие букеты в Перми каждую неделю или раз в месяц. Доставка по городу, фото букета перед отправкой.',
  },
  contacts: {
    title: 'Контакты салона цветов «Пион» в Перми',
    description:
      'Салон цветов «Пион»: Пермь, ул. Газеты Звезда, 27, телефон +7 342 258 45 45. Работаем ежедневно с 10:00 до 22:00 — заезжайте или позвоните.',
  },
  uds: {
    title: 'Программа лояльности UDS | Салон «Пион»',
    description:
      'Программа лояльности UDS салона «Пион» в Перми: бонусы за каждую покупку букетов и подарков, которыми можно оплатить следующий заказ.',
  },
  stock: {
    title: 'Акции и скидки на цветы в Перми | Салон «Пион»',
    description:
      'Действующие акции салона цветов «Пион» в Перми: скидки на декор, искусственные цветы и вечерние букеты, самовывоз из салона со скидкой 5%.',
  },
  policy: {
    title: 'Политика конфиденциальности | Салон «Пион»',
    description:
      'Политика обработки персональных данных салона цветов и подарков «Пион», Пермь: какие данные мы собираем, зачем они нужны и как мы их защищаем.',
  },
  valentinesday: {
    title: 'Букеты на 14 февраля в Перми | Салон «Пион»',
    description:
      'Букеты и подарочные боксы к 14 февраля от салона «Пион» в Перми. Композиции из свежих цветов, доставка по городу и самовывоз со скидкой 5%.',
  },
  'new-year-2025': {
    title: 'Новогодние букеты 2025 в Перми | Салон «Пион»',
    description:
      'Новогодняя коллекция 2025 салона цветов «Пион» в Перми: еловые композиции, зимние букеты и подарочные боксы с доставкой по городу.',
  },
  doza_endorfina: {
    title: 'Доза эндорфина — охапки цветов в Перми',
    description:
      '«Доза эндорфина» — большие охапки цветов от салона «Пион» в Перми. Много цветов и много радости, с доставкой по городу в день заказа.',
  },
  flowers: {
    title: 'Цветы поштучно и букеты в Перми | Салон «Пион»',
    description:
      'Цветы поштучно и букеты в Перми: пионы, розы, микс из свежесрезанных цветов. Салон «Пион», доставка по городу и самовывоз со скидкой 5%.',
  },
  indoorflowers: {
    title: 'Комнатные растения в Перми | Салон «Пион»',
    description:
      'Комнатные растения в Перми от салона «Пион»: живые цветы в горшках для дома и офиса, с доставкой по городу и самовывозом из салона.',
  },
};

export function generateMetadata({ params }: { params: { slug: string } }): Metadata {
  const { slug } = params;

  // Titles are absolute so the layout's " | Салон цветов «Пион», Пермь" suffix
  // cannot push them past the length a search result shows.
  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const label = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;
    // Seasonal sections carry hand-written titles in PAGE_SEO (they used to be
    // content pages); the template covers the rest.
    const custom = PAGE_SEO[slug];
    const title = custom?.title ?? `${label} с доставкой в Перми | Салон «Пион»`;
    return {
      ...buildMetadata({
        title,
        description:
          custom?.description ??
          `${label} от салона «Пион» в Перми. Авторские композиции из свежих цветов, фото букета перед доставкой, самовывоз со скидкой 5%.`,
        path: `/${slug}/`,
      }),
      title: { absolute: title },
    };
  }

  const page = PAGE_SEO[slug];
  if (page) {
    return {
      ...buildMetadata({ ...page, path: `/${slug}/` }),
      title: { absolute: page.title },
    };
  }

  return buildMetadata({
    title: 'Салон цветов «Пион»',
    description:
      'Букеты, композиции и подарки с доставкой по Перми от салона «Пион» на ул. Газеты Звезда, 27. Работаем ежедневно с 10:00 до 22:00.',
    path: `/${slug}/`,
  });
}

export default async function SlugPage({ params }: { params: { slug: string } }) {
  const { slug } = params;

  if ((CATEGORY_SLUGS as readonly string[]).includes(slug)) {
    const products = (await getCatalog(slug)) ?? [];
    const meta = getCategoryMeta(slug);
    const label = CATEGORY_LABELS[slug as keyof typeof CATEGORY_LABELS] ?? slug;
    const coverTitle = meta?.covers.length ? meta.title : null;

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

        {coverTitle && (
          <PageCover title={coverTitle} subtitle={meta?.sub ?? ''} images={meta!.covers} />
        )}
        <CategoryGrid
          products={products}
          title={meta?.heading ?? (meta?.title ? '' : label)}
          subtitle={meta?.headingSub ?? ''}
          showNotFoundBand={meta?.hasNotFound ?? false}
          headingLevel={coverTitle ? 'h2' : 'h1'}
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
