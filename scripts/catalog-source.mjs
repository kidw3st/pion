/**
 * Решение сборки: собирать ли сайт и из какого каталога.
 *
 * Пока переменная репозитория CATALOG_SOURCE не равна «server», сайт
 * собирается из закоммиченного снимка data/catalog-export.json — как до
 * этапа 3, — а запуск по расписанию ничего не делает. С «server» каталог
 * берётся с /pay/catalog-export.php: по расписанию — только когда изменился
 * он или код. Если источник — сервер, а выгрузки нет, из снимка не собираем
 * никогда: снимок устарел, и выкладка вернула бы на сайт старый каталог.
 *
 * Код выхода: 0 — решение принято (в GITHUB_OUTPUT build и catalog);
 * 1 — ошибка: сервер не отдал выгрузку по коммиту или выгрузка не прошла
 * проверку; 2 — неверный вызов (с «server» не задан CATALOG_OUT).
 *
 * Запускается из .github/workflows/deploy.yml; см. docs/deploy.md.
 */
import { appendFileSync, existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyCatalogExport } from './apply-catalog-export.mjs';

export function decideBuild({ event, source, commit, previous, exportVersion }) {
  if (source !== 'server') {
    return event === 'schedule'
      ? { build: false, fail: false, catalog: 'snapshot', reason: 'источник — снимок, по расписанию собирать нечего' }
      : { build: true, fail: false, catalog: 'snapshot', reason: 'источник — снимок в репозитории' };
  }
  if (exportVersion === null) {
    return event === 'schedule'
      ? { build: false, fail: false, catalog: 'server', reason: 'сервер не ответил — попробуем через 15 минут' }
      : { build: false, fail: true, catalog: 'server', reason: 'сервер не отдал выгрузку — из устаревшего снимка не собираем' };
  }
  if (event === 'schedule' && previous && previous.commit === commit && previous.catalog?.version === exportVersion) {
    return { build: false, fail: false, catalog: 'server', reason: 'ни код, ни каталог не изменились' };
  }
  return { build: true, fail: false, catalog: 'server', reason: 'каталог с сервера' };
}

function readPrevious(file) {
  if (!file || !existsSync(file)) return null;
  try {
    return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const env = process.env;
  const event = env.GITHUB_EVENT_NAME ?? 'push';
  // Без учёта регистра — как concurrency и gate в deploy.yml (выражения GitHub так сравнивают строки).
  const source = (env.CATALOG_SOURCE ?? '').toLowerCase();
  const commit = env.GITHUB_SHA ?? '';
  const previous = readPrevious(env.PREVIOUS_BUILD_INFO);
  let exportVersion = null;
  let fail = false;
  if (source === 'server') {
    // Без CATALOG_OUT applyCatalogExport записал бы выгрузку поверх закоммиченного снимка.
    if (!env.CATALOG_OUT) {
      console.error('CATALOG_OUT не задан — выгрузку с сервера некуда положить.');
      process.exit(2);
    }
    const previousCount = Number.isInteger(previous?.catalog?.products) ? previous.catalog.products : null;
    if (previousCount === null) console.error('Внимание: защиты от потери нет — в прошлой сборке нет числа букетов.');
    const r = await applyCatalogExport({
      source: env.CATALOG_URL || 'https://pionperm.ru/pay/catalog-export.php',
      out: env.CATALOG_OUT,
      allowShrink: env.ALLOW_SHRINK === 'true',
      previousCount,
    });
    if (r.warning) console.error(`Внимание: ${r.warning}.`);
    if (r.ok) {
      exportVersion = r.version;
      console.log(`Выгрузка с сервера: разделов ${r.sections}, букетов ${r.products}, версия ${r.version.slice(0, 12)}.`);
    } else if (r.kind === 'unreachable') {
      console.log(r.errors[0]);
    } else {
      console.error('Выгрузка не принята:');
      for (const error of r.errors.slice(0, 30)) console.error(`- ${error}`);
      fail = true;
    }
  }
  const decision = fail
    ? { build: false, fail: true, catalog: 'server', reason: 'выгрузка не прошла проверку' }
    : decideBuild({ event, source, commit, previous, exportVersion });
  console.log(`Решение: ${decision.build ? 'собирать' : 'не собирать'} — ${decision.reason}.`);
  if (env.GITHUB_OUTPUT) {
    appendFileSync(env.GITHUB_OUTPUT, `build=${decision.build}\ncatalog=${decision.catalog}\n`);
  }
  process.exit(decision.fail ? 1 : 0);
}
