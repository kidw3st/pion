/**
 * Выгрузка каталога — формат из спецификации (раздел 3), тот же, что отдаёт
 * server-pay/catalog/export.php. Здесь то, что нужно сборке сайта: версия
 * (sha256 канонического JSON), проверки перед сборкой и запись файла.
 *
 * Версия совпадает с PHP байт в байт. JSON.stringify пишет кириллицу и слэши
 * как есть, так же как json_encode с JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES. Ключи объектов идут в том же порядке, что в
 * catalog_export_data(). Сверка — tests/php/catalog_export_js_test.php
 * считает на PHP версию того же файла-образца.
 */
import { createHash } from 'node:crypto';

/**
 * Адреса сайта, которые раздел занять не может. Тот же список —
 * CATALOG_RESERVED_SLUGS в server-pay/catalog/slug.php (проверка в тесте).
 */
export const RESERVED_SLUGS = [
  'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000', 'blog', 'pay', 'api', 'images', 'md',
  'fonts', '_next', 'tproduct', 'tstore', 'feed',
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds', 'stock', 'policy',
  'doza_endorfina', 'flowers', 'indoorflowers',
];

/** Если букетов стало меньше этой доли от прошлой сборки, сборка останавливается. */
export const SHRINK_LIMIT = 0.7;

