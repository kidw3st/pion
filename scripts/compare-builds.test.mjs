import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { afterAll, describe, expect, it } from 'vitest';
import { compareBuilds } from './compare-builds.mjs';

const SCRIPT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'compare-builds.mjs');

const made = [];
afterAll(() => {
  for (const dir of made) rmSync(dir, { recursive: true, force: true });
});

/** Временная папка с файлами: путь => содержимое. */
function tree(files) {
  const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-compare-'));
  made.push(dir);
  for (const [rel, body] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(dir, rel)), { recursive: true });
    writeFileSync(path.join(dir, rel), body);
  }
  return dir;
}

const itemList = (urls) =>
  JSON.stringify({
    '@type': 'ItemList',
    itemListElement: urls.map((url, i) => ({ '@type': 'ListItem', position: i + 1, item: { url, offers: { price: 1000 } } })),
  });

/** Страница так, как её отдаёт Next: теги в <head>, <h1>, ссылки внутри <main>. */
function page({ title = 'Пион', description = 'Описание', canonical = 'https://pionperm.ru/', h1 = 'Салон', links = [], list = [] } = {}) {
  return (
    `<!DOCTYPE html><html><head><title>${title}</title>` +
    `<meta name="description" content="${description}"/>` +
    `<link rel="canonical" href="${canonical}"/>` +
    `<script type="application/ld+json">${itemList(list)}</script></head>` +
    `<body><header><a href="/menu/">Меню</a></header><h1 class="x">${h1}</h1>` +
    `<main class="page_main__abc">${links.map(([href, text]) => `<a class="t" href="${href}"><div><span>${text}</span></div></a>`).join('')}</main>` +
    `<footer><a href="/footer/">Подвал</a></footer></body></html>`
  );
}

const catalog = (products) => JSON.stringify({ slug: 'bukety', title: 'Букеты', url: 'https://pionperm.ru/bukety/', products });
const PRODUCTS = [
  { id: '1', title: 'Букет А', priceRub: 3000, image: 'a.jpg' },
  { id: '2', title: 'Букет Б', priceRub: 4000, image: 'b.jpg' },
];
const xml = (date) => `<?xml version="1.0"?>\n<yml_catalog date="${date}">\n<offer id="1"><price>3000</price></offer>\n</yml_catalog>\n`;
const CSV = 'name;price\nБукет А;3000\nБукет Б;4000\n';

/** Крошечная сборка; overrides подменяют или (null) убирают файлы. */
function build(overrides = {}) {
  const files = {
    'sitemap.xml': '<urlset><url><loc>https://pionperm.ru/</loc></url><url><loc>https://pionperm.ru/bukety/</loc></url></urlset>',
    'index.html': page({
      links: [
        ['/bukety/buket-a/', 'Букет А'],
        ['/bukety/buket-b/', 'Букет Б'],
      ],
      list: ['https://pionperm.ru/bukety/buket-a/', 'https://pionperm.ru/bukety/buket-b/'],
    }),
    'bukety/index.html': page({ title: 'Букеты | Пион', canonical: 'https://pionperm.ru/bukety/', h1: 'Букеты' }),
    'api/catalog/bukety.json': catalog(PRODUCTS),
    'feed/products.xml': xml('2026-10-08 10:00'),
    'feed/products.csv': CSV,
    ...overrides,
  };
  for (const [rel, body] of Object.entries(files)) if (body === null) delete files[rel];
  return tree(files);
}

const text = (result) => result.lines.join('\n');

