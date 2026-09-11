/** @type {import('next').NextConfig} */

// GitHub Pages serves plain static files from a subpath (kidw3st.github.io/pion),
// so the Pages build needs a static export and a base path. Local `npm run dev`
// and `npm run build` leave GITHUB_PAGES unset and behave normally (site at /).
const isPages = process.env.GITHUB_PAGES === 'true';
const repo = '/pion';

// Боевая площадка — pionperm.ru, поэтому она и есть значение по умолчанию.
// Раньше по умолчанию стоял адрес GitHub Pages, и сборка без переменной молча
// уезжала на сервер с canonical, og и sitemap на github.io: поисковик читал это
// как «оригинал не здесь». Теперь забытая переменная даёт правильный боевой
// адрес, а тестовая копия берёт свой из флага, который и так выставляет.
const SITE_URL =
  process.env.SITE_URL || (isPages ? 'https://kidw3st.github.io/pion' : 'https://pionperm.ru');

const nextConfig = {
  output: 'export',
  // No image optimisation server on Pages: images come straight from /public.
  // The custom loader is what prefixes their paths with basePath — Next applies
  // basePath to routes, but not to an image's src.
  images: {
    loader: 'custom',
    loaderFile: './src/lib/imageLoader.ts',
  },
  env: {
    NEXT_PUBLIC_BASE_PATH: isPages ? repo : '',
    // Один источник адреса на весь проект: и для метаданных страниц
    // (src/lib/seo.ts), и для генератора robots/llms/api — чтобы задавать
    // приходилось одну переменную, а не две с одинаковым смыслом.
    NEXT_PUBLIC_SITE_URL: SITE_URL,
  },
  // Emits /bukety/index.html rather than /bukety.html, which is what Pages
  // expects when resolving a directory URL.
  trailingSlash: true,
  basePath: isPages ? repo : '',
  assetPrefix: isPages ? `${repo}/` : '',
};

export default nextConfig;
