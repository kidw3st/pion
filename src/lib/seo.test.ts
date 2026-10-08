import { describe, expect, it } from 'vitest';
import { productJsonLd, productListJsonLd } from './seo';

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

describe('productJsonLd', () => {
  const product = { title: 'Букет «А»', description: 'Роза', price: 4400, images: ['/images/catalog/bukety/a.webp'] };

  it('в продаже — под заказ, как раньше', () => {
    const ld = productJsonLd(product, 'bukety', 'a') as { offers: { availability: string; price: number } };
    expect(ld.offers.availability).toBe('https://schema.org/PreOrder');
    expect(ld.offers.price).toBe(4400);
  });

  it('снят с продажи — нет в наличии', () => {
    const ld = productJsonLd(product, 'bukety', 'a', false) as { offers: { availability: string } };
    expect(ld.offers.availability).toBe('https://schema.org/OutOfStock');
  });

  it('без цены — без предложения: «0 ₽» поисковику не показываем', () => {
    const ld = productJsonLd({ ...product, price: 0 }, 'korobki', 'b', false) as { offers?: unknown };
    expect(ld.offers).toBeUndefined();
  });
});
