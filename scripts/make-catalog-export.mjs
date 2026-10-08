/**
 * Снимок каталога из нынешних файлов репозитория в формате выгрузки админки
 * (спецификация: раздел 3 и «Перенос нынешнего каталога»).
 *
 *   npm run catalog:snapshot   (= node scripts/make-catalog-export.mjs --out data/catalog-export.json)
 *
 * С этапа 2В сайт читает только data/catalog-export.json. Пока каталог не
 * переехал в базу (этап 3), источник снимка — data/catalog/*.json (выгрузка
 * из Tilda) и data/catalog-meta.json. После их правки снимок нужно собрать
 * заново, иначе scripts/make-catalog-export.test.mjs упадёт и напомнит.
 *
 * Правила переноса:
 * - 13 разделов. Названия — те, что были в коде сайта (CATEGORY_LABELS),
 *   обложки и тексты — из catalog-meta.json, пустые поля — пустые строки;
 * - у трёх сезонных разделов свои заголовки для поиска (были в PAGE_SEO);
 * - 12 плиток в нынешнем порядке; у раздела без плитки — «не показывать в каталоге»;
 * - букеты — после dedupeProducts, в порядке файлов; главный раздел — тот, где
 *   букет лежит. Букет с ценой 0 ₽ (19 «Цветочных коробок»: в Tilda они без
 *   цены и выключены) снят с продажи — страница остаётся, заказать нельзя;
 * - раздела «Новинки» здесь нет: он и его фото появятся при переносе в базу
 *   (этап 3), а до тех пор главная берёт «Новинки» из data/site.json.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { dedupeProducts } from './lib/dedupeProducts.mjs';
import { exportVersion, formatExport, validateExport } from './lib/catalog-export.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Названия разделов — были CATEGORY_LABELS в src/lib/content.ts. */
export const SECTION_LABELS = {
  bukety: 'Букеты',
  korziny: 'Корзины цветов',
  korobki: 'Коробки с цветами',
  wedding: 'Свадебные букеты',
  balloons: 'Воздушные шары',
  chocolate: 'Шоколад',
  luchshee: 'Лучшее для дома',
  flame: 'Продукция Flame',
  pions: 'Пионы',
  roses: 'Розы',
  mixflower: 'Микс из цветов',
  valentinesday: 'Букеты и боксы к 14 февраля',
  'new-year-2025': 'Новогодняя коллекция',
};

/**
 * Заголовки для поиска, написанные вручную, — были в PAGE_SEO
 * (src/app/[slug]/page.tsx). Сезонные разделы раньше были отдельными
 * страницами. «Букет невесты» и «свадебный букет» — один товар, но первое
 * ищут втрое чаще, поэтому оно в заголовке.
 */
export const SECTION_SEO = {
  wedding: {
    title: 'Букет невесты и свадебные букеты в Перми | «Пион»',
    description:
      'Букет невесты, бутоньерка жениха и цветы для церемонии от салона «Пион» в Перми. Собираем в день свадьбы, привозим ко времени сбора невесты.',
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
};

const slugOfHref = (href) => href.replace(/^\//, '').replace(/\/$/, '');

export function makeCatalogExport(root = ROOT) {
  const read = (rel) => JSON.parse(readFileSync(path.join(root, rel), 'utf8'));
  const meta = read('data/catalog-meta.json');

  const tileImages = new Map();
  const tiles = meta.tiles.map((t) => {
    if (t.href.startsWith('#')) return { type: 'popup', label: t.label, href: t.href, image: t.image };
    const slug = slugOfHref(t.href);
    if (Object.hasOwn(SECTION_LABELS, slug)) {
      tileImages.set(slug, t.image);
      return { type: 'section', slug };
    }
    return { type: 'link', label: t.label, href: t.href, image: t.image };
  });

  const sections = [];
  const products = [];
  for (const slug of Object.keys(SECTION_LABELS).sort()) {
    const list = dedupeProducts(read(`data/catalog/${slug}.json`));
    const m = meta.categories[slug] ?? {};
    const seo = SECTION_SEO[slug] ?? null;
    sections.push({
      slug,
      label: SECTION_LABELS[slug],
      tileImage: tileImages.get(slug) ?? '',
      visible: tileImages.has(slug),
      coverTitle: m.title ?? '',
      coverSub: m.sub ?? '',
      covers: m.covers ?? [],
      heading: m.heading ?? '',
      headingSub: m.headingSub ?? '',
      hasNotFound: m.hasNotFound ?? false,
      seoTitle: seo?.title ?? null,
      seoDescription: seo?.description ?? null,
      products: list.map((p) => String(p.uid)),
    });
    for (const p of list) {
      products.push({
        uid: String(p.uid),
        slug: p.slug,
        title: p.title,
        description: p.description ?? '',
        price: p.price,
        images: p.images,
        mainSection: slug,
        status: p.price > 0 ? 'active' : 'hidden',
      });
    }
  }
  products.sort((a, b) => (a.uid < b.uid ? -1 : a.uid > b.uid ? 1 : 0));

  const exp = { changedAt: null, sections, tiles, products, redirects: [] };
  return { version: exportVersion(exp), ...exp };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const at = process.argv.indexOf('--out');
  const exp = makeCatalogExport();
  const errors = validateExport(exp);
  if (errors.length) {
    console.error('Снимок не прошёл проверку:');
    for (const error of errors) console.error(`- ${error}`);
    process.exit(1);
  }
  if (at > 0 && process.argv[at + 1]) {
    writeFileSync(path.resolve(process.argv[at + 1]), formatExport(exp));
    console.log(`Снимок каталога: разделов ${exp.sections.length}, букетов ${exp.products.length}, версия ${exp.version.slice(0, 12)}.`);
  } else {
    process.stdout.write(formatExport(exp));
  }
}
