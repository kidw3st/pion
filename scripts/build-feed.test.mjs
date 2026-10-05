import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { buildFeed } from './build-feed.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

describe('buildFeed на настоящих данных сайта', () => {
  const yml = buildFeed({ root, siteUrl: 'https://pionperm.ru', date: '2026-10-05T20:00+05:00' });
  const offers = (yml.match(/<offer /g) ?? []).length;

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
