import { getCatalogTiles } from '@/lib/content';
import { CatalogTiles } from '@/components/CatalogTiles/CatalogTiles';

export default function CatalogPage() {
  return <CatalogTiles tiles={getCatalogTiles()} />;
}
