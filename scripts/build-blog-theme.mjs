/**
 * Собирает тему WordPress для блога и кладёт её в out/, чтобы она уезжала на
 * сервер тем же архивом, что и сайт (см. pion-server-deploy).
 *
 * Тема должна выглядеть как продолжение сайта, поэтому три вещи она берёт не
 * из своих исходников, а из того же источника, что и сам сайт:
 *   1. меню, контакты и подвал — из data/site.json (site-data.php);
 *   2. шрифт Montserrat — теми же файлами, что положил next/font, чтобы
 *      начертание совпадало точно и не зависело от внешних сервисов;
 *   3. картинки логотипа — по абсолютным адресам сайта, копировать не нужно.
 *
 * Запускается автоматически как postbuild, после next build.
 */

import { readFileSync, writeFileSync, mkdirSync, cpSync, readdirSync, copyFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, 'wp-theme', 'pion');
const OUT = join(ROOT, 'out', 'blog', 'wp-content', 'themes', 'pion');

if (!existsSync(SRC)) {
  console.error('[blog-theme] нет папки wp-theme/pion — пропускаю');
  process.exit(0);
}

if (!existsSync(join(ROOT, 'out'))) {
  console.error('[blog-theme] нет папки out — сначала next build');
  process.exit(1);
}

// ------------------------------------------------------------------ шаблоны
mkdirSync(OUT, { recursive: true });
cpSync(SRC, OUT, { recursive: true });

// --------------------------------------------------------- данные из сайта
const site = JSON.parse(readFileSync(join(ROOT, 'data', 'site.json'), 'utf8'));

const data = {
  nav: site.nav.map(({ label, href }) => ({ label, href })),
  phone: site.phone,
  address: site.address,
  social: site.social.map(({ label, href }) => ({ label, href })),
  footer: {
    columns: site.footer.columns.map((c) => ({
      title: c.title,
      links: c.links.map(({ label, href }) => ({ label, href })),
    })),
    legal: site.footer.legal,
    hours: site.footer.hours,
  },
};

/** PHP-литерал из значения JSON. Строки — в одинарных кавычках с экранированием. */
function php(value, indent = 1) {
  const pad = '    '.repeat(indent);
  const padEnd = '    '.repeat(indent - 1);

  if (typeof value === 'string') {
    return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  if (Array.isArray(value)) {
    if (value.length === 0) return '[]';
    return `[\n${value.map((v) => `${pad}${php(v, indent + 1)},`).join('\n')}\n${padEnd}]`;
  }
  const entries = Object.entries(value);
  if (entries.length === 0) return '[]';
  return `[\n${entries
    .map(([k, v]) => `${pad}${php(k, indent + 1)} => ${php(v, indent + 1)},`)
    .join('\n')}\n${padEnd}]`;
}

writeFileSync(
  join(OUT, 'site-data.php'),
  `<?php
/**
 * Сгенерировано автоматически из data/data/site.json — руками не править.
 * Файл перезаписывается на каждой сборке (scripts/build-blog-theme.mjs).
 */

return ${php(data)};
`.replace('data/data/site.json', 'data/site.json'),
  'utf8',
);

// ------------------------------------------------------------------- шрифт
// next/font кладёт Montserrat в out/_next/static/media под хэшированными
// именами и описывает его в собранном CSS. Забираем оттуда и файлы, и правила
// @font-face — тогда блог получает тот же шрифт с теми же поддиапазонами.
const cssDir = join(ROOT, 'out', '_next', 'static', 'css');
const mediaDir = join(ROOT, 'out', '_next', 'static', 'media');
const fontsDir = join(OUT, 'fonts');

let faces = [];

if (existsSync(cssDir)) {
  const css = readdirSync(cssDir)
    .filter((f) => f.endsWith('.css'))
    .map((f) => readFileSync(join(cssDir, f), 'utf8'))
    .join('\n');

  faces = [...css.matchAll(/@font-face\{([^}]*Montserrat[^}]*)\}/g)].map((m) => m[1]);
}

if (faces.length === 0) {
  console.error('[blog-theme] в сборке не нашёлся Montserrat — тема останется без своего шрифта');
} else {
  mkdirSync(fontsDir, { recursive: true });

  const seen = new Set();
  const rules = [];

  for (const body of faces) {
    const file = body.match(/url\(\/_next\/static\/media\/([^)]+)\)/)?.[1];
    if (!file) continue;

    if (!seen.has(file)) {
      copyFileSync(join(mediaDir, file), join(fontsDir, file));
      seen.add(file);
    }

    rules.push(
      `@font-face{${body
        .replace(/font-family:[^;]+;/, "font-family:'Montserrat';")
        .replace(/url\(\/_next\/static\/media\/[^)]+\)/, `url(./${file})`)}}`,
    );
  }

  writeFileSync(
    join(fontsDir, 'fonts.css'),
    `/* Сгенерировано scripts/build-blog-theme.mjs из сборки сайта.\n`
      + `   Те же файлы Montserrat, что грузит pionperm.ru. Руками не править. */\n`
      + rules.join('\n')
      + '\n',
    'utf8',
  );

  console.log(`[blog-theme] шрифт: ${seen.size} файл(ов), ${rules.length} правил @font-face`);
}

console.log(`[blog-theme] тема собрана в out/blog/wp-content/themes/pion`);
