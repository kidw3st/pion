/**
 * Проверка выгрузки каталога и запись её для сборки сайта.
 *
 *   node scripts/apply-catalog-export.mjs <файл или https://…> [--out data/catalog-export.json] [--allow-shrink]
 *   node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]
 *
 * Первая форма — для сборки: проверить и записать. На этапе 3 сюда придёт
 * выгрузка с сервера. Защита от массовой потери сравнивает число букетов с
 * файлом, который сейчас лежит по адресу --out. Вторая форма проверяет файл
 * на месте и ничего не меняет — так делает prebuild.
 *
 * Код выхода: 0 — принято, 1 — выгрузка не принята, 2 — неверный вызов.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatExport, validateExport } from './lib/catalog-export.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DEFAULT_FILE = path.join(ROOT, 'data', 'catalog-export.json');
const USAGE = [
  'Как вызывать:',
  '  node scripts/apply-catalog-export.mjs <файл или https://адрес> [--out data/catalog-export.json] [--allow-shrink]',
  '  node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]',
].join('\n');

async function load(source) {
  if (/^https?:\/\//.test(source)) {
    const res = await fetch(source);
    if (!res.ok) throw new Error(`сервер ответил ${res.status}`);
    return JSON.parse(await res.text());
  }
  return JSON.parse(readFileSync(source, 'utf8'));
}

/** Сколько букетов в файле, который лежит сейчас; null — файла нет или он не читается. */
function previousCount(file) {
  if (!existsSync(file)) return null;
  try {
    const products = JSON.parse(readFileSync(file, 'utf8')).products;
    return Array.isArray(products) ? products.length : null;
  } catch {
    return null;
  }
}

export async function applyCatalogExport({ source, out = DEFAULT_FILE, allowShrink = false, check = false }) {
  let exp;
  try {
    exp = await load(source);
  } catch (error) {
    return { ok: false, errors: [`Не удалось прочитать выгрузку ${source}: ${error.message}`] };
  }
  const errors = validateExport(exp, { previousCount: check ? null : previousCount(out), allowShrink });
  if (errors.length) return { ok: false, errors };
  const text = formatExport(exp);
  if (!check) writeFileSync(out, text);
  return {
    ok: true,
    errors: [],
    products: exp.products.length,
    sections: exp.sections.length,
    version: JSON.parse(text).version,
  };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const positional = [];
  let out = DEFAULT_FILE;
  let check = false;
  let allowShrink = false;
  let bad = false;
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--check') check = true;
    else if (args[i] === '--allow-shrink') allowShrink = true;
    else if (args[i] === '--out' && args[i + 1]) out = path.resolve(args[++i]);
    else if (args[i].startsWith('--')) bad = true;
    else positional.push(args[i]);
  }
  const source = positional[0] ?? (check ? DEFAULT_FILE : undefined);
  if (bad || !source || positional.length > 1) {
    console.error(USAGE);
    process.exit(2);
  }
  const r = await applyCatalogExport({ source, out, allowShrink, check });
  if (!r.ok) {
    console.error('Выгрузка не принята:');
    for (const error of r.errors.slice(0, 30)) console.error(`- ${error}`);
    if (r.errors.length > 30) console.error(`…и ещё ${r.errors.length - 30}.`);
    process.exit(1);
  }
  const summary = `разделов ${r.sections}, букетов ${r.products}, версия ${r.version.slice(0, 12)}`;
  console.log(check ? `Выгрузка в порядке: ${summary}.` : `Записано в ${path.relative(ROOT, out)}: ${summary}.`);
}
