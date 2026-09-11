import type { Metadata } from 'next';
import Link from 'next/link';
import Image from 'next/image';
import { notFound } from 'next/navigation';
import {
  CATEGORY_LABELS,
  getProduct,
  getRelatedProducts,
  getAllProductParams,
  getAmbiguousTitles,
  getSite,
} from '@/lib/content';
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { AddToCart } from '@/components/ProductPage/AddToCart';
import { Gallery } from '@/components/Gallery/Gallery';
import { buildMetadata, breadcrumbJsonLd, productJsonLd, productPath } from '@/lib/seo';
import styles from './page.module.css';

/**
 * Страница одного букета.
 *
 * До неё каталог был витриной: карточки — это `div` без ссылок, и на
 * конкретный букет нельзя было ни зайти, ни сослаться, ни привести рекламу.
 * Поисковику было нечего показывать по запросам вроде «букет из пионов Пермь»,
 * потому что у нас под такой запрос не существовало страницы.
 */

export async function generateStaticParams() {
  return getAllProductParams();
}

// Состав в данных записан как «Состав букета: Роза одноголосая, Алиум» —
// в описание для поисковика он идёт как есть, но без повторов слова «состав».
function compositionLine(description: string): string {
  return description.replace(/^Состав\s+\S+:\s*/i, '').trim();
}

export async function generateMetadata({
  params,
}: {
  params: { slug: string; product: string };
}): Promise<Metadata> {
  const product = await getProduct(params.slug, params.product);
  if (!product) return {};

  const composition = compositionLine(product.description);
  // Одно и то же название бывает в двух разделах у разных товаров. Тогда в
  // заголовок добавляется раздел — иначе две страницы выглядят для поисковика
  // одинаково, и он показывает только одну из них.
  const ambiguous = await getAmbiguousTitles();
  const label = CATEGORY_LABELS[params.slug as keyof typeof CATEGORY_LABELS] ?? params.slug;
  const short = product.title.trim().toLowerCase();
  // «Пион» в разделе «Пионы» подписывать нечем — вышло бы «Пион — пионы».
  // Достаточно, что подпись получит вторая страница пары: заголовки станут
  // разными, а этот останется коротким и читаемым.
  const needsLabel =
    ambiguous.has(short) && !label.toLowerCase().includes(short) && !short.includes(label.toLowerCase());
  const name = needsLabel ? `${product.title} — ${label.toLowerCase()}` : product.title;
  const title = `${name} — купить в Перми | Салон «Пион»`;

  return {
    ...buildMetadata({
      title,
      description: [
        `${product.title} за ${product.price.toLocaleString('ru-RU')} ₽ с доставкой по Перми.`,
        composition && `Состав: ${composition}.`,
        'Фото букета перед доставкой, самовывоз со скидкой 5%.',
      ]
        .filter(Boolean)
        .join(' '),
      path: productPath(params.slug, params.product),
      image: product.images[0],
    }),
    title: { absolute: title },
  };
}

export default async function ProductPage({
  params,
}: {
  params: { slug: string; product: string };
}) {
  const product = await getProduct(params.slug, params.product);
  if (!product) notFound();

  const related = await getRelatedProducts(params.slug, params.product);
  const site = getSite();
  const label = CATEGORY_LABELS[params.slug as keyof typeof CATEGORY_LABELS] ?? params.slug;
  const composition = compositionLine(product.description);
  const pickup = site.delivery.options.find((o) => o.id === 'pickup');
  const cheapest = site.delivery.options
    .filter((o) => o.priceRub > 0)
    .sort((a, b) => a.priceRub - b.priceRub)[0];

  return (
    <main className={styles.page}>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: 'Главная', path: '/' },
          { name: 'Каталог', path: '/catalog/' },
          { name: label, path: `/${params.slug}/` },
          { name: product.title, path: productPath(params.slug, params.product) },
        ])}
      />
      <JsonLd data={productJsonLd(product, params.slug, params.product)} />

      <nav className={styles.crumbs} aria-label="Вы здесь">
        <Link href="/">Главная</Link>
        <span aria-hidden="true">/</span>
        <Link href="/catalog">Каталог</Link>
        <span aria-hidden="true">/</span>
        <Link href={`/${params.slug}`}>{label}</Link>
      </nav>

      <div className={styles.layout}>
        <Gallery
          images={product.images}
          alt={product.title}
          sizes="(max-width: 900px) 100vw, 560px"
          priority
        />

        <div className={styles.info}>
          <h1 className={styles.title}>{product.title}</h1>
          <p className={styles.price}>{product.price.toLocaleString('ru-RU')} ₽</p>

          {composition && (
            <div className={styles.block}>
              <h2 className={styles.blockTitle}>Состав</h2>
              <p className={styles.composition}>{composition}</p>
            </div>
          )}

          <AddToCart product={product} />

          <p className={styles.assurance}>
            Соберём и пришлём фото букета перед доставкой — если что-то не понравится,
            переделаем.
          </p>

          <div className={styles.block}>
            <h2 className={styles.blockTitle}>Доставка и оплата</h2>
            <ul className={styles.facts}>
              {cheapest && (
                <li>
                  Доставка по Перми от {cheapest.priceRub.toLocaleString('ru-RU')} ₽ — стоимость
                  зависит от расстояния до салона.
                </li>
              )}
              {pickup && (
                <li>
                  Самовывоз с ул. Газеты Звезда, 27 — скидка {pickup.discountPercent}% на заказ.
                </li>
              )}
              <li>Оплата картой онлайн или наличными при получении.</li>
              <li>Работаем ежедневно с 10:00 до 22:00, телефон {site.phone}.</li>
            </ul>
            <Link href="/delivery-and-payment" className={styles.more}>
              Подробнее об условиях
            </Link>
          </div>
        </div>
      </div>

      {related.length > 0 && (
        <section className={styles.related}>
          <h2 className={styles.relatedTitle}>Ещё из раздела «{label}»</h2>
          <ul className={styles.relatedGrid}>
            {related.map((p) => (
              <li key={p.uid}>
                <Link href={productPath(params.slug, p.slug)} className={styles.relatedCard}>
                  <span className={styles.relatedPhoto}>
                    {p.images[0] && (
                      <Image src={p.images[0]} alt={p.title} fill sizes="260px" className={styles.photoImg} />
                    )}
                  </span>
                  <span className={styles.relatedName}>{p.title}</span>
                  <span className={styles.relatedPrice}>{p.price.toLocaleString('ru-RU')} ₽</span>
                </Link>
              </li>
            ))}
          </ul>
          <Link href={`/${params.slug}`} className={styles.more}>
            Весь раздел «{label}»
          </Link>
        </section>
      )}
    </main>
  );
}
