/**
 * Не даёт выложить сборку, которая указывает поисковикам на чужой домен.
 *
 * История, ради которой это написано: адрес сайта задавался двумя разными
 * переменными (`SITE_URL` для robots/llms/api и `NEXT_PUBLIC_SITE_URL` для
 * canonical/og/Schema/sitemap) с одинаковым значением по умолчанию — адресом
 * тестовой копии на GitHub Pages. Сборка без переменных выглядела совершенно
 * рабочей, и три дня боевой сайт просил Яндекс считать первоисточником
 * github.io. Проверка «я же поправил robots.txt» это не поймала, потому что
 * смотрела не туда.
 *
 * Поэтому здесь проверяется не то, что чинили, а то, что должно быть верно:
 * готовый out/ целиком, включая сам HTML страниц.
 *
 * Запускается автоматически как часть postbuild.
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'out');

// Проверка односторонняя: бережём боевой сайт. В сборке для GitHub Pages
// упоминания pionperm.ru законны — это адрес салона в текстах и контактах, а
// не указание поисковику, поэтому там сканировать нечего.
const isPages = process.env.GITHUB_PAGES === 'true';

if (isPages) {
  console.log('[check-urls] сборка для GitHub Pages — проверка боевых адресов не нужна');
  process.exit(0);
}

const EXPECTED = process.env.SITE_URL || 'https://pionperm.ru';
const FOREIGN = 'kidw3st.github.io';

if (!existsSync(OUT)) {
  console.error('[check-urls] нет папки out — сначала next build');
  process.exit(1);
}

// Смотрим только то, что читают поисковики. Скрипты и стили могут содержать
// что угодно — на индексацию это не влияет.
const EXT = ['.html', '.xml', '.txt', '.json', '.md'];

function walk(dir, acc = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      // Блог обслуживает WordPress, его файлы к этой сборке не относятся.
      if (name === '_next' || name === 'blog') continue;
      walk(full, acc);
    } else if (EXT.some((e) => name.endsWith(e))) {
      acc.push(full);
    }
  }
  return acc;
}

const files = walk(OUT);
const offenders = [];

for (const file of files) {
  const text = readFileSync(file, 'utf8');
  const hits = text.split(FOREIGN).length - 1;
  if (hits > 0) offenders.push({ file: relative(OUT, file), hits });
}

// Отдельно — прямая проверка того, из-за чего всё и случилось: canonical на
// ключевых страницах должен указывать на нашу площадку.
const mustHaveCanonical = ['index.html', 'catalog/index.html', 'contacts/index.html'];
const canonicalProblems = [];

for (const rel of mustHaveCanonical) {
  const full = join(OUT, rel);
  if (!existsSync(full)) continue;
  const html = readFileSync(full, 'utf8');
  const found = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
  if (!found) canonicalProblems.push(`${rel}: canonical отсутствует`);
  else if (!found.startsWith(EXPECTED)) canonicalProblems.push(`${rel}: canonical → ${found}`);
}

if (offenders.length === 0 && canonicalProblems.length === 0) {
  console.log(`[check-urls] адреса в порядке: везде ${EXPECTED}, проверено ${files.length} файлов`);
  process.exit(0);
}

console.error('\n[check-urls] СБОРКА НЕ ГОДИТСЯ ДЛЯ ВЫКЛАДКИ\n');

if (canonicalProblems.length) {
  console.error('  Канонические ссылки:');
  for (const p of canonicalProblems) console.error(`    ✗ ${p}`);
  console.error('');
}

if (offenders.length) {
  const total = offenders.reduce((n, o) => n + o.hits, 0);
  console.error(`  Чужой домен «${FOREIGN}» встречается ${total} раз в ${offenders.length} файл(ах):`);
  for (const o of offenders.slice(0, 12)) console.error(`    ✗ ${o.file} — ${o.hits}`);
  if (offenders.length > 12) console.error(`    … и ещё ${offenders.length - 12}`);
  console.error('');
}

console.error(`  Ожидался адрес: ${EXPECTED}`);
console.error('  Боевая сборка: npm run build:prod');
console.error('  Сборка для GitHub Pages: GITHUB_PAGES=true npm run build\n');
process.exit(1);
