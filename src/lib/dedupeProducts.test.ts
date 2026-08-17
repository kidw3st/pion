import { describe, it, expect } from 'vitest';
import { dedupeProducts } from './dedupeProducts';
import type { Product } from './types';

const product = (
  uid: string,
  title: string,
  { price = 4000, description = '', images = [] as string[] } = {},
): Product => ({ uid, title, description, price, images, slug: uid });

describe('dedupeProducts', () => {
  it('leaves a clean catalogue untouched, in order', () => {
    const catalog = [
      product('1', 'Букет «Ванильное небо»'),
      product('2', 'Букет «Для любимой»'),
      product('3', 'Гербера'),
    ];
    expect(dedupeProducts(catalog).map((p) => p.uid)).toEqual(['1', '2', '3']);
  });

  it('drops listings the store admin left behind as copies', () => {
    const catalog = [
      product('1', 'Букет «Все оттенки»'),
      product('2', 'Copy: Букет «Все оттенки»'),
      product('3', 'copy: Нарцисс'),
    ];
    expect(dedupeProducts(catalog).map((p) => p.title)).toEqual(['Букет «Все оттенки»']);
  });

  it('keeps only one listing per name', () => {
    const catalog = [
      product('1', 'Букет «Утро в Париже»'),
      product('2', 'Букет «Утро в Париже»'),
    ];
    expect(dedupeProducts(catalog)).toHaveLength(1);
  });

  it('keeps the duplicate that still has a photo', () => {
    const catalog = [
      product('1', 'Гербера'),
      product('2', 'Гербера', { images: ['/images/catalog/mixflower/gerbera.jpg'] }),
    ];
    expect(dedupeProducts(catalog).map((p) => p.uid)).toEqual(['2']);
  });

  it('prefers the fuller composition when both have photos', () => {
    const catalog = [
      product('1', 'Букет «Для любимой»', { images: ['/a.jpg'] }),
      product('2', 'Букет «Для любимой»', { images: ['/b.jpg'], description: 'Состав: розы, эустома' }),
    ];
    expect(dedupeProducts(catalog).map((p) => p.uid)).toEqual(['2']);
  });

  it('keeps the earlier listing when the two are equally complete', () => {
    const catalog = [
      product('1', 'Букет «Ничего лишнего»', { images: ['/a.jpg'], description: 'Розы' }),
      product('2', 'Букет «Ничего лишнего»', { images: ['/b.jpg'], description: 'Розы' }),
    ];
    expect(dedupeProducts(catalog).map((p) => p.uid)).toEqual(['1']);
  });

  it('treats quote style and spacing as the same name', () => {
    const catalog = [
      product('1', 'Букет «Ягодное варенье»'),
      product('2', 'Букет "Ягодное  варенье"'),
    ];
    expect(dedupeProducts(catalog)).toHaveLength(1);
  });

  it('holds the surviving duplicate in the position of the first listing', () => {
    const catalog = [
      product('1', 'Гербера'),
      product('2', 'Ранункулюс'),
      product('3', 'Гербера', { images: ['/g.jpg'] }),
    ];
    expect(dedupeProducts(catalog).map((p) => p.uid)).toEqual(['3', '2']);
  });

  it('drops entries left with no name at all', () => {
    expect(dedupeProducts([product('1', '   ')])).toEqual([]);
  });
});
