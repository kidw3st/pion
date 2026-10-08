/**
 * Как сайт видит выгрузку каталога: что стоит в разделе, какая страница у
 * букета, что попадает в «Новинки», плитки сетки. Это чистые функции над
 * объектом выгрузки. Их используют и страницы (src/lib/catalog.ts), и
 * служебные файлы сборки (build-agent-assets.mjs, build-feed.mjs), чтобы
 * эти части не разошлись.
 *
 * Правила из спецификации (раздел 6): в разделе показываются только букеты в
 * продаже, в порядке раздела. Страница есть у букетов в продаже и у снятых,
 * адрес строится по главному разделу — в каком бы разделе букет ни стоял.
 */

/** Раздел, первые букеты в продаже из которого стоят на главной. */
export const NEW_SECTION = 'novinki';

/** Постоянный адрес страницы букета. Та же форма, что productPath в src/lib/seo.ts. */
export function productPath(section, slug) {
  return `/${section}/${slug}/`;
}

const indexes = new WeakMap();
function byUid(exp) {
  let index = indexes.get(exp);
  if (!index) {
    index = new Map(exp.products.map((p) => [p.uid, p]));
    indexes.set(exp, index);
  }
  return index;
}

export function findSection(exp, slug) {
  return exp.sections.find((s) => s.slug === slug) ?? null;
}

/** Букеты в продаже из раздела, в его порядке. Снятые в разделах не показываются. */
export function sectionProducts(exp, slug) {
  const section = findSection(exp, slug);
  if (!section) return [];
  const index = byUid(exp);
  return section.products.map((uid) => index.get(uid)).filter((p) => p !== undefined && p.status === 'active');
}

/** Букет по адресу страницы — главный раздел и slug. Снятые тоже: страница у них есть. */
export function findProduct(exp, section, slug) {
  return exp.products.find((p) => p.mainSection === section && p.slug === slug) ?? null;
}

/** Все страницы букетов — в продаже и снятые — по разделам и в их порядке. */
export function productPages(exp) {
  const index = byUid(exp);
  const pages = [];
  for (const section of exp.sections) {
    for (const uid of section.products) {
      const p = index.get(uid);
      if (p && p.mainSection === section.slug) pages.push({ slug: section.slug, product: p.slug });
    }
  }
  return pages;
}

/** Похожие букеты — в продаже, из главного раздела, без самого букета. */
export function relatedProducts(exp, product, limit = 4) {
  return sectionProducts(exp, product.mainSection).filter((p) => p.uid !== product.uid).slice(0, limit);
}

/**
 * Названия, под которыми продаются букеты из разных главных разделов: их
 * страницы получают в заголовок название раздела, иначе поисковик видит две
 * одинаковые. Счёт идёт по букетам: букет, стоящий в двух разделах, сам себе
 * не тёзка.
 */
export function ambiguousTitles(exp) {
  const seen = new Map();
  for (const p of exp.products) {
    const key = p.title.trim().toLowerCase();
    if (!seen.has(key)) seen.set(key, new Set());
    seen.get(key).add(p.mainSection);
  }
  return new Set([...seen].filter(([, sections]) => sections.size > 1).map(([title]) => title));
}

/**
 * Плитки сетки каталога: у раздела — его название, адрес и фото плитки.
 * Раздел без фото плитки в сетку не выводится: пустая картинка выглядела бы
 * поломкой (админка и так создаёт новый раздел скрытым).
 */
export function catalogTiles(exp) {
  return exp.tiles.flatMap((t) => {
    if (t.type !== 'section') return [{ label: t.label, href: t.href, image: t.image }];
    const section = findSection(exp, t.slug);
    return section && section.tileImage ? [{ label: section.label, href: `/${section.slug}`, image: section.tileImage }] : [];
  });
}

const orNull = (value) => (value === '' ? null : value);

/** Оформление страницы раздела в прежнем виде CategoryMeta: пустое поле — null. */
export function sectionMeta(section) {
  return {
    title: orNull(section.coverTitle),
    sub: orNull(section.coverSub),
    covers: section.covers,
    heading: orNull(section.heading),
    headingSub: orNull(section.headingSub),
    hasNotFound: section.hasNotFound,
  };
}

/**
 * «Новинки» на главной — первые букеты в продаже из раздела novinki.
 * null — такого раздела в выгрузке нет (до переноса каталога в базу);
 * пустой список — раздел есть, но в продаже в нём ничего, и блока нет.
 */
export function newProducts(exp, limit = 3) {
  if (!findSection(exp, NEW_SECTION)) return null;
  return sectionProducts(exp, NEW_SECTION).slice(0, limit);
}

/**
 * Старые адреса товаров Tilda (`…/tproduct/…-<uid>-…`) → страница букета.
 * Uid при переезде не менялся. Снятые ведут на свою страницу; удалённых в
 * выгрузке нет, их ведёт в раздел правило .htaccess.
 */
export function tildaMap(exp) {
  return Object.fromEntries(
    exp.products.filter((p) => /^[0-9]+$/.test(p.uid)).map((p) => [p.uid, productPath(p.mainSection, p.slug)]),
  );
}

/** Переадресации каталога для pay/catalog-redirect.php: откуда → куда. */
export function redirectMap(exp) {
  return Object.fromEntries(exp.redirects.map((r) => [r.from, r.to]));
}