describe('compareBuilds', () => {
  it('одинаковые сборки — отличий нет', () => {
    const result = compareBuilds(build(), build());
    expect(result.differences).toBe(0);
    expect(text(result)).toContain('Фид: тот же.');
  });

  it('сравнение сборки с самой собой — отличий нет', () => {
    const dir = build();
    expect(compareBuilds(dir, dir).differences).toBe(0);
  });

  it('другой <title> на одной странице — отличие, в строках адрес и оба заголовка', () => {
    const after = build({ 'bukety/index.html': page({ title: 'Другие букеты', canonical: 'https://pionperm.ru/bukety/', h1: 'Букеты' }) });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    const entry = result.lines.find((l) => l.includes('bukety/index.html') && l.includes('Букеты | Пион'));
    expect(entry).toBeDefined();
    expect(entry).toContain('Другие букеты');
  });

  it('другое описание, canonical или h1 — отличие', () => {
    const base = { title: 'Букеты | Пион', canonical: 'https://pionperm.ru/bukety/', h1: 'Букеты' };
    for (const change of [{ description: 'Новое описание' }, { canonical: 'https://pionperm.ru/bukety2/' }, { h1: 'Другой заголовок' }]) {
      const result = compareBuilds(build(), build({ 'bukety/index.html': page({ ...base, ...change }) }));
      expect(result.differences, JSON.stringify(change)).toBeGreaterThan(0);
      expect(text(result)).toContain('bukety/index.html');
    }
  });

  it('другая ссылка в <main> главной (букет в «Новинках») — отличие', () => {
    const after = build({
      'index.html': page({
        links: [
          ['/bukety/buket-a/', 'Букет А'],
          ['/bukety/buket-v/', 'Букет В'],
        ],
        list: ['https://pionperm.ru/bukety/buket-a/', 'https://pionperm.ru/bukety/buket-b/'],
      }),
    });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    const entry = result.lines.find((l) => l.includes('index.html') && l.includes('/bukety/buket-v/'));
    expect(entry).toBeDefined();
    expect(entry).toContain('/bukety/buket-b/');
  });

  it('тот же адрес, другой текст ссылки в <main> — отличие', () => {
    const links = [
      ['/bukety/buket-a/', 'Букет А — дороже'],
      ['/bukety/buket-b/', 'Букет Б'],
    ];
    const after = build({ 'index.html': page({ links, list: ['https://pionperm.ru/bukety/buket-a/', 'https://pionperm.ru/bukety/buket-b/'] }) });
    expect(compareBuilds(build(), after).differences).toBeGreaterThan(0);
  });

  it('те же ссылки в другом порядке — отличие', () => {
    const after = build({
      'index.html': page({
        links: [
          ['/bukety/buket-b/', 'Букет Б'],
          ['/bukety/buket-a/', 'Букет А'],
        ],
        list: ['https://pionperm.ru/bukety/buket-a/', 'https://pionperm.ru/bukety/buket-b/'],
      }),
    });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('порядок');
  });

  it('ссылки вне <main> (шапка, подвал) отличием не считаются', () => {
    const html = page({
      links: [
        ['/bukety/buket-a/', 'Букет А'],
        ['/bukety/buket-b/', 'Букет Б'],
      ],
      list: ['https://pionperm.ru/bukety/buket-a/', 'https://pionperm.ru/bukety/buket-b/'],
    }).replace('<a href="/menu/">Меню</a>', '<a href="/menu-2/">Другое меню</a>');
    expect(compareBuilds(build(), build({ 'index.html': html })).differences).toBe(0);
  });

  it('другой список ItemList в JSON-LD — отличие (прежняя проверка)', () => {
    const after = build({
      'index.html': page({
        links: [
          ['/bukety/buket-a/', 'Букет А'],
          ['/bukety/buket-b/', 'Букет Б'],
        ],
        list: ['https://pionperm.ru/bukety/buket-a/'],
      }),
    });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('Список букетов на index.html');
  });

  it('страница пропала — отличие', () => {
    const result = compareBuilds(build(), build({ 'bukety/index.html': null }));
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('− bukety/index.html');
  });

  it('другой адрес в карте сайта — отличие', () => {
    const after = build({ 'sitemap.xml': '<urlset><url><loc>https://pionperm.ru/</loc></url></urlset>' });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('− https://pionperm.ru/bukety/');
  });

  it('другой api/catalog/bukety.json — отличие', () => {
    const after = build({ 'api/catalog/bukety.json': catalog([PRODUCTS[0], { ...PRODUCTS[1], priceRub: 4500 }]) });
    const result = compareBuilds(build(), after);
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('api/catalog/bukety.json');
  });

  it('api/catalog: порядок ключей не важен, порядок букетов важен', () => {
    const reordered = JSON.stringify({
      products: PRODUCTS.map((p) => ({ image: p.image, priceRub: p.priceRub, title: p.title, id: p.id })),
      url: 'https://pionperm.ru/bukety/',
      title: 'Букеты',
      slug: 'bukety',
    });
    expect(compareBuilds(build(), build({ 'api/catalog/bukety.json': reordered })).differences).toBe(0);
    const swapped = compareBuilds(build(), build({ 'api/catalog/bukety.json': catalog([PRODUCTS[1], PRODUCTS[0]]) }));
    expect(swapped.differences).toBeGreaterThan(0);
    expect(text(swapped)).toContain('порядок');
  });

  it('api/catalog: файл есть только в одной сборке — отличие', () => {
    const result = compareBuilds(build(), build({ 'api/catalog/bukety.json': null }));
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('api/catalog/bukety.json');
  });

  it('другой products.csv — отличие', () => {
    const result = compareBuilds(build(), build({ 'feed/products.csv': 'name;price\nБукет А;3000\nБукет Б;4100\n' }));
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('products.csv');
  });

  it('другой products.xml (не дата) — отличие', () => {
    const result = compareBuilds(build(), build({ 'feed/products.xml': xml('2026-10-08 10:00').replace('3000', '3100') }));
    expect(result.differences).toBeGreaterThan(0);
    expect(text(result)).toContain('Фид: ОТЛИЧАЕТСЯ.');
  });

  it('дата в products.xml отличием не считается', () => {
    const result = compareBuilds(build(), build({ 'feed/products.xml': xml('2026-12-31 23:59') }));
    expect(result.differences).toBe(0);
    expect(text(result)).toContain('Фид: тот же.');
  });
});

describe('compare-builds как программа', () => {
  const run = (...args) => spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8' });

  it('одинаковые сборки: код 0 и печать', () => {
    const res = run(build(), build());
    expect(res.status).toBe(0);
    expect(res.stdout).toContain('Карта сайта: было 2, стало 2.');
    expect(res.stdout).toContain('Итого отличий: 0');
  });

  it('разные сборки: код 1, с --report — код 0, печать та же', () => {
    const before = build();
    const after = build({ 'feed/products.csv': 'name;price\nБукет А;1\n' });
    const strict = run(before, after);
    expect(strict.status).toBe(1);
    expect(strict.stdout).toContain('products.csv');
    const report = run('--report', before, after);
    expect(report.status).toBe(0);
    expect(report.stdout).toBe(strict.stdout);
  });

  it('без аргументов или с несуществующей папкой: подсказка и код 2', () => {
    expect(run().status).toBe(2);
    const res = run(build(), path.join(os.tmpdir(), 'pion-compare-no-such-dir'));
    expect(res.status).toBe(2);
    expect(res.stderr).toContain('Как вызывать');
  });

  it('импорт модуля ничего не печатает и не завершает процесс', () => {
    const code = `import(${JSON.stringify(pathToFileURL(SCRIPT).href)}).then((m) => console.log(typeof m.compareBuilds));`;
    const res = spawnSync(process.execPath, ['--input-type=module', '-e', code], { encoding: 'utf8' });
    expect(res.status).toBe(0);
    expect(res.stdout).toBe('function\n');
    expect(res.stderr).toBe('');
  });
});
