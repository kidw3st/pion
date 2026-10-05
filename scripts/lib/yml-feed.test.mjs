import { describe, expect, it } from 'vitest';
import { buildYml, cleanDescription, feedCategories } from './yml-feed.mjs';

describe('cleanDescription', () => {
  it('оставляет обычный состав как есть', () => {
    expect(cleanDescription('Состав букета: Роза одноголовая, Алиум')).toBe('Состав букета: Роза одноголовая, Алиум');
  });

  it('переписывает «Заказ от N шт» без запрещённого слова', () => {
    expect(cleanDescription('Заказ от 7 шт, цена указана за одну штуку')).toBe('От 7 шт, цена указана за одну штуку');
  });

  it('убирает предложения с запрещёнными словами', () => {
    expect(cleanDescription('Нежный букет. Скидка 10% на второй! Состав: розы.')).toBe('Нежный букет. Состав: розы.');
    expect(cleanDescription('Акция!')).toBe('');
  });

  it('не путает запрещённые слова с похожими', () => {
    expect(cleanDescription('Масла подарят коже аромат. Хитрый лис.')).toBe('Масла подарят коже аромат. Хитрый лис.');
  });

  it('склеивает переносы строк и лишние пробелы', () => {
    expect(cleanDescription('Розы,\n  пионы\r\nи эвкалипт')).toBe('Розы, пионы и эвкалипт');
  });

  it('не длиннее 5000 знаков', () => {
    expect(cleanDescription('а'.repeat(6000))).toHaveLength(5000);
  });
});

describe('feedCategories', () => {
  const catalogTiles = [
    { label: 'Цветы', href: '/flowers' },
    { label: 'Букеты', href: '/bukety' },
    { label: 'Букеты до 5000', href: '/bukety-do-5000' },
    { label: 'Создать уникальный букет', href: '#popup:individual' },
    { label: 'Шоколад', href: '/chocolate' },
  ];
  const flowerTiles = [
    { label: 'Розы', href: '/roses' },
    { label: 'Пионы', href: '/pions' },
  ];
  const sections = new Set(['bukety', 'chocolate', 'roses', 'pions', 'new-year-2025']);

  it('берёт разделы из плиток каталога и «Цветов», остальные плитки пропускает', () => {
    expect(feedCategories({ catalogTiles, flowerTiles, sections })).toEqual([
      { id: '1', name: 'Цветы' },
      { id: '2', name: 'Розы', parentId: '1', section: 'roses' },
      { id: '3', name: 'Пионы', parentId: '1', section: 'pions' },
      { id: '4', name: 'Букеты', section: 'bukety' },
      { id: '5', name: 'Шоколад', section: 'chocolate' },
    ]);
  });
});

describe('buildYml', () => {
  const categories = [
    { id: '1', name: 'Цветы' },
    { id: '2', name: 'Розы', parentId: '1', section: 'roses' },
    { id: '3', name: 'Букеты', section: 'bukety' },
  ];
  const product = {
    uid: '553645466981',
    title: 'Букет «Сад & Огород» <1>',
    description: 'Состав: розы. Скидка 5%.',
    price: 6380,
    images: ['/images/catalog/bukety/buket-sad-553645466981.webp', '/images/catalog/bukety/second.webp'],
    slug: 'buket-sad',
    section: 'bukety',
  };
  const yml = buildYml({
    siteUrl: 'https://pionperm.ru',
    date: '2026-10-05T20:00+05:00',
    categories,
    products: [
      product,
      { ...product, uid: '1', price: 0 },
      { ...product, uid: '2', section: 'new-year-2025' },
      { ...product, uid: '3', section: 'roses', slug: 'roza', title: 'Роза', description: 'Акция!' },
    ],
  });

  it('оформлен как YML-каталог с магазином и рублями', () => {
    expect(yml.startsWith('<?xml version="1.0" encoding="UTF-8"?>\n<yml_catalog date="2026-10-05T20:00+05:00">')).toBe(true);
    expect(yml).toContain('<url>https://pionperm.ru/</url>');
    expect(yml).toContain('<currency id="RUB" rate="1"/>');
    expect(yml.trimEnd().endsWith('</yml_catalog>')).toBe(true);
  });

  it('выводит вложенные категории', () => {
    expect(yml).toContain('<category id="1">Цветы</category>');
    expect(yml).toContain('<category id="2" parentId="1">Розы</category>');
  });

  it('описывает букет: адрес страницы, цену, раздел, фото в JPG и очищенное описание', () => {
    expect(yml).toContain(
      [
        '      <offer id="553645466981" available="true">',
        '        <url>https://pionperm.ru/bukety/buket-sad/</url>',
        '        <price>6380</price>',
        '        <currencyId>RUB</currencyId>',
        '        <categoryId>3</categoryId>',
        '        <picture>https://pionperm.ru/feed/img/bukety/buket-sad-553645466981.jpg</picture>',
        '        <name>Букет «Сад &amp; Огород» &lt;1&gt;</name>',
        '        <description>Состав: розы.</description>',
        '      </offer>',
      ].join('\n'),
    );
  });

  it('пропускает товары без цены и из разделов вне каталога', () => {
    expect(yml.match(/<offer /g)).toHaveLength(2);
    expect(yml).not.toContain('offer id="1"');
    expect(yml).not.toContain('offer id="2"');
  });

  it('не выводит пустое описание', () => {
    const roza = yml.slice(yml.indexOf('<offer id="3"'));
    expect(roza.slice(0, roza.indexOf('</offer>'))).not.toContain('<description>');
  });
});
