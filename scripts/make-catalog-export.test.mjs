import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { SECTION_LABELS, SECTION_SEO, makeCatalogExport } from './make-catalog-export.mjs';
import { exportVersion, formatExport, validateExport } from './lib/catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const snapshot = JSON.parse(readFileSync(path.join(root, 'data/catalog-export.json'), 'utf8'));

describe('снимок каталога data/catalog-export.json', () => {
  it('не устарел: совпадает с тем, что собирается из data/catalog/*.json (иначе — npm run catalog:snapshot)', () => {
    expect(snapshot).toEqual(JSON.parse(formatExport(makeCatalogExport(root))));
  });

  it('проходит проверку перед сборкой, версия сходится', () => {
    expect(validateExport(snapshot)).toEqual([]);
    expect(snapshot.version).toBe(exportVersion(snapshot));
    expect(snapshot.changedAt).toBeNull();
    expect(snapshot.redirects).toEqual([]);
  });

  it('13 разделов, 12 плиток, 487 букетов; без цены — только 19 коробок, и они сняты', () => {
    expect(snapshot.sections.map((s) => s.slug)).toEqual(Object.keys(SECTION_LABELS).sort());
    expect(snapshot.tiles).toHaveLength(12);
    expect(snapshot.products).toHaveLength(487);
    const hidden = snapshot.products.filter((p) => p.status === 'hidden');
    expect(hidden).toHaveLength(19);
    expect(hidden.every((p) => p.price === 0 && p.mainSection === 'korobki')).toBe(true);
    expect(snapshot.products.filter((p) => p.status === 'active').every((p) => p.price > 0)).toBe(true);
  });

  it('в каталоге видны те 8 разделов, у которых есть плитка', () => {
    expect(snapshot.sections.filter((s) => s.visible).map((s) => s.slug).sort()).toEqual(
      ['balloons', 'bukety', 'chocolate', 'flame', 'korobki', 'korziny', 'luchshee', 'wedding'],
    );
  });

  it('заголовки для поиска написаны вручную только у трёх сезонных разделов', () => {
    const custom = snapshot.sections.filter((s) => s.seoTitle !== null).map((s) => s.slug);
    expect(custom.sort()).toEqual(['new-year-2025', 'valentinesday', 'wedding']);
    expect(snapshot.sections.find((s) => s.slug === 'wedding').seoTitle).toBe(SECTION_SEO.wedding.title);
  });
});
