import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { buildFeed, feedDate } from './build-feed.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Строка CSV в ячейки: кавычки, удвоенные кавычки, «;» внутри кавычек. */
function csvCells(line) {
  const cells = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (quoted && ch === '"' && line[i + 1] === '"') {
      cell += '"';
      i++;
    } else if (ch === '"') {
      quoted = !quoted;
    } else if (ch === ';' && !quoted) {
      cells.push(cell);
      cell = '';
    } else {
      cell += ch;
    }
  }
  return [...cells, cell];
}

const CAPS_WORD = /(?<!\p{L})\p{Lu}{3,}(?!\p{L})/u;

describe('feedDate', () => {
  it('пишет пермское время в классическом виде YML', () => {
    expect(feedDate(new Date('2026-10-05T15:07:00Z'))).toBe('2026-10-05 20:07');
  });

  it('после 19:00 по Москве в Перми уже следующий день', () => {
    expect(feedDate(new Date('2026-10-05T20:30:00Z'))).toBe('2026-10-06 01:30');
  });
});

describe('buildFeed на настоящих данных сайта', () => {
  const { yml, csv } = buildFeed({ root, siteUrl: 'https://pionperm.ru', date: '2026-10-05 20:00' });
  const offers = (yml.match(/<offer /g) ?? []).length;
  const [header, ...rows] = csv.trimEnd().split('\n').map(csvCells);

  it('в CSV те же товары и в том же порядке, что в YML', () => {
    expect(header).toEqual(['name', 'price', 'currencyId', 'category', 'url', 'picture', 'id', 'description']);
    expect(rows.map((row) => row[6])).toEqual([...yml.matchAll(/<offer id="(\d+)"/g)].map((m) => m[1]));
    for (const row of rows) expect(row).toHaveLength(8);
  });

  it('в названиях нет слов заглавными буквами, описания не длиннее 500 знаков', () => {
    for (const row of rows) {
      expect(row[0]).not.toMatch(CAPS_WORD);
      expect(row[7].length).toBeLessThanOrEqual(500);
    }
    for (const m of yml.matchAll(/<name>([^<]*)<\/name>/g)) expect(m[1]).not.toMatch(CAPS_WORD);
  });

  it('категории — разделы навигации: «Цветы» с подразделами и разделы каталога', () => {
    const names = [...yml.matchAll(/<category id="\d+"(?: parentId="\d+")?>([^<]+)<\/category>/g)].map((m) => m[1]);
    expect(names).toEqual([
      'Цветы',
      'Розы',
      'Пионы',
      'Разные цветы',
      'Букеты',
      'Корзины цветов',
      'Коробки с цветами',
      'Свадебные букеты',
      'Шоколад',
      'Лучшее для дома',
      'Воздушные шары',
      'Продукция Flame',
    ]);
  });

  it('без архивных сезонных коллекций и без товаров без цены', () => {
    expect(offers).toBeGreaterThan(300);
    expect(yml).not.toContain('/new-year-2025/');
    expect(yml).not.toContain('/valentinesday/');
    for (const m of yml.matchAll(/<price>(\d+)<\/price>/g)) expect(Number(m[1])).toBeGreaterThan(0);
  });

  it('у каждого товара страница на pionperm.ru и фото в JPG', () => {
    expect(yml.match(/<url>https:\/\/pionperm\.ru\/[a-z0-9-]+\/[a-z0-9-]+\/<\/url>/g)).toHaveLength(offers);
    expect(yml.match(/<picture>https:\/\/pionperm\.ru\/feed\/img\/[a-z0-9-]+\/[a-z0-9-]+\.jpg<\/picture>/g)).toHaveLength(offers);
  });

  it('в описаниях нет слова «заказ»', () => {
    for (const m of yml.matchAll(/<description>([^<]*)<\/description>/g)) expect(m[1]).not.toMatch(/заказ/i);
  });
});
