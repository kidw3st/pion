// Generates the machine-readable files agents look for, into public/ so they
// ship with the static export. Run automatically before `npm run build`.
//
// Scope note: several agent-discovery mechanisms cannot be satisfied by a
// static site served from a subpath, and are deliberately not faked here —
// see README "Agent discovery" for what needs a different host or DNS access.

import { mkdir, writeFile, readFile, readdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { dedupeProducts } from './lib/dedupeProducts.mjs';

const ROOT = path.resolve(import.meta.dirname, '..');
const PUB = path.join(ROOT, 'public');
const SITE_URL = process.env.SITE_URL || 'https://kidw3st.github.io/pion';

const readJson = async (p) => JSON.parse(await readFile(path.join(ROOT, p), 'utf-8'));
const write = async (rel, contents) => {
  const dest = path.join(PUB, rel);
  await mkdir(path.dirname(dest), { recursive: true });
  await writeFile(dest, contents, 'utf-8');
  return dest;
};

const site = await readJson('data/site.json');
const meta = await readJson('data/catalog-meta.json');
const catalogFiles = (await readdir(path.join(ROOT, 'data/catalog'))).filter((f) => f.endsWith('.json'));

// ---------------------------------------------------------------- robots.txt
// Content Signals (contentsignals.org) declare how this content may be used.
// ai-train=no keeps the shop's photography out of training corpora; search and
// ai-input stay yes so the shop can still be found and answered about.
// Сборщики ссылочных баз и SEO-краулеры: выкачивают каталог, создают нагрузку
// и не приводят ни одного покупателя. Тех, кто проигнорирует robots.txt,
// останавливают правила в public/.htaccess. Поисковики и ИИ-ассистенты сюда
// намеренно не попадают — через них нас находят.
const BLOCKED_CRAWLERS = [
  'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'BLEXBot', 'DataForSeoBot',
  'serpstatbot', 'MegaIndex', 'ZoominfoBot', 'Barkrowler', 'SeekportBot',
];

await write(
  'robots.txt',
  [
    '# Content preferences, see https://contentsignals.org/',
    'Content-Signal: ai-train=no, search=yes, ai-input=yes',
    '',
    'User-agent: *',
    'Content-Signal: ai-train=no, search=yes, ai-input=yes',
    'Allow: /',
    '',
    '# Служебные адреса индексировать незачем.',
    'Disallow: /pay/',
    'Disallow: /checkout/',
    '',
    ...BLOCKED_CRAWLERS.flatMap((bot) => [`User-agent: ${bot}`, 'Disallow: /', '']),
    '# Поисковикам — просьба не бомбить сервер.',
    'User-agent: Yandex',
    'Crawl-delay: 1',
    'Allow: /',
    '',
    'User-agent: Bingbot',
    'Crawl-delay: 1',
    'Allow: /',
    '',
    `Sitemap: ${SITE_URL}/sitemap.xml`,
    '',
  ].join('\n'),
);

// ------------------------------------------------------------------ llms.txt
const categoryLines = meta.tiles
  .filter((t) => !t.href.startsWith('#'))
  .map((t) => `- [${t.label}](${SITE_URL}${t.href}/)`);

await write(
  'llms.txt',
  [
    '# Салон цветов и подарков «Пион»',
    '',
    `> Цветочный салон в Перми: букеты, композиции, декор и подарки. ${site.address}, тел. ${site.phone}.`,
    '',
    'Заказы через сайт оформляются как заявка — оплата и доставка согласуются по телефону.',
    '',
    '## Разделы каталога',
    ...categoryLines,
    '',
    '## Информация',
    `- [О нас](${SITE_URL}/about/)`,
    `- [Доставка и оплата](${SITE_URL}/delivery-and-payment/)`,
    `- [Акции](${SITE_URL}/stock/)`,
    `- [Контакты](${SITE_URL}/contacts/)`,
    '',
    '## Машиночитаемые данные',
    `- [Каталог и контакты (JSON)](${SITE_URL}/api/index.json)`,
    '',
  ].join('\n'),
);

// ----------------------------------------------------- static JSON for agents
// Not an HTTP API — plain files, but they give agents the catalogue without
// scraping the rendered pages.
const categories = [];
for (const file of catalogFiles) {
  const slug = file.replace(/\.json$/, '');
  // Mirrors ONLY_ACTIVE_IN_STORE in src/lib/content.ts, which is off: agents
  // are given the same full range the pages show, switched-off items included.
  const products = dedupeProducts(await readJson(`data/catalog/${file}`));
  const cat = meta.categories?.[slug] ?? null;
  categories.push({
    slug,
    title: cat?.title ?? slug,
    url: `${SITE_URL}/${slug}/`,
    productCount: products.length,
  });
  await write(
    `api/catalog/${slug}.json`,
    JSON.stringify(
      {
        slug,
        title: cat?.title ?? slug,
        url: `${SITE_URL}/${slug}/`,
        products: products.map((p) => ({
          id: p.uid,
          title: p.title,
          composition: p.description,
          priceRub: p.price,
          image: p.images[0] ? `${SITE_URL}${p.images[0]}` : null,
        })),
      },
      null,
      2,
    ) + '\n',
  );
}

await write(
  'api/index.json',
  JSON.stringify(
    {
      name: 'Салон цветов и подарков «Пион»',
      url: `${SITE_URL}/`,
      phone: site.phone,
      address: site.address,
      openingHours: site.footer.hours,
      social: site.social,
      categories,
      note: 'Статические файлы, не HTTP API. Заказ оформляется заявкой на сайте, оплата согласуется отдельно.',
    },
    null,
    2,
  ) + '\n',
);

// -------------------------------------------------- agent skills discovery
// Agent Skills Discovery RFC v0.2.0: an index of skill documents, each with a
// sha256 so a client can verify what it fetched.
const skillBody = [
  '---',
  'name: pion-catalog',
  'description: Look up flower-shop products, prices and contact details for Салон «Пион» (Пермь)',
  '---',
  '',
  '# Каталог салона «Пион»',
  '',
  'Товары и контакты доступны статическими JSON-файлами — HTML разбирать не нужно.',
  '',
  '## Точки входа',
  '',
  `- \`${SITE_URL}/api/index.json\` — контакты, режим работы, список категорий с количеством товаров.`,
  `- \`${SITE_URL}/api/catalog/<slug>.json\` — товары категории: название, состав, цена в рублях, фото.`,
  '',
  '## Что важно знать',
  '',
  '- Цены в рублях, поле `priceRub` — целое число.',
  '- Часть категорий сейчас пуста (`productCount: 0`) — это соответствует состоянию салона, а не ошибке.',
  '- Оформление заказа на сайте создаёт заявку; оплата и доставка согласуются по телефону.',
  '',
].join('\n');

await write('.well-known/agent-skills/pion-catalog/SKILL.md', skillBody);

await write(
  '.well-known/agent-skills/index.json',
  JSON.stringify(
    {
      $schema: 'https://agentskills.io/schemas/v0.2.0/index.json',
      version: '0.2.0',
      skills: [
        {
          name: 'pion-catalog',
          type: 'skill',
          description: 'Каталог, цены и контакты салона цветов «Пион» в машиночитаемом виде',
          url: `${SITE_URL}/.well-known/agent-skills/pion-catalog/SKILL.md`,
          sha256: createHash('sha256').update(skillBody, 'utf-8').digest('hex'),
        },
      ],
    },
    null,
    2,
  ) + '\n',
);

// ------------------------------------------------------------ markdown copies
// True `Accept: text/markdown` negotiation needs a server; static twins are the
// closest a static export gets, and they are what most agents fall back to.
const pageFiles = (await readdir(path.join(ROOT, 'data/pages'))).filter((f) => f.endsWith('.json'));
const sectionToMd = (s) => {
  if (s.kind === 'cover' || s.kind === 'text') {
    return [s.title ? `## ${s.title.replace(/\n/g, ' ')}` : '', s.body || s.subtitle || '']
      .filter(Boolean)
      .join('\n\n');
  }
  if (s.kind === 'cards') return s.items.map((i) => `- **${i.title}** — ${i.subtitle}`).join('\n');
  if (s.kind === 'quote') return `> ${s.text.replace(/\n/g, ' ')}`;
  return '';
};

for (const file of pageFiles) {
  const slug = file.replace(/\.json$/, '');
  const sections = await readJson(`data/pages/${file}`);
  const body = sections.map(sectionToMd).filter(Boolean).join('\n\n');
  await write(`md/${slug}.md`, `# ${slug}\n\n${body}\n`);
}

for (const cat of categories) {
  const data = await readJson(`api/../public/api/catalog/${cat.slug}.json`).catch(() => null);
  if (!data) continue;
  const rows = data.products.map((p) => `- **${p.title}** — ${p.priceRub} р. ${p.composition}`.trim());
  await write(
    `md/${cat.slug}.md`,
    `# ${cat.title}\n\n${rows.length ? rows.join('\n') : 'В этой категории сейчас нет товаров.'}\n`,
  );
}

console.log(
  `agent assets: robots.txt, llms.txt, api/index.json + ${categories.length} categories, ` +
    `agent-skills index, ${pageFiles.length + categories.length} markdown copies`,
);
