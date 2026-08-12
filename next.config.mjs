/** @type {import('next').NextConfig} */

// GitHub Pages serves plain static files from a subpath (kidw3st.github.io/pion),
// so the Pages build needs a static export and a base path. Local `npm run dev`
// and `npm run build` leave GITHUB_PAGES unset and behave normally (site at /).
const isPages = process.env.GITHUB_PAGES === 'true';
const repo = '/pion';

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
  },
  // Emits /bukety/index.html rather than /bukety.html, which is what Pages
  // expects when resolving a directory URL.
  trailingSlash: true,
  basePath: isPages ? repo : '',
  assetPrefix: isPages ? `${repo}/` : '',
};

export default nextConfig;
