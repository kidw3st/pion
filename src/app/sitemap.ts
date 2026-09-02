import type { MetadataRoute } from 'next';
import { CATEGORY_SLUGS, PAGE_SLUGS } from '@/lib/content';
import { absoluteUrl } from '@/lib/seo';

/**
 * One entry per real route, generated from the same slug lists the router uses
 * — so the sitemap cannot list a page that does not exist, or miss one that
 * does. `trailingSlash: true` in next.config means URLs end with a slash.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  const lastModified = new Date();

  const routes = [
    { path: '/', priority: 1 },
    { path: '/catalog/', priority: 0.9 },
    // Витрина меняется каждый день, поэтому стоит высоко.
    { path: '/v-nalichii/', priority: 0.9 },
    ...CATEGORY_SLUGS.map((slug) => ({ path: `/${slug}/`, priority: 0.8 })),
    ...PAGE_SLUGS.map((slug) => ({ path: `/${slug}/`, priority: 0.6 })),
    { path: '/checkout/', priority: 0.3 },
  ];

  return routes.map(({ path, priority }) => ({
    url: absoluteUrl(path),
    lastModified,
    changeFrequency: priority >= 0.8 ? 'weekly' : 'monthly',
    priority,
  }));
}
