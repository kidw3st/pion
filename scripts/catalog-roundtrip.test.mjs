/**
 * Сверка «туда-обратно» (спецификация, «Перенос нынешнего каталога», п. 2):
 * снимок data/catalog-export.json, прочитанный так, как его читает сайт,
 * даёт тот же каталог, что старые файлы Tilda, прочитанные по-старому:
 * те же разделы, букеты в том же порядке, те же цены, фото и адреса.
 * Единственное отличие — 19 коробок с ценой 0 ₽: их нет в разделе, но
 * страница у них осталась.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { dedupeProducts } from './lib/dedupeProducts.mjs';
import {
  ambiguousTitles,
  catalogTiles,
  findProduct,
  findSection,
  productPages,
  sectionMeta,
  sectionProducts,
} from './lib/catalog-view.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel) => JSON.parse(readFileSync(path.join(root, rel), 'utf8'));
const exp = read('data/catalog-export.json');
const meta = read('data/catalog-meta.json');

/** Названия разделов из кода сайта до этапа 2В (CATEGORY_LABELS). */
const LABELS_BEFORE = {
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
const before = Object.fromEntries(
  Object.keys(LABELS_BEFORE).map((slug) => [slug, dedupeProducts(read(`data/catalog/${slug}.json`))]),
);
const card = (p) => ({ uid: String(p.uid), slug: p.slug, title: p.title, description: p.description, price: p.price, images: p.images });

describe('снимок даёт тот же каталог, что старые файлы', () => {
  it('те же разделы и названия', () => {
    expect(Object.fromEntries(exp.sections.map((s) => [s.slug, s.label]))).toEqual(LABELS_BEFORE);
  });

  for (const slug of Object.keys(LABELS_BEFORE)) {
    it(`раздел ${slug}: те же букеты в том же порядке (без коробок за 0 ₽)`, () => {
      expect(sectionProducts(exp, slug).map(card)).toEqual(before[slug].filter((p) => p.price > 0).map(card));
    });
  }

  it('те же страницы букетов — все 487, включая снятые коробки', () => {
    const was = Object.entries(before).flatMap(([slug, list]) => list.map((p) => `/${slug}/${p.slug}/`)).sort();
    const now = productPages(exp).map((p) => `/${p.slug}/${p.product}/`).sort();
    expect(now).toEqual(was);
    expect(now).toHaveLength(487);
  });

  it('на странице каждого букета — те же название, состав, цена и фото', () => {
    for (const [slug, list] of Object.entries(before)) {
      for (const p of list) expect(card(findProduct(exp, slug, p.slug))).toEqual(card(p));
    }
  });

  it('те же плитки сетки каталога', () => {
    expect(catalogTiles(exp)).toEqual(meta.tiles);
  });

  it('то же оформление разделов (пустая строка и null — одно и то же)', () => {
    const norm = (m) => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, v === '' ? null : v]));
    for (const slug of Object.keys(LABELS_BEFORE)) {
      expect(norm(sectionMeta(findSection(exp, slug)))).toEqual(norm(meta.categories[slug]));
    }
  });

  it('те же одноимённые букеты', () => {
    const seen = new Map();
    for (const [slug, list] of Object.entries(before)) {
      for (const p of list) {
        const key = p.title.trim().toLowerCase();
        seen.set(key, new Set([...(seen.get(key) ?? []), slug]));
      }
    }
    const was = new Set([...seen].filter(([, s]) => s.size > 1).map(([t]) => t));
    expect(ambiguousTitles(exp)).toEqual(was);
  });
});
