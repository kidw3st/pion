import type { Metadata } from 'next';
import Link from 'next/link';
import Image from 'next/image';
import { notFound } from 'next/navigation';
import { getSite } from '@/lib/content';
import {
  getAllProductParams,
  getAmbiguousTitles,
  getProduct,
  getRelatedProducts,
  getSectionLabel,
} from '@/lib/catalog';
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
 *
 * Страницы есть у букетов в продаже и у снятых с продажи: снятый остаётся по
 * тому же адресу с пометкой, чтобы ссылки на него не вели в пустоту.
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
  const product = getProduct(params.slug, params.product);
  if (!product) return {};

  const composition = compositionLine(product.description);
  const onSale = product.status === 'active';
  // Одно и то же название бывает у разных букетов из разных разделов. Тогда в
  // заголовок добавляется раздел — иначе две страницы выглядят для поисковика
  // одинаково, и он показывает только одну из них.
  const label = getSectionLabel(product.mainSection);
  const short = product.title.trim().toLowerCase();
  // «Пион» в разделе «Пионы» подписывать нечем — вышло бы «Пион — пионы».
  // Достаточно, что подпись получит вторая страница пары: заголовки станут
  // разными, а этот останется коротким и читаемым.
  const needsLabel =
    getAmbiguousTitles().has(short) && !label.toLowerCase().includes(short) && !short.includes(label.toLowerCase());
  const name = needsLabel ? `${product.title} — ${label.toLowerCase()}` : product.title;
  // «Купить» в заголовке снятого букета обещало бы то, чего нельзя.
  const title = onSale ? `${name} — купить в Перми | Салон «Пион»` : `${name} — нет в продаже | Салон «Пион»`;

  return {
    ...buildMetadata({
      title,
      description: [
        onSale
          ? `${product.title} за ${product.price.toLocaleString('ru-RU')} ₽ с доставкой по Перми.`
          : `${product.title} — сейчас нет в продаже, похожие букеты — в разделе «${label}».`,
        composition && `Состав: ${composition}.`,
        onSale ? 'Фото букета перед доставкой, самовывоз со скидкой 5%.' : 'Салон цветов «Пион», Пермь.',
      ]
        .filter(Boolean)
        .join(' '),
      path: productPath(product.mainSection, product.slug),
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
  const product = getProduct(params.slug, params.product);
  if (!product) notFound();

  const onSale = product.status === 'active';
  const related = getRelatedProducts(product);
  const site = getSite();
  const label = getSectionLabel(product.mainSection);
  const composition = compositionLine(product.description);
  const pickup = site.delivery.options.find((o) => o.id === 'pickup');
  const cheapest = site.delivery.options
    .filter((o) => o.priceRub > 0)
    .sort((a, b) => a.priceRub - b.priceRub)[0];
  // Зона с бесплатной доставкой по акции — берём из данных, чтобы порог
  // менялся в одном месте.
  const freeZone = site.delivery.options.find((o) => o.freeFromRub !== undefined);

  return (
    <main className={styles.page}>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: 'Главная', path: '/' },
          { name: 'Каталог', path: '/catalog/' },
          { name: label, path: `/${product.mainSection}/` },
          { name: product.title, path: productPath(product.mainSection, product.slug) },
        ])}
      />
      <JsonLd data={productJsonLd(product, product.mainSection, product.slug, onSale)} />

      <nav className={styles.crumbs} aria-label="Вы здесь">
        <Link href="/">Главная</Link>
        <span aria-hidden="true">/</span>
        <Link href="/catalog">Каталог</Link>
        <span aria-hidden="true">/</span>
        <Link href={`/${product.mainSection}`}>{label}</Link>
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
          {product.price > 0 && <p className={styles.price}>{product.price.toLocaleString('ru-RU')} ₽</p>}
          {!onSale && <p className={styles.soldOut}>Сейчас нет в продаже</p>}

          {composition && (
            <div className={styles.block}>
              <h2 className={styles.blockTitle}>Состав</h2>
              <p className={styles.composition}>{composition}</p>
            </div>
          )}

          {onSale ? (
            <>
              <AddToCart product={product} />

              <p className={styles.assurance}>
                Соберём и пришлём фото букета перед доставкой — если что-то не понравится,
                переделаем.
              </p>
            </>
          ) : (
            <p className={styles.assurance}>
              {related.length > 0
                ? 'Посмотрите похожие букеты ниже — их можно заказать.'
                : `Позвоните нам — ${site.phone}, подскажем, что собрать вместо него.`}
            </p>
          )}

          <div className={styles.block}>
            <h2 className={styles.blockTitle}>Доставка и оплата</h2>
            <ul className={styles.facts}>
              {cheapest && (
                <li>
                  Доставка по Перми от {cheapest.priceRub.toLocaleString('ru-RU')} ₽ — стоимость
                  зависит от расстояния до салона.
                </li>
              )}
              {freeZone?.freeFromRub && (
                <li>
                  От {freeZone.freeFromRub.toLocaleString('ru-RU')} ₽ —{' '}
                  {freeZone.label.toLowerCase()} бесплатно, плюс фирменная коробка в подарок.
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
                <Link href={productPath(p.mainSection, p.slug)} className={styles.relatedCard}>
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
          <Link href={`/${product.mainSection}`} className={styles.more}>
            Весь раздел «{label}»
          </Link>
        </section>
      )}
    </main>
  );
}
