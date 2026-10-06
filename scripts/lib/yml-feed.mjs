/**
 * Товарный фид в формате YML (Yandex Market Language) — по нему 2ГИС и
 * Яндекс Карты сами забирают букеты салона: название, цену, состав, фото.
 * Тот же фид в CSV по образцу 2ГИС — для загрузки в их кабинет файлом.
 *
 * Требования 2ГИС, под которые здесь всё подогнано
 * (account.2gis.com/public/assets/faq/2gis-price-instruction.pdf и
 * 2gis-rules-for-adding-products.pdf там же):
 * - фото только JPG, PNG или GIF — наши WebP отдаются JPG-копиями по адресу
 *   /feed/img/<раздел>/<имя>.jpg, их делает сервер (pay/feed-img.php);
 * - в названии нельзя слов, набранных заглавными буквами;
 * - в описании нельзя «скидка», «акция», «подарок», «новинка», «заказ», «хит»
 *   и подобное, переносы строк и длиннее 500 знаков.
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

const MAX_DESCRIPTION = 500;

/**
 * Описание для 2ГИС: одной строкой, без предложений с запрещёнными словами,
 * целыми предложениями в пределах 500 знаков.
 * «Заказ от 7 шт» у поштучных цветов — полезное условие, поэтому оно
 * переписывается в «От 7 шт», а не выбрасывается.
 */
export function cleanDescription(text) {
  const flat = String(text)
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/Заказ от (\d+)\s*шт/gu, 'От $1 шт')
    .replace(/заказ от (\d+)\s*шт/gu, 'от $1 шт');
  const sentences = flat
    .split(/(?<=[.!?])\s+/u)
    .filter((sentence) => sentence !== '' && !FORBIDDEN.test(sentence));
  let description = '';
  for (const sentence of sentences) {
    const longer = description ? `${description} ${sentence}` : sentence;
    if (longer.length > MAX_DESCRIPTION) break;
    description = longer;
  }
  // Уже первое предложение длиннее предела — остаётся только обрезать его.
  return description || (sentences[0] ?? '').slice(0, MAX_DESCRIPTION);
}

/**
 * Название для 2ГИС: слов, набранных заглавными, они не принимают —
 * «ВАЗА ДЕКОРАТИВНАЯ FLORA» становится «Ваза декоративная Flora». На сайте
 * названия остаются как есть, меняется только фид. Короткие латинские
 * слова вроде «XL» не трогаются: это размеры и обозначения.
 */
export function feedName(title) {
  const name = String(title).replace(/\s+/g, ' ').trim();
  const letters = name.match(/\p{L}/gu) ?? [];
  // Название набрано капсом целиком — тогда строчными становятся и предлоги вроде «С».
  const shouting = letters.filter((ch) => ch !== ch.toLowerCase()).length * 2 > letters.length;
  return name.replace(/\p{L}+/gu, (word, offset) => {
    if (word !== word.toUpperCase() || word === word.toLowerCase()) return word;
    const latin = /\p{Script=Latin}/u.test(word);
    if (word.length < 3 && (latin || !shouting)) return word;
    const capitalized = word[0] + word.slice(1).toLowerCase();
    if (latin) return capitalized;
    // С заглавной — первое слово и название в кавычках: «Ваза "Ягуар"».
    return /^\P{L}*$|[«"„“]$/u.test(name.slice(0, offset)) ? capitalized : word.toLowerCase();
  });
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
 * @typedef {{uid: string, title: string, description?: string, price: number, images?: string[], slug: string, section: string}} FeedProduct
 */

/**
 * Предложения фида — общие для YML и CSV. Товары без цены и из разделов вне
 * навигации (архивные сезонные коллекции) пропускаются.
 *
 * @param {{base: string, categories: ReturnType<typeof feedCategories>, products: FeedProduct[]}} input
 */
function feedOffers({ base, categories, products }) {
  const categoryOf = new Map(categories.filter((c) => c.section).map((c) => [c.section, c]));
  return products.flatMap((product) => {
    const category = categoryOf.get(product.section);
    if (category === undefined || !(product.price > 0)) return [];
    return [
      {
        id: product.uid,
        url: `${base}/${product.section}/${product.slug}/`,
        price: Math.round(product.price),
        category,
        picture: pictureUrl(base, product.images?.[0]),
        name: feedName(product.title),
        description: cleanDescription(product.description ?? ''),
      },
    ];
  });
}

/**
 * @param {{siteUrl: string, date: string, categories: ReturnType<typeof feedCategories>, products: FeedProduct[]}} input
 * @returns {string} YML-документ
 */
export function buildYml({ siteUrl, date, categories, products }) {
  const base = siteUrl.replace(/\/$/, '');
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
  for (const offer of feedOffers({ base, categories, products })) {
    lines.push(
      `      <offer id="${escapeXml(offer.id)}" available="true">`,
      `        <url>${escapeXml(offer.url)}</url>`,
      `        <price>${offer.price}</price>`,
      '        <currencyId>RUB</currencyId>',
      `        <categoryId>${offer.category.id}</categoryId>`,
      ...(offer.picture ? [`        <picture>${escapeXml(offer.picture)}</picture>`] : []),
      `        <name>${escapeXml(offer.name)}</name>`,
      ...(offer.description ? [`        <description>${escapeXml(offer.description)}</description>`] : []),
      '      </offer>',
    );
  }
  lines.push('    </offers>', '  </shop>', '</yml_catalog>', '');
  return lines.join('\n');
}

/** Ячейка CSV: с точкой с запятой или кавычками — в кавычках, кавычки внутри удваиваются. */
const csvCell = (value) => {
  const text = String(value);
  return /[;"]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
};

/**
 * Тот же фид в CSV по образцу из инструкции 2ГИС: для кнопки «Загрузить
 * файл» в их кабинете. Категория в CSV у 2ГИС одноуровневая, поэтому у роз
 * она «Розы», без «Цветов».
 *
 * @param {{siteUrl: string, categories: ReturnType<typeof feedCategories>, products: FeedProduct[]}} input
 * @returns {string} CSV в UTF-8, разделитель — точка с запятой
 */
export function buildCsv({ siteUrl, categories, products }) {
  const base = siteUrl.replace(/\/$/, '');
  const rows = feedOffers({ base, categories, products }).map((offer) =>
    [offer.name, offer.price, 'RUB', offer.category.name, offer.url, offer.picture ?? '', offer.id, offer.description]
      .map(csvCell)
      .join(';'),
  );
  return ['name;price;currencyId;category;url;picture;id;description', ...rows, ''].join('\n');
}
