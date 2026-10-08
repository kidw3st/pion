import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  RESERVED_SLUGS,
  canonicalParts,
  exportVersion,
  formatExport,
  validateExport,
} from './catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const FIXTURE = path.join(root, 'tests/php/fixtures/catalog-export-small.json');
const VERSION = '4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc';

/** Образец без версии: правки в тестах не упираются в «версия не сходится». */
function sample() {
  const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
  delete exp.version;
  return exp;
}

describe('версия выгрузки', () => {
  it('совпадает с той, что считает PHP (tests/php/catalog_export_js_test.php)', () => {
    const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    expect(exportVersion(exp)).toBe(VERSION);
    expect(exp.version).toBe(VERSION);
  });

  it('не зависит от порядка ключей в присланном JSON', () => {
    const exp = sample();
    const shuffled = {
      ...exp,
      products: exp.products.map((p) => Object.fromEntries(Object.entries(p).reverse())),
      sections: exp.sections.map((s) => Object.fromEntries(Object.entries(s).reverse())),
    };
    expect(exportVersion(shuffled)).toBe(VERSION);
    expect(Object.keys(canonicalParts(shuffled).products[0])).toEqual([
      'uid', 'slug', 'title', 'description', 'price', 'images', 'mainSection', 'status',
    ]);
  });

  it('formatExport пишет версию, время и части в каноническом порядке', () => {
    const text = formatExport(sample());
    expect(text.endsWith('}\n')).toBe(true);
    const parsed = JSON.parse(text);
    expect(Object.keys(parsed)).toEqual(['version', 'changedAt', 'sections', 'tiles', 'products', 'redirects']);
    expect(parsed.version).toBe(VERSION);
    expect(parsed.changedAt).toBe('2026-10-05T14:32:10+05:00');
  });
});

describe('validateExport', () => {
  it('образец в порядке', () => {
    expect(validateExport(JSON.parse(readFileSync(FIXTURE, 'utf8')))).toEqual([]);
  });

  it('версия не сходится с содержимым — файл повреждён', () => {
    const exp = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    exp.products[0].price = 4500;
    expect(validateExport(exp).join('\n')).toContain('Версия выгрузки не сходится');
  });

  it('нет части выгрузки', () => {
    const exp = sample();
    delete exp.tiles;
    expect(validateExport(exp)).toEqual(['В выгрузке нет части tiles.']);
  });

  const cases = [
    ['uid повторяется', (e) => { e.products[1].uid = '100000000001'; }, 'встречается дважды'],
    ['главного раздела нет', (e) => { e.products[0].mainSection = 'korobki'; }, 'главного раздела "korobki" нет'],
    ['букета нет в его главном разделе', (e) => { e.products[3].mainSection = 'roses'; }, 'его нет в его главном разделе roses'],
    ['два букета на одном адресе', (e) => { e.products[1].slug = 'buket-a'; }, 'Адрес /bukety/buket-a/ у двух букетов'],
    ['цена 0 у букета в продаже', (e) => { e.products[0].price = 0; }, 'цена 0'],
    ['цена дробная', (e) => { e.products[0].price = 4400.5; }, 'цена 4400.5'],
    ['статус черновика', (e) => { e.products[0].status = 'draft'; }, 'статус "draft"'],
    ['раздел на занятом адресе', (e) => { e.sections[2].slug = 'catalog'; e.products[4].mainSection = 'catalog'; }, 'совпадает с другой страницей сайта'],
    ['в разделе неизвестный букет', (e) => { e.sections[2].products.push('100000000009'); }, 'букета 100000000009 в выгрузке нет'],
    ['букеты не по порядку uid', (e) => { e.products.reverse(); }, 'не по порядку uid'],
    ['видимый раздел без плитки', (e) => { e.sections[2].visible = true; }, 'его плитки в сетке нет'],
    ['плитка скрытого раздела', (e) => { e.tiles.push({ type: 'section', slug: 'roses' }); }, 'Плитка скрытого раздела roses'],
    ['цепочка переадресаций', (e) => { e.redirects.push({ from: '/korobki/buket-z/', to: '/korobki/buket-a/' }); }, 'Цепочка переадресаций через /korobki/buket-a/'],
    ['переадресация с живой страницы', (e) => { e.redirects = [{ from: '/bukety/buket-a/', to: '/bukety/' }]; }, 'там живая страница букета'],
    ['чужой адрес в переадресации', (e) => { e.redirects = [{ from: '/korobki/x/', to: '//evil.example/' }]; }, 'Неправильная переадресация'],
    ['фото не из /images/', (e) => { e.products[0].images = ['https://evil.example/a.webp']; }, 'неправильные пути к фото'],
  ];
  for (const [name, mutate, message] of cases) {
    it(name, () => {
      const exp = sample();
      mutate(exp);
      expect(validateExport(exp).join('\n')).toContain(message);
    });
  }

  it('у снятого с продажи цена 0 допустима', () => {
    const exp = sample();
    exp.products[2].price = 0;
    expect(validateExport(exp)).toEqual([]);
  });

  it('защита от массовой потери: меньше 70% прошлого — стоп, allowShrink — можно', () => {
    expect(validateExport(sample(), { previousCount: 10 }).join('\n')).toContain('allow_shrink');
    expect(validateExport(sample(), { previousCount: 10, allowShrink: true })).toEqual([]);
    expect(validateExport(sample(), { previousCount: 7 })).toEqual([]);
  });
});

describe('занятые адреса', () => {
  it('тот же список, что CATALOG_RESERVED_SLUGS в server-pay/catalog/slug.php', () => {
    const php = readFileSync(path.join(root, 'server-pay/catalog/slug.php'), 'utf8');
    const body = php.match(/const CATALOG_RESERVED_SLUGS = \[([\s\S]*?)\];/)?.[1] ?? '';
    const list = [...body.matchAll(/'([^']+)'/g)].map((m) => m[1]);
    expect(list.length).toBeGreaterThan(10);
    expect(RESERVED_SLUGS).toEqual(list);
  });
});
