/**
 * Проверка выгрузки каталога и запись её для сборки сайта.
 *
 *   node scripts/apply-catalog-export.mjs <файл или https://…> [--out data/catalog-export.json] [--allow-shrink] [--previous-count N]
 *   node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]
 *
 * Первая форма — для сборки: проверить и записать. Источник — файл или адрес
 * выгрузки на сервере. Защита от массовой потери сравнивает число букетов с
 * --previous-count (число из прошлой сборки), а если его нет — с файлом, который
 * сейчас лежит по адресу --out. Вторая форма проверяет файл на месте и ничего
 * не меняет — так делает prebuild.
 *
 * Код выхода: 0 — принято, 1 — выгрузка не принята, 2 — неверный вызов.
 */
import { existsSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { formatExport, validateExport } from './lib/catalog-export.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DEFAULT_FILE = path.join(ROOT, 'data', 'catalog-export.json');
const USAGE = [
  'Как вызывать:',
  '  node scripts/apply-catalog-export.mjs <файл или https://адрес> [--out data/catalog-export.json] [--allow-shrink] [--previous-count N]',
  '  node scripts/apply-catalog-export.mjs --check [data/catalog-export.json]',
  '',
  '  --out ФАЙЛ          куда записать принятую выгрузку',
  '  --allow-shrink      не останавливаться, если букетов стало заметно меньше',
  '  --previous-count N  сравнить число букетов с прошлой сборкой, а не с файлом',
  '  --check             только проверить файл на месте, ничего не записывать (без --out)',
].join('\n');

async function load(source, timeoutMs) {
  if (/^https?:\/\//.test(source)) {
    // Сервер может зависнуть — сборка не должна ждать его до своего таймаута.
    let res;
    let text;
    try {
      res = await fetch(source, { signal: AbortSignal.timeout(timeoutMs), headers: { 'User-Agent': 'pion-build' } });
      if (!res.ok) throw new Error(`сервер ответил ${res.status}`);
      text = await res.text();
    } catch (error) {
      if (res && !res.ok) throw error;
      throw new Error(networkReason(error, timeoutMs));
    }
    return parseJson(text);
  }
  return parseJson(readFileSync(source, 'utf8'));
}

/** Причина сбоя сети по-русски: fetch пишет «fetch failed» и «The operation was aborted due to timeout». */
function networkReason(error, timeoutMs) {
  if (error?.name === 'TimeoutError' || error?.cause?.name === 'TimeoutError') return `сервер не ответил за ${timeoutMs / 1000} с`;
  const code = error?.cause?.code ?? error?.code;
  return `нет связи с сервером${code ? ` (${code})` : ''}`;
}

function parseJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    throw new Error('прислано не JSON');
  }
}

/** Сколько букетов в файле, который лежит сейчас. Нет файла — сравнивать не с чем; битый — тоже, но об этом говорим. */
function previousFromFile(file) {
  if (!existsSync(file)) return { count: null, warning: null };
  try {
    const products = JSON.parse(readFileSync(file, 'utf8')).products;
    if (Array.isArray(products)) return { count: products.length, warning: null };
  } catch {
    // ниже — предупреждение
  }
  return { count: null, warning: `прошлый файл ${file} не читается — число букетов сравнить не с чем` };
}

export async function applyCatalogExport({ source, out = DEFAULT_FILE, allowShrink = false, check = false, previousCount = null, timeoutMs = 30_000 }) {
  let exp;
  try {
    exp = await load(source, timeoutMs);
  } catch (error) {
    return { ok: false, kind: 'unreachable', errors: [`Не удалось прочитать выгрузку ${source}: ${error.message}`] };
  }
  let previous = { count: null, warning: null };
  if (!check) previous = previousCount !== null ? { count: previousCount, warning: null } : previousFromFile(out);
  const errors = validateExport(exp, { previousCount: previous.count, allowShrink });
  if (errors.length) return { ok: false, kind: 'invalid', errors, warning: previous.warning };
  const text = formatExport(exp);
  if (!check) {
    try {
      writeFileSync(`${out}.part`, text);
      renameSync(`${out}.part`, out);
    } catch (error) {
      return { ok: false, kind: 'write', errors: [`Не удалось записать ${out}: ${error.message}`] };
    }
  }
  const written = JSON.parse(text);
  return {
    ok: true,
    errors: [],
    warning: previous.warning,
    products: exp.products.length,
    sections: exp.sections.length,
    version: written.version,
    changedAt: written.changedAt,
  };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const positional = [];
  let out = null;
  let check = false;
  let allowShrink = false;
  let previousCount = null;
  let bad = false;
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--check') check = true;
    else if (arg === '--allow-shrink') allowShrink = true;
    else if (arg === '--out' || arg === '--previous-count') {
      const value = args[i + 1];
      if (value === undefined || value.startsWith('-')) bad = true;
      else if (arg === '--out') out = path.resolve(value);
      else if (/^\d+$/.test(value)) previousCount = Number(value);
      else bad = true;
      i++;
    } else if (arg.startsWith('-')) bad = true;
    else positional.push(arg);
  }
  if (check && out !== null) bad = true;
  const source = positional[0] ?? (check ? DEFAULT_FILE : undefined);
  if (bad || !source || positional.length > 1) {
    console.error(USAGE);
    process.exit(2);
  }
  const r = await applyCatalogExport({ source, out: out ?? DEFAULT_FILE, allowShrink, check, previousCount });
  if (r.warning) console.error(`Внимание: ${r.warning}.`);
  if (!r.ok) {
    console.error('Выгрузка не принята:');
    for (const error of r.errors.slice(0, 30)) console.error(`- ${error}`);
    if (r.errors.length > 30) console.error(`…и ещё ${r.errors.length - 30}.`);
    process.exit(1);
  }
  const summary = `разделов ${r.sections}, букетов ${r.products}, версия ${r.version.slice(0, 12)}`;
  console.log(check ? `Выгрузка в порядке: ${summary}.` : `Записано в ${path.relative(ROOT, out ?? DEFAULT_FILE)}: ${summary}.`);
}
