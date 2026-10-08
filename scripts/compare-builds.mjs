/**
 * Сравнение двух сборок сайта (папок out/): получился ли тот же сайт.
 *
 *   node scripts/compare-builds.mjs [--report] <до>/out <после>/out
 *
 * Печатает:
 * - адреса карты сайта, которые пропали или появились;
 * - страницы (index.html), которые пропали или появились;
 * - на общих страницах: <title>, <meta name="description">, canonical, первый
 *   <h1> и список ссылок и кнопок внутри <main> в порядке документа (у ссылки —
 *   адрес, текст и адреса картинок внутри неё, у кнопки — текст и картинки) —
 *   так видны «Новинки» на главной, плитки каталога с их фото и плитка-кнопка
 *   «Создать уникальный букет»;
 * - разделы, где поменялся список букетов в разметке (адрес и цена каждого);
 * - страницы букетов, где поменялись название, цена, наличие или фото;
 * - файлы api/catalog/*.json (по разобранному содержимому: порядок ключей не
 *   важен, порядок букетов важен);
 * - отличается ли фид: feed/products.xml (без даты) и feed/products.csv.
 * Нужен при смене источника каталога (этапы 2В и 3): «тот же сайт» — это те
 * же адреса, цены, фото и порядок.
 *
 * Код выхода: 0 — отличий нет; 1 — сборки различаются (для этапа 3В: сравнение
 * сборки из снимка со сборкой из выгрузки сервера); 2 — неверный вызов, папка,
 * не похожая на сборку (нет sitemap.xml или ни одной страницы index.html: две
 * пустые папки «одинаковыми» не считаются), или сравнение не удалось (любая
 * неожиданная ошибка; причина — в stderr после «Сравнение не удалось:»).
 * С --report код выхода при отличиях 0: посмотреть их, ничего не останавливая;
 * код 2 остаётся и с --report.
 *
 * Из других скриптов и тестов: import { compareBuilds } from './compare-builds.mjs'
 * — вернёт { lines, differences } и ничего не напечатает; если папка не сборка,
 * бросит ошибку с понятным текстом.
 */
import { existsSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

/** Сколько отличий каждого вида показывать подробно (счётчик считает все). */
const SHOW = 25;

/** Папка не похожа на сборку сайта: программа отвечает на это кодом 2, а не «отличий нет». */
class NotABuildError extends Error {}

function walk(dir, base = dir, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, base, out);
    else out.push(path.relative(base, full).split(path.sep).join('/'));
  }
  return out;
}

/** Текст файла сборки или null, если файла нет. */
function readText(root, rel) {
  try {
    return readFileSync(path.join(root, rel), 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT' || error.code === 'ENOTDIR') return null;
    throw error;
  }
}

/**
 * Адреса карты сайта и список страниц (index.html) сборки; читается один раз.
 * Папка без sitemap.xml или без единой страницы — не сборка: сравнивать нечего.
 */
function loadBuild(root) {
  const xml = readText(root, 'sitemap.xml');
  if (xml === null) throw new NotABuildError(`В папке «${root}» нет sitemap.xml — это не сборка сайта (out/), сравнивать нечего.`);
  const pages = new Set(walk(root).filter((f) => f.endsWith('index.html')));
  if (pages.size === 0) throw new NotABuildError(`В папке «${root}» нет ни одной страницы index.html — это не сборка сайта (out/), сравнивать нечего.`);
  return { urls: new Set([...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1])), pages };
}

const diff = (a, b) => [[...a].filter((x) => !b.has(x)), [...b].filter((x) => !a.has(x))];

/** Текст из разметки: без тегов и комментариев React, пробелы схлопнуты. */
const plain = (html) => html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

const firstMatch = (html, re) => html.match(re)?.[1] ?? null;

const same = (a, b) => a.length === b.length && a.every((x, i) => x === b[i]);

/** Адреса картинок (src) внутри куска разметки. srcSet не берём: src его дублирует. */
const imageSources = (html) => [...html.matchAll(/<img[^>]*\ssrc="([^"]*)"/g)].map((m) => m[1]);

/** Строка для сравнения: подпись, текст без тегов и, если есть картинки, их адреса в скобках. */
function keyOf(label, inner) {
  const srcs = imageSources(inner);
  return `${label} — ${plain(inner)}${srcs.length ? ` [${srcs.join(' ')}]` : ''}`;
}

