import type { Metadata } from 'next';
import { getCatalogTiles } from '@/lib/content';
import { CatalogTiles } from '@/components/CatalogTiles/CatalogTiles';
import { JsonLd } from '@/components/JsonLd/JsonLd';
import { buildMetadata, breadcrumbJsonLd } from '@/lib/seo';

export const metadata: Metadata = {
  ...buildMetadata({
    title: 'Каталог цветов и подарков в Перми | «Пион»',
    description:
      'Букеты, корзины и коробки с цветами, комнатные растения, шоколад, шары и декор. Салон «Пион», Пермь, ул. Газеты Звезда, 27.',
    path: '/catalog/',
  }),
  title: { absolute: 'Каталог цветов и подарков в Перми | «Пион»' },
};

export default function CatalogPage() {
  return (
    <>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: 'Главная', path: '/' },
          { name: 'Каталог', path: '/catalog/' },
        ])}
      />
      <CatalogTiles tiles={getCatalogTiles()} />
    </>
  );
}