const SLUG = /^[a-z0-9][a-z0-9-]*$/;
const UID = /^[0-9]{6,20}$/;
// Как CATALOG_SITE_IMAGE в PHP: только /images/, без «..» и без корзины _deleted.
const IMAGE = /^\/images\/(?!.*\.\.)(?!(?:.*\/)?_deleted\/)[A-Za-z0-9/._-]+\.webp$/;
// Адрес страницы этого сайта: /раздел/ или /раздел/букет/.
const PATH = /^\/[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\/$/;
const STATUSES = ['active', 'hidden'];

const sectionOut = (s) => ({
  slug: s.slug,
  label: s.label,
  tileImage: s.tileImage,
  visible: s.visible,
  coverTitle: s.coverTitle,
  coverSub: s.coverSub,
  covers: s.covers,
  heading: s.heading,
  headingSub: s.headingSub,
  hasNotFound: s.hasNotFound,
  seoTitle: s.seoTitle,
  seoDescription: s.seoDescription,
  products: s.products,
});
const tileOut = (t) =>
  t.type === 'section'
    ? { type: t.type, slug: t.slug }
    : { type: t.type, label: t.label, href: t.href, image: t.image };
const productOut = (p) => ({
  uid: p.uid,
  slug: p.slug,
  title: p.title,
  description: p.description,
  price: p.price,
  images: p.images,
  mainSection: p.mainSection,
  status: p.status,
});
const redirectOut = (r) => ({ from: r.from, to: r.to });

/** Четыре части выгрузки с ключами в каноническом порядке — по ним считается версия. */
export function canonicalParts(exp) {
  return {
    sections: exp.sections.map(sectionOut),
    tiles: exp.tiles.map(tileOut),
    products: exp.products.map(productOut),
    redirects: exp.redirects.map(redirectOut),
  };
}

/** sha256 канонического JSON; version и changedAt в неё не входят. */
export function exportVersion(exp) {
  return createHash('sha256').update(JSON.stringify(canonicalParts(exp)), 'utf8').digest('hex');
}

/** Текст файла data/catalog-export.json: версия, время и части в каноническом порядке. */
export function formatExport(exp) {
  const parts = canonicalParts(exp);
  return `${JSON.stringify({ version: exportVersion(exp), changedAt: exp.changedAt ?? null, ...parts }, null, 2)}\n`;
}

const isStr = (v) => typeof v === 'string';
const ascending = (list, key) => list.every((x, i) => i === 0 || list[i - 1][key] < x[key]);

/**
 * Проверка выгрузки перед сборкой (спецификация, раздел 4, шаг 3). Пустой
 * список — выгрузку можно собирать; иначе ошибки по-русски, по одной на
 * строку.
 *
 * @param {any} exp
 * @param {{previousCount?: number|null, allowShrink?: boolean}} [opts]
 * @returns {string[]}
 */
export function validateExport(exp, { previousCount = null, allowShrink = false } = {}) {
  if (exp === null || typeof exp !== 'object' || Array.isArray(exp)) return ['Выгрузка — не объект JSON.'];
  const errors = [];
  for (const part of ['sections', 'tiles', 'products', 'redirects']) {
    if (!Array.isArray(exp[part])) errors.push(`В выгрузке нет части ${part}.`);
  }
  if (errors.length) return errors;
  if (exp.changedAt !== undefined && exp.changedAt !== null && !isStr(exp.changedAt)) {
    errors.push('changedAt — не строка.');
  }

  // Разделы.
  const sections = new Map();
  for (const s of exp.sections) {
    if (!s || !isStr(s.slug) || !SLUG.test(s.slug)) {
      errors.push(`Раздел с неправильным адресом: ${JSON.stringify(s?.slug)}.`);
      continue;
    }
    if (sections.has(s.slug)) errors.push(`Раздел ${s.slug} встречается дважды.`);
    if (RESERVED_SLUGS.includes(s.slug)) errors.push(`Раздел ${s.slug} совпадает с другой страницей сайта.`);
    if (!isStr(s.label) || s.label.trim() === '') errors.push(`У раздела ${s.slug} нет названия.`);
    for (const field of ['tileImage', 'coverTitle', 'coverSub', 'heading', 'headingSub']) {
      if (!isStr(s[field])) errors.push(`Раздел ${s.slug}: ${field} — не строка.`);
    }
    for (const field of ['seoTitle', 'seoDescription']) {
      if (s[field] !== null && !isStr(s[field])) errors.push(`Раздел ${s.slug}: ${field} — не строка и не null.`);
    }
    if (typeof s.visible !== 'boolean' || typeof s.hasNotFound !== 'boolean') {
      errors.push(`Раздел ${s.slug}: visible и hasNotFound должны быть true или false.`);
    }
    if (!Array.isArray(s.covers) || !s.covers.every((c) => isStr(c) && IMAGE.test(c))) {
      errors.push(`Раздел ${s.slug}: неправильные фото обложки.`);
    }
    if (isStr(s.tileImage) && s.tileImage !== '' && !IMAGE.test(s.tileImage)) {
      errors.push(`Раздел ${s.slug}: неправильное фото плитки.`);
    }
    if (!Array.isArray(s.products) || !s.products.every(isStr)) {
      errors.push(`Раздел ${s.slug}: список букетов — не список uid.`);
      continue;
    }
    if (new Set(s.products).size !== s.products.length) errors.push(`Раздел ${s.slug}: один букет записан дважды.`);
    sections.set(s.slug, s);
  }
  if (!ascending(exp.sections.filter((s) => s && isStr(s.slug)), 'slug')) {
    errors.push('Разделы не по алфавиту адресов — выгрузка не в каноническом виде.');
  }

  // Букеты.
  const products = new Map();
  const addresses = new Set();
  for (const p of exp.products) {
    if (!p || !isStr(p.uid) || !UID.test(p.uid)) {
      errors.push(`Букет с неправильным uid: ${JSON.stringify(p?.uid)}.`);
      continue;
    }
    const name = `Букет ${p.uid}`;
    if (products.has(p.uid)) errors.push(`${name} встречается дважды.`);
    products.set(p.uid, p);
    if (!isStr(p.slug) || !SLUG.test(p.slug)) errors.push(`${name}: неправильный адрес ${JSON.stringify(p.slug)}.`);
    if (!isStr(p.title) || p.title.trim() === '') errors.push(`${name}: нет названия.`);
    if (!isStr(p.description)) errors.push(`${name}: состав — не строка.`);
    if (!STATUSES.includes(p.status)) {
      errors.push(`${name}: статус ${JSON.stringify(p.status)} — в выгрузке бывают только active и hidden.`);
    }
    if (!Number.isInteger(p.price) || p.price < 0 || (p.status === 'active' && p.price === 0)) {
      errors.push(`${name}: цена ${JSON.stringify(p.price)} — нужна целая и больше нуля (у снятого с продажи можно 0).`);
    }
    if (!Array.isArray(p.images) || !p.images.every((i) => isStr(i) && IMAGE.test(i))) {
      errors.push(`${name}: неправильные пути к фото.`);
    }
    const main = sections.get(p.mainSection);
    if (!main) errors.push(`${name}: главного раздела ${JSON.stringify(p.mainSection)} нет.`);
    else if (!main.products.includes(p.uid)) errors.push(`${name}: его нет в его главном разделе ${p.mainSection}.`);
    const address = `/${p.mainSection}/${p.slug}/`;
    if (addresses.has(address)) errors.push(`Адрес ${address} у двух букетов.`);
    addresses.add(address);
  }
  if (!ascending(exp.products.filter((p) => p && isStr(p.uid)), 'uid')) {
    errors.push('Букеты не по порядку uid — выгрузка не в каноническом виде.');
  }
  for (const s of sections.values()) {
    for (const uid of s.products) {
      if (!products.has(uid)) errors.push(`Раздел ${s.slug}: букета ${uid} в выгрузке нет.`);
    }
  }

  // Плитки сетки каталога.
  const inGrid = new Set();
  for (const t of exp.tiles) {
    if (t?.type === 'section') {
      const s = sections.get(t.slug);
      if (!s) errors.push(`Плитка ведёт в раздел ${JSON.stringify(t.slug)}, которого нет.`);
      else if (!s.visible) errors.push(`Плитка скрытого раздела ${t.slug} попала в сетку.`);
      if (inGrid.has(t.slug)) errors.push(`У раздела ${t.slug} две плитки.`);
      inGrid.add(t.slug);
    } else if (t?.type === 'link' || t?.type === 'popup') {
      if (!isStr(t.label) || t.label.trim() === '' || !isStr(t.image) || !IMAGE.test(t.image)) {
        errors.push(`Плитка ${JSON.stringify(t.label)}: нет подписи или фото.`);
      }
      const hrefOk = isStr(t.href) && (t.type === 'popup' ? t.href.startsWith('#') : t.href.startsWith('/'));
      if (!hrefOk) errors.push(`Плитка ${JSON.stringify(t.label)}: неправильная ссылка ${JSON.stringify(t.href)}.`);
    } else {
      errors.push(`Плитка неизвестного вида: ${JSON.stringify(t?.type)}.`);
    }
  }
  for (const s of sections.values()) {
    if (s.visible && !inGrid.has(s.slug)) errors.push(`Раздел ${s.slug} показывается в каталоге, но его плитки в сетке нет.`);
  }

  // Переадресации.
  const froms = new Set();
  for (const r of exp.redirects) {
    if (!r || !isStr(r.from) || !isStr(r.to) || !PATH.test(r.from) || !PATH.test(r.to)) {
      errors.push(`Неправильная переадресация ${JSON.stringify(r)}.`);
      continue;
    }
    if (froms.has(r.from)) errors.push(`Переадресация с ${r.from} записана дважды.`);
    froms.add(r.from);
    if (r.from === r.to) errors.push(`Переадресация ${r.from} ведёт сама на себя.`);
    if (addresses.has(r.from)) errors.push(`С адреса ${r.from} стоит переадресация, но там живая страница букета.`);
  }
  for (const r of exp.redirects) {
    if (r && froms.has(r.to)) errors.push(`Цепочка переадресаций через ${r.to}.`);
  }
  if (!ascending(exp.redirects.filter((r) => r && isStr(r.from)), 'from')) {
    errors.push('Переадресации не по порядку — выгрузка не в каноническом виде.');
  }

  // Версию сверяем, только когда сама выгрузка цела: иначе посчитать её нельзя.
  if (errors.length === 0 && exp.version !== undefined && exp.version !== exportVersion(exp)) {
    errors.push('Версия выгрузки не сходится с содержимым — файл повреждён.');
  }

  // Защита от массовой потери: салон редко удаляет треть каталога разом.
  if (previousCount !== null && previousCount > 0 && !allowShrink && exp.products.length < previousCount * SHRINK_LIMIT) {
    const percent = Math.round((1 - exp.products.length / previousCount) * 100);
    errors.push(
      `Букетов ${exp.products.length} вместо ${previousCount} — меньше на ${percent}%. ` +
        'Если салон и правда столько удалил, запустите сборку вручную с allow_shrink (в командной строке — флаг --allow-shrink).',
    );
  }
  return errors;
}