/** Ссылки (m[1] — адрес, m[2] — содержимое) и кнопки (m[3] — содержимое) в порядке документа. */
const LINKS_AND_BUTTONS = /<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>|<button[^>]*>([\s\S]*?)<\/button>/g;

/** Всё, что сравнивается на странице; страница читается и разбирается один раз. */
function facts(root, rel) {
  const html = readFileSync(path.join(root, rel), 'utf8');
  const blocks = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)]
    .map((m) => {
      try {
        return JSON.parse(m[1]);
      } catch {
        return null;
      }
    })
    .filter(Boolean);
  const list = blocks.find((d) => d['@type'] === 'ItemList');
  const product = blocks.find((d) => d['@type'] === 'Product');
  const h1 = html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/);
  const main = firstMatch(html, /<main[^>]*>([\s\S]*?)<\/main>/) ?? '';
  return {
    title: firstMatch(html, /<title>([^<]*)<\/title>/),
    description: firstMatch(html, /<meta name="description" content="([^"]*)"/),
    canonical: firstMatch(html, /<link rel="canonical" href="([^"]*)"/),
    h1: h1 ? plain(h1[1]) : null,
    links: [...main.matchAll(LINKS_AND_BUTTONS)].map((m) => (m[1] === undefined ? keyOf('button', m[3]) : keyOf(m[1], m[2]))),
    list: list ? list.itemListElement.map((i) => `${i.item.url} ${i.item.offers?.price}`).join('\n') : null,
    product: product
      ? [product.name, product.offers?.price ?? '—', product.offers?.availability ?? '—', (product.image ?? []).join(',')].join(' | ')
      : null,
  };
}

/** facts, но с названием файла в тексте ошибки: «Сравнение не удалось: <файл>: <причина>». */
function factsOf(root, rel) {
  try {
    return facts(root, rel);
  } catch (error) {
    throw new Error(`${path.join(root, rel)}: ${error?.message ?? error}`, { cause: error });
  }
}

/** Поля страницы, сравниваемые как строки: [поле, как назвать в отчёте]. */
const TEXT_FIELDS = [
  ['title', '<title>'],
  ['description', '<meta name="description">'],
  ['canonical', 'canonical'],
  ['h1', 'первый <h1>'],
];

/** JSON с отсортированными ключами: одно и то же содержимое — одна и та же строка. */
const canon = (value) =>
  JSON.stringify(value, (_, x) =>
    x && typeof x === 'object' && !Array.isArray(x)
      ? Object.fromEntries(Object.entries(x).sort(([k], [l]) => (k < l ? -1 : k > l ? 1 : 0)))
      : x,
  );

const parseJson = (text) => {
  try {
    return JSON.parse(text);
  } catch {
    return { 'не JSON': text };
  }
};

const apiFiles = (root) => {
  const dir = path.join(root, 'api/catalog');
  return existsSync(dir) ? readdirSync(dir).filter((n) => n.endsWith('.json')) : [];
};

/** Что именно поменялось в api/catalog/<имя>.json (букеты сверяются по id). */
function describeApi(name, a, b) {
  const head = `api/catalog/${name}:`;
  if (!Array.isArray(a?.products) || !Array.isArray(b?.products)) return `${head} содержимое отличается.`;
  const key = (p) => String(p?.id);
  const byId = (products) => new Map(products.map((p) => [key(p), canon(p)]));
  const ma = byId(a.products);
  const mb = byId(b.products);
  const gone = [...ma.keys()].filter((id) => !mb.has(id));
  const added = [...mb.keys()].filter((id) => !ma.has(id));
  const changed = [...ma.keys()].filter((id) => mb.has(id) && ma.get(id) !== mb.get(id));
  const orderA = a.products.map(key).filter((id) => mb.has(id));
  const orderB = b.products.map(key).filter((id) => ma.has(id));
  const parts = [`букетов убрано ${gone.length}, добавлено ${added.length}, изменено ${changed.length}`];
  if (changed.length) parts.push(`изменены id: ${changed.slice(0, 5).join(', ')}${changed.length > 5 ? ', …' : ''}`);
  if (!same(orderA, orderB)) parts.push('поменялся порядок');
  if (canon({ ...a, products: null }) !== canon({ ...b, products: null })) parts.push('поменялись другие поля раздела');
  return `${head} ${parts.join('; ')}.`;
}

