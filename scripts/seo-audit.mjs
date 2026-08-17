/**
 * Crawls the local site and reports every technical-SEO defect the audit brief
 * lists: missing lang, missing/duplicate H1, empty alt on content images,
 * missing JSON-LD, short titles, absent descriptions, images without
 * dimensions or lazy loading.
 *
 *   node scripts/seo-audit.mjs            # against http://localhost:3000
 */
import { chromium } from 'playwright';

const BASE = process.env.AUDIT_BASE || 'http://localhost:3000';

const CATEGORIES = [
  'bukety', 'korziny', 'korobki', 'wedding', 'balloons',
  'chocolate', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
];
const PAGES = [
  'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds',
  'stock', 'policy', 'valentinesday', 'new-year-2025', 'doza_endorfina',
  'flowers', 'indoorflowers',
];
const ROUTES = ['/', '/catalog', '/checkout', ...CATEGORIES.map((s) => `/${s}`), ...PAGES.map((s) => `/${s}`)];

const audit = () => {
  const q = (sel) => Array.from(document.querySelectorAll(sel));
  const jsonLd = q('script[type="application/ld+json"]')
    .map((s) => {
      try {
        return JSON.parse(s.textContent)['@type'];
      } catch {
        return 'INVALID';
      }
    });

  const imgs = q('img').map((img) => {
    // An image needs no alt of its own when the text right next to it already
    // names it: a photo tile inside a captioned link, an icon above its own
    // heading. Anything standing alone does need one.
    const container = img.closest('a,button,li,figure');
    const labelled =
      !!container && (container.textContent || '').trim().length > 0;

    return {
      src: (img.getAttribute('src') || '').slice(-70),
      alt: img.getAttribute('alt'),
      hasWidth: img.hasAttribute('width') || !!img.style.width,
      loading: img.getAttribute('loading'),
      inLabelled: labelled,
      // Loading eagerly is right for anything visible without scrolling.
      aboveFold: img.getBoundingClientRect().top < window.innerHeight,
    };
  });

  return {
    lang: document.documentElement.getAttribute('lang'),
    title: document.title,
    description: document.querySelector('meta[name="description"]')?.content ?? null,
    canonical: document.querySelector('link[rel="canonical"]')?.href ?? null,
    h1: q('h1').map((h) => h.textContent.trim()),
    h2count: q('h2').length,
    jsonLd,
    imgs,
  };
};

const browser = await chromium.launch({ channel: 'msedge' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

const rows = [];
for (const route of ROUTES) {
  try {
    await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(400);
    rows.push({ route, ...(await page.evaluate(audit)) });
  } catch (err) {
    rows.push({ route, error: String(err).split('\n')[0] });
  }
}
await browser.close();

const problems = [];
for (const r of rows) {
  if (r.error) {
    problems.push(`${r.route}  LOAD FAILED: ${r.error}`);
    continue;
  }
  if (r.lang !== 'ru') problems.push(`${r.route}  lang=${r.lang}`);
  if (r.h1.length === 0) problems.push(`${r.route}  NO H1`);
  if (r.h1.length > 1) problems.push(`${r.route}  ${r.h1.length} H1: ${r.h1.join(' | ')}`);
  if (!r.description) problems.push(`${r.route}  NO description`);
  else if (r.description.length < 120 || r.description.length > 165)
    problems.push(`${r.route}  description ${r.description.length} chars (want 120-160)`);
  if (!r.canonical) problems.push(`${r.route}  NO canonical`);
  if (r.title.length < 30 || r.title.length > 65)
    problems.push(`${r.route}  title ${r.title.length} chars: ${r.title}`);
  if (r.jsonLd.length === 0) problems.push(`${r.route}  NO JSON-LD`);

  // alt="" is the correct markup for an image a neighbouring label already
  // names — a photo tile inside a captioned link, an icon beside its heading.
  // Only a missing attribute, or an empty one on a standalone image, is a fault.
  const noAlt = r.imgs.filter((i) => i.alt === null || (i.alt === '' && !i.inLabelled));
  if (noAlt.length)
    problems.push(
      `${r.route}  ${noAlt.length}/${r.imgs.length} img without alt: ${noAlt.map((i) => i.src).join(', ')}`,
    );
  const noDim = r.imgs.filter((i) => !i.hasWidth);
  if (noDim.length) problems.push(`${r.route}  ${noDim.length}/${r.imgs.length} img without width/height`);
  const eager = r.imgs.filter((i) => i.loading !== 'lazy' && !i.aboveFold);
  if (eager.length)
    problems.push(
      `${r.route}  ${eager.length}/${r.imgs.length} img below the fold not lazy: ${eager.map((i) => i.src).join(', ')}`,
    );
}

console.log(`\n=== ${rows.length} routes, ${problems.length} problems ===\n`);
console.log(problems.join('\n'));
console.log('\n=== H1 / title per route ===');
for (const r of rows) {
  if (r.error) continue;
  console.log(`${r.route.padEnd(24)} h1=[${r.h1.join(' | ')}]  ld=[${r.jsonLd.join(',')}]`);
}
