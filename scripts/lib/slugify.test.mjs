import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { slugify } from './slugify.mjs';

describe('slugify', () => {
  it.each([
    ['Букет «Бархатные грани»', 'buket-barhatnye-grani'],
    ['Ёлочка', 'elochka'],
    ['Пион Sara Bernhardt', 'pion-sara-bernhardt'],
    ['Шёлк и щётка', 'shelk-i-schetka'],
    ['«»!!!', 'tovar'],
  ])('%s → %s', (title, slug) => {
    expect(slugify(title)).toBe(slug);
  });

  it('не длиннее 60 знаков', () => {
    expect(slugify('а'.repeat(80))).toBe('a'.repeat(60));
  });

  it('образец для PHP-сверки собран этой же функцией', () => {
    const pairs = JSON.parse(readFileSync(new URL('../../tests/php/fixtures/slugs.json', import.meta.url), 'utf8'));
    expect(pairs.length).toBeGreaterThan(400);
    for (const { title, slug } of pairs) expect(slugify(title)).toBe(slug);
  });
});
