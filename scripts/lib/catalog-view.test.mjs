import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  newProducts,
  productPages,
  productPath,
  relatedProducts,
  sectionMeta,
  sectionProducts,
} from './catalog-view.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const load = () => JSON.parse(readFileSync(path.join(root, 'tests/php/fixtures/catalog-export-small.json'), 'utf8'));
const uids = (list) => list.map((p) => p.uid);

describe('catalog-view на выгрузке-образце', () => {
  it('в разделе — только букеты в продаже, в порядке раздела', () => {
    expect(uids(sectionProducts(load(), 'bukety'))).toEqual(['100000000001', '100000000002']);
    expect(uids(sectionProducts(load(), 'novinki'))).toEqual(['100000000002', '100000000004']);
    expect(sectionProducts(load(), 'nope')).toEqual([]);
  });

  it('страница букета — по главному разделу; у снятого она тоже есть', () => {
    expect(findProduct(load(), 'bukety', 'buket-v')?.status).toBe('hidden');
    expect(findProduct(load(), 'novinki', 'buket-b')).toBeNull();
    expect(productPath('bukety', 'buket-a')).toBe('/bukety/buket-a/');
  });

  it('все страницы букетов: по одной на букет, по главному разделу', () => {
    expect(productPages(load())).toEqual([
      { slug: 'bukety', product: 'buket-v' },
      { slug: 'bukety', product: 'buket-a' },
      { slug: 'bukety', product: 'buket-b' },
      { slug: 'novinki', product: 'pion' },
      { slug: 'roses', product: 'pion' },
    ]);
  });

  it('похожие — букеты в продаже из главного раздела, без самого букета', () => {
    const exp = load();
    expect(uids(relatedProducts(exp, findProduct(exp, 'bukety', 'buket-a')))).toEqual(['100000000002']);
    expect(uids(relatedProducts(exp, findProduct(exp, 'bukety', 'buket-v')))).toEqual(['100000000001', '100000000002']);
  });

  it('одноимённые — по главным разделам, а не по разделам вообще', () => {
    expect(ambiguousTitles(load())).toEqual(new Set(['пион']));
  });

  it('плитки сетки: у раздела — его название, адрес и фото плитки', () => {
    expect(catalogTiles(load())).toEqual([
      { label: 'Цветы', href: '/flowers', image: '/images/site/catalog-tiles/tile-0.webp' },
      { label: 'Букеты', href: '/bukety', image: '/images/site/catalog-tiles/tile-1.webp' },
      { label: 'Создать уникальный букет', href: '#popup:individual', image: '/images/site/catalog-tiles/tile-6.webp' },
    ]);
  });

  it('оформление раздела: пустые поля — null, как было в catalog-meta.json', () => {
    expect(sectionMeta(findSection(load(), 'roses'))).toEqual({
      title: 'Лучшее для дома\nи офиса',
      sub: null,
      covers: [],
      heading: null,
      headingSub: null,
      hasNotFound: false,
    });
  });

  it('«Новинки»: первые букеты в продаже; снятые пропускаются; нет раздела — null; пусто — []', () => {
    expect(uids(newProducts(load()))).toEqual(['100000000002', '100000000004']);
    expect(uids(newProducts(load(), 1))).toEqual(['100000000002']);
    const without = load();
    without.sections = without.sections.filter((s) => s.slug !== 'novinki');
    expect(newProducts(without)).toBeNull();
    const empty = load();
    empty.sections.find((s) => s.slug === 'novinki').products = ['100000000003'];
    expect(newProducts(empty)).toEqual([]);
  });
});
