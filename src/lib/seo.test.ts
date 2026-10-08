import { describe, expect, it } from 'vitest';
import { productListJsonLd } from './seo';

const p = (uid: string, mainSection: string, slug: string) => ({
  uid, title: `Букет ${uid}`, description: '', price: 4400, images: [], slug, mainSection,
});

describe('productListJsonLd', () => {
  it('ссылки ведут на страницу букета по его главному разделу, в каком бы разделе он ни стоял', () => {
    const list = productListJsonLd([p('1', 'bukety', 'a'), p('2', 'roses', 'b')]) as {
      itemListElement: { item: { url: string; offers: { url: string } } }[];
    };
    expect(list.itemListElement.map((i) => i.item.url)).toEqual([
      'https://pionperm.ru/bukety/a/',
      'https://pionperm.ru/roses/b/',
    ]);
    expect(list.itemListElement[1].item.offers.url).toBe('https://pionperm.ru/roses/b/');
  });
});
