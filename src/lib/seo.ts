import type { Metadata } from 'next';
import { getSite } from './content';

/**
 * Абсолютный адрес сайта. Значение подставляет next.config.mjs — там же
 * решается, боевая это сборка или копия для GitHub Pages. Запасной вариант
 * здесь — именно боевой адрес: если переменная почему-то не доехала, страницы
 * должны указывать на pionperm.ru, а не на тестовую копию.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL || 'https://pionperm.ru';

export const CITY = 'Пермь';

export function absoluteUrl(path = '/'): string {
  return `${SITE_URL}${path.startsWith('/') ? path : `/${path}`}`;
}

/** Formats a price the way the shop writes them: "4 830 ₽". */
export function formatPrice(rub: number): string {
  return `${rub.toLocaleString('ru-RU')} ₽`;
}

/**
 * Builds page metadata with the city in the title, since "букеты Пермь" is how
 * the shop is actually searched for — a bare "Букеты" competes with the whole
 * country. Descriptions carry the concrete facts a searcher decides on: what
 * it is, where, from what price, and how to reach the salon.
 */
export function buildMetadata({
  title,
  description,
  path,
  image,
}: {
  title: string;
  description: string;
  path: string;
  image?: string;
}): Metadata {
  const url = absoluteUrl(path);
  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: {
      title,
      description,
      url,
      siteName: 'Салон цветов и подарков «Пион»',
      locale: 'ru_RU',
      type: 'website',
      images: [{ url: absoluteUrl(image ?? '/images/site/logo.webp') }],
    },
  };
}

/**
 * LocalBusiness/Florist card describing the salon itself. Every value comes
 * from data/site.json, so the structured data cannot drift from what the
 * header and footer display.
 */
export function localBusinessJsonLd() {
  const site = getSite();
  return {
    '@context': 'https://schema.org',
    '@type': 'Florist',
    '@id': `${SITE_URL}/#florist`,
    name: 'Салон цветов и подарков «Пион»',
    url: absoluteUrl('/'),
    image: absoluteUrl('/images/site/logo.webp'),
    telephone: site.phone,
    address: {
      '@type': 'PostalAddress',
      streetAddress: 'ул. Газеты Звезда, 27',
      addressLocality: CITY,
      addressCountry: 'RU',
    },
    // The footer states the same hours for every day of the week.
    openingHoursSpecification: [
      {
        '@type': 'OpeningHoursSpecification',
        dayOfWeek: [
          'Monday',
          'Tuesday',
          'Wednesday',
          'Thursday',
          'Friday',
          'Saturday',
          'Sunday',
        ],
        opens: '10:00',
        closes: '22:00',
      },
    ],
    sameAs: site.social.map((s) => s.href),
    priceRange: '₽₽',
  };
}

export function breadcrumbJsonLd(trail: { name: string; path: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: trail.map((item, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: item.name,
      item: absoluteUrl(item.path),
    })),
  };
}

/**
 * Product list for a category page. Availability is deliberately omitted: the
 * salon's own copy says stock is confirmed when the order is placed, so
 * asserting InStock here would claim more than the shop does.
 */
/** Постоянный адрес товара. Один на весь проект, чтобы ссылки не разошлись. */
export function productPath(category: string, slug: string): string {
  return `/${category}/${slug}/`;
}

/**
 * Карточка товара для поисковика. `offers.url` ведёт на саму страницу товара —
 * до появления отдельных страниц все предложения указывали на раздел, и
 * поисковику было нечего показать по конкретному букету.
 *
 * Наличие не указываем жёстко: букеты собирают под заказ из того, что есть в
 * это утро, поэтому честнее сказать «под заказ», чем обещать склад.
 */
export function productJsonLd(
  product: { title: string; description: string; price: number; images: string[] },
  category: string,
  slug: string,
) {
  const url = absoluteUrl(productPath(category, slug));
  return {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.title,
    description: product.description || undefined,
    image: product.images.map((i) => absoluteUrl(i)),
    url,
    brand: { '@type': 'Brand', name: 'Пион' },
    offers: {
      '@type': 'Offer',
      price: product.price,
      priceCurrency: 'RUB',
      url,
      availability: 'https://schema.org/PreOrder',
      seller: { '@type': 'Organization', name: 'Салон цветов и подарков «Пион»' },
      areaServed: CITY,
    },
  };
}

export function productListJsonLd(
  products: {
    uid: string;
    title: string;
    description: string;
    price: number;
    images: string[];
    slug: string;
  }[],
  categoryPath: string,
) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    itemListElement: products.map((p, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      item: {
        '@type': 'Product',
        name: p.title,
        description: p.description || undefined,
        image: p.images[0] ? absoluteUrl(p.images[0]) : undefined,
        // Ссылка на страницу самого товара, а не на раздел: иначе поисковик
        // видит список из ста предложений по одному адресу.
        url: absoluteUrl(productPath(categoryPath.replace(/\//g, ''), p.slug)),
        offers: {
          '@type': 'Offer',
          price: p.price,
          priceCurrency: 'RUB',
          url: absoluteUrl(productPath(categoryPath.replace(/\//g, ''), p.slug)),
        },
      },
    })),
  };
}