/** До пяти строк «− …» или «+ …» с хвостом «…и ещё N». */
const listed = (items, sign) =>
  items
    .slice(0, 5)
    .map((x) => `\n  ${sign} ${x}`)
    .join('') + (items.length > 5 ? `\n  ${sign} …и ещё ${items.length - 5}` : '');

const cut = (s) => (s === undefined ? '—' : s.length > 200 ? `${s.slice(0, 200)}…` : s);

/** Первая отличающаяся строка двух текстов — чтобы было понятно, куда смотреть. */
function firstDifference(a, b) {
  const la = a.split('\n');
  const lb = b.split('\n');
  for (let i = 0; i < Math.max(la.length, lb.length); i++) {
    if (la[i] !== lb[i]) return `\n  первое отличие, строка ${i + 1}:\n    было  ${cut(la[i])}\n    стало ${cut(lb[i])}`;
  }
  return '';
}

/**
 * Сравнивает две сборки. Возвращает строки отчёта и число найденных отличий
 * (каждая пропавшая или новая страница и адрес карты сайта, каждое поле страницы,
 * список, букет, файл api и фид — по одному). Ничего не печатает и не завершает процесс.
 */
export function compareBuilds(before, after) {
  const lines = [];
  let differences = 0;

  // Карта сайта и список страниц строятся один раз (раньше список пересобирали на каждой странице — около 70 с).
  const { urls: mapBefore, pages: pagesBefore } = loadBuild(before);
  const { urls: mapAfter, pages: pagesAfter } = loadBuild(after);
  const [goneUrls, newUrls] = diff(mapBefore, mapAfter);
  differences += goneUrls.length + newUrls.length;
  lines.push(`Карта сайта: было ${mapBefore.size}, стало ${mapAfter.size}.`);
  for (const u of goneUrls) lines.push(`  − ${u}`);
  for (const u of newUrls) lines.push(`  + ${u}`);

  const [gonePages, newPages] = diff(pagesBefore, pagesAfter);
  differences += gonePages.length + newPages.length;
  lines.push(`Страницы: пропало ${gonePages.length}, появилось ${newPages.length}.`);
  for (const p of [...gonePages.map((x) => `  − ${x}`), ...newPages.map((x) => `  + ${x}`)].slice(0, 40)) lines.push(p);

  let listChanges = 0;
  let productChanges = 0;
  let pagesChanged = 0;
  const fieldChanges = { title: 0, description: 0, canonical: 0, h1: 0, links: 0 };
  for (const rel of [...pagesBefore].filter((p) => pagesAfter.has(p))) {
    const a = factsOf(before, rel);
    const b = factsOf(after, rel);
    let pageChanged = false;
    for (const [field, label] of TEXT_FIELDS) {
      if (a[field] === b[field]) continue;
      pageChanged = true;
      differences++;
      if (++fieldChanges[field] <= SHOW) lines.push(`Страница ${rel}: ${label}\n  было  ${a[field] ?? '—'}\n  стало ${b[field] ?? '—'}`);
    }
    if (!same(a.links, b.links)) {
      pageChanged = true;
      differences++;
      if (++fieldChanges.links <= SHOW) {
        const [gone, added] = diff(new Set(a.links), new Set(b.links));
        let note = '';
        if (gone.length + added.length === 0) {
          note = same([...a.links].sort(), [...b.links].sort()) ? ' (поменялся порядок)' : ' (поменялось число повторов)';
        }
        lines.push(`Ссылки и кнопки в <main> на ${rel}: убрано ${gone.length}, добавлено ${added.length}${note}.${listed(gone, '−')}${listed(added, '+')}`);
      }
    }
    if (pageChanged) pagesChanged++;
    if (a.list !== b.list) {
      listChanges++;
      differences++;
      const [gone, added] = diff(new Set((a.list ?? '').split('\n')), new Set((b.list ?? '').split('\n')));
      lines.push(`Список букетов на ${rel}: убрано ${gone.length}, добавлено ${added.length}${gone.length + added.length === 0 ? ' (поменялся порядок)' : ''}.`);
    }
    if (a.product !== b.product) {
      productChanges++;
      differences++;
      if (productChanges <= SHOW) lines.push(`Букет ${rel}:\n  было  ${a.product}\n  стало ${b.product}`);
    }
  }
  lines.push(`Разделов с другим списком: ${listChanges}. Страниц букетов с другими данными: ${productChanges}.`);
  lines.push(
    `Страниц с другими данными в разметке: ${pagesChanged} (<title> — ${fieldChanges.title}, описание — ${fieldChanges.description}, ` +
      `canonical — ${fieldChanges.canonical}, <h1> — ${fieldChanges.h1}, ссылки и кнопки в <main> — ${fieldChanges.links}).`,
  );

  const names = [...new Set([...apiFiles(before), ...apiFiles(after)])].sort();
  let apiChanges = 0;
  for (const name of names) {
    const textBefore = readText(before, `api/catalog/${name}`);
    const textAfter = readText(after, `api/catalog/${name}`);
    if (textBefore === null || textAfter === null) {
      apiChanges++;
      lines.push(`api/catalog/${name}: есть только в сборке «${textBefore === null ? 'после' : 'до'}».`);
      continue;
    }
    const a = parseJson(textBefore);
    const b = parseJson(textAfter);
    if (canon(a) !== canon(b)) {
      apiChanges++;
      lines.push(describeApi(name, a, b));
    }
  }
  differences += apiChanges;
  lines.push(`Файлов api/catalog с другим содержимым: ${apiChanges} из ${names.length}.`);

  // Фид: у XML дата выгрузки каждый раз новая, её не сравниваем; CSV даты не содержит.
  const feeds = [
    ['Фид', 'feed/products.xml', (text) => text.replace(/date="[^"]*"/, '')],
    ['Фид CSV (feed/products.csv)', 'feed/products.csv', (text) => text],
  ];
  for (const [label, rel, normalize] of feeds) {
    const textBefore = readText(before, rel);
    const textAfter = readText(after, rel);
    if (textBefore === null && textAfter === null) {
      lines.push(`${label}: нет ни в одной сборке.`);
    } else if (textBefore === null || textAfter === null) {
      differences++;
      lines.push(`${label}: ОТЛИЧАЕТСЯ — файла нет в сборке «${textBefore === null ? 'до' : 'после'}».`);
    } else if (normalize(textBefore) === normalize(textAfter)) {
      lines.push(`${label}: тот же.`);
    } else {
      differences++;
      lines.push(`${label}: ОТЛИЧАЕТСЯ.${firstDifference(normalize(textBefore), normalize(textAfter))}`);
    }
  }

  lines.push(differences === 0 ? 'Итого отличий: 0 — сборки одинаковые.' : `Итого отличий: ${differences} — сборки различаются.`);
  return { lines, differences };
}

/**
 * Запущен ли файл как программа. Прямой запуск узнаём по адресу модуля; Node приводит
 * главный модуль к настоящему пути (через symlink и junction), а argv[1] — нет, поэтому
 * иначе сравниваем настоящие пути обоих (.native ещё и раскрывает короткие имена вроде
 * C:\Users\2BA0~1). Если realpath не работает (странный сетевой или subst-том), прямой
 * запуск всё равно узнан. Любая неудача значит «не главный модуль».
 */
function isMain() {
  try {
    const argv1 = process.argv[1];
    if (!argv1) return false;
    if (import.meta.url === pathToFileURL(argv1).href) return true;
    return realpathSync.native(path.resolve(argv1)) === realpathSync.native(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
}

if (isMain()) {
  const args = process.argv.slice(2);
  const report = args.includes('--report');
  const [before, after] = args.filter((a) => a !== '--report');
  if (!before || !after || !existsSync(before) || !existsSync(after)) {
    console.error('Как вызывать: node scripts/compare-builds.mjs [--report] <до>/out <после>/out');
    process.exitCode = 2;
  } else {
    try {
      const { lines, differences } = compareBuilds(before, after);
      for (const line of lines) console.log(line);
      process.exitCode = report || differences === 0 ? 0 : 1;
    } catch (error) {
      // Ворота не должны «падать в 1»: код 1 значит только «сборки различаются».
      const message = error instanceof NotABuildError ? error.message : `Сравнение не удалось: ${error?.message ?? error}`;
      console.error(message);
      process.exitCode = 2;
    }
  }
}
