/**
 * Товарный фид в формате YML (Yandex Market Language) — по нему 2ГИС и
 * Яндекс Карты сами забирают букеты салона: название, цену, состав, фото.
 *
 * Требования 2ГИС, под которые здесь всё подогнано
 * (account.2gis.com/public/assets/faq/2gis-price-instruction.pdf):
 * - фото только JPG, PNG или GIF — наши WebP отдаются JPG-копиями по адресу
 *   /feed/img/<раздел>/<имя>.jpg, их делает сервер (pay/feed-img.php);
 * - в описании нельзя «скидка», «акция», «подарок», «новинка», «заказ», «хит»
 *   и подобное, переносы строк и длиннее 5000 знаков.
 */

const SHOP_NAME = 'Пион';
const COMPANY = 'Салон цветов и подарков «Пион»';

/** Плитка «Цветы» ведёт на страницу с плитками разделов поштучных цветов. */
const FLOWERS_PAGE = 'flowers';

/**
 * Слова, с которыми 2ГИС не принимает описание. Совпадение — только целым
 * словом: «подарят» и «хитрый» запрещёнными не считаются.
 */
const FORBIDDEN = new RegExp(
  '(?<!\\p{L})(' +
    [
      'скидк\\p{L}*',
      'распродаж\\p{L}*',
      'деш[её]в\\p{L}*',
      'подарок',
      'подарк\\p{L}*',
      'бесплатн\\p{L}*',
      'акци\\p{L}*',
      'специальн\\p{L}* цен\\p{L}*',
      'новинк\\p{L}*',
      'new',
      'аналог\\p{L}*',
      'заказ\\p{L}*',
      'хит',
      'хиты',
      'хита',
      'хитом',
      'хитов',
    ].join('|') +
    ')(?!\\p{L})',
  'iu',
);

const MAX_DESCRIPTION = 5000;

/**
 * Описание для 2ГИС: одной строкой, без предложений с запрещёнными словами.
 * «Заказ от 7 шт» у поштучных цветов — полезное условие, поэтому оно
 * переписывается в «От 7 шт», а не выбрасывается.
 */
export function cleanDescription(text) {
  const flat = String(text)
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/Заказ от (\d+)\s*шт/gu, 'От $1 шт')
    .replace(/заказ от (\d+)\s*шт/gu, 'от $1 шт');
  return flat
    .split(/(?<=[.!?])\s+/u)
    .filter((sentence) => sentence !== '' && !FORBIDDEN.test(sentence))
    .join(' ')
    .trim()
    .slice(0, MAX_DESCRIPTION);
}

const slugOf = (href) => /^\/([a-z0-9-]+)$/.exec(href)?.[1] ?? null;

/**
 * Категории фида — те же разделы, что в навигации сайта: плитки каталога и
 * плитки страницы «Цветы» (как подразделы). Плитки без раздела товаров
 * («Букеты до 5000», «Комнатные растения», всплывающая форма) пропускаются,
 * как и разделы без плитки — архивные сезонные коллекции.
 *
 * @param {{catalogTiles: {label: string, href: string}[], flowerTiles: {label: string, href: string}[], sections: Set<string>}} input
 * @returns {{id: string, name: string, parentId?: string, section?: string}[]}
 */
export function feedCategories({ catalogTiles, flowerTiles, sections }) {
  const categories = [];
  const add = (category) => {
    const id = String(categories.length + 1);
    categories.push({ id, ...category });
    return id;
  };
  for (const tile of catalogTiles) {
    const slug = slugOf(tile.href);
    if (slug === FLOWERS_PAGE) {
      const children = flowerTiles.filter((t) => sections.has(slugOf(t.href)));
      if (children.length === 0) continue;
      const parentId = add({ name: tile.label });
      for (const child of children) add({ name: child.label, parentId, section: slugOf(child.href) });
    } else if (slug !== null && sections.has(slug)) {
      add({ name: tile.label, section: slug });
    }
  }
  return categories;
}

const escapeXml = (value) =>
  String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');

/** JPG-копия первого фото: /images/catalog/<раздел>/<имя>.webp → /feed/img/<раздел>/<имя>.jpg. */
function pictureUrl(base, image) {
  const match = /^\/images\/catalog\/([a-z0-9-]+)\/([a-z0-9-]+)\.webp$/.exec(image ?? '');
  return match ? `${base}/feed/img/${match[1]}/${match[2]}.jpg` : null;
}

/**
 * @param {{siteUrl: string, date: string, categories: ReturnType<typeof feedCategories>, products: {uid: string, title: string, description?: string, price: number, images?: string[], slug: string, section: string}[]}} input
 * @returns {string} YML-документ
 */
export function buildYml({ siteUrl, date, categories, products }) {
  const base = siteUrl.replace(/\/$/, '');
  const categoryOf = new Map(categories.filter((c) => c.section).map((c) => [c.section, c.id]));
  const lines = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    `<yml_catalog date="${escapeXml(date)}">`,
    '  <shop>',
    `    <name>${escapeXml(SHOP_NAME)}</name>`,
    `    <company>${escapeXml(COMPANY)}</company>`,
    `    <url>${escapeXml(base)}/</url>`,
    '    <currencies>',
    '      <currency id="RUB" rate="1"/>',
    '    </currencies>',
    '    <categories>',
    ...categories.map(
      (c) => `      <category id="${c.id}"${c.parentId ? ` parentId="${c.parentId}"` : ''}>${escapeXml(c.name)}</category>`,
    ),
    '    </categories>',
    '    <offers>',
  ];
  for (const product of products) {
    const categoryId = categoryOf.get(product.section);
    if (categoryId === undefined || !(product.price > 0)) continue;
    const picture = pictureUrl(base, product.images?.[0]);
    const description = cleanDescription(product.description ?? '');
    lines.push(
      `      <offer id="${escapeXml(product.uid)}" available="true">`,
      `        <url>${escapeXml(`${base}/${product.section}/${product.slug}/`)}</url>`,
      `        <price>${Math.round(product.price)}</price>`,
      '        <currencyId>RUB</currencyId>',
      `        <categoryId>${categoryId}</categoryId>`,
      ...(picture ? [`        <picture>${escapeXml(picture)}</picture>`] : []),
      `        <name>${escapeXml(product.title)}</name>`,
      ...(description ? [`        <description>${escapeXml(description)}</description>`] : []),
      '      </offer>',
    );
  }
  lines.push('    </offers>', '  </shop>', '</yml_catalog>', '');
  return lines.join('\n');
}
