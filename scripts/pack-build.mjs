/**
 * Упаковывает готовую сборку для сервера: архив сайта, архив /pay/ и
 * build-info.json с sha256 обоих. По нему deploy.php на сервере проверяет,
 * что скачал ровно то, что собрал GitHub. Если передан catalogFile (выгрузка
 * каталога, из которой собран сайт), в build-info.json есть и catalog: версия,
 * время изменения и число товаров — deploy.php переносит их в state.json.
 *
 *   npm run build && npm run pack     # кладёт всё в build/
 *
 * Фото каталога в архив сайта не входят: ими владеет сервер (их загружает
 * админка), и deploy.php не выложит сборку, которая лезет в images/catalog/.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Без этих файлов deploy.php сборку не примет — проверяем уже здесь. */
export const REQUIRED = ['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt'];

// На Windows — встроенный bsdtar по полному пути: tar из Git Bash принял бы
// «C:» в пути к архиву за имя удалённого сервера.
const TAR =
  process.platform === 'win32'
    ? path.join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', 'tar.exe')
    : 'tar';

/** GNU tar (Linux, GitHub Actions) умеет писать одинаковые архивы, bsdtar (Windows) — нет. */
export const GNU_TAR = execFileSync(TAR, ['--version'], { encoding: 'utf8' }).includes('GNU tar');

/** Файлы архива: пути без «./», без папок. */
export function listArchive(file) {
  return execFileSync(TAR, ['-tzf', file], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
    .split(/\r?\n/)
    .map((line) => line.replace(/^\.\//, ''))
    .filter((line) => line !== '' && line !== '.' && !line.endsWith('/'));
}

function describeArchive(file) {
  return {
    file: path.basename(file),
    bytes: statSync(file).size,
    sha256: createHash('sha256').update(readFileSync(file)).digest('hex'),
  };
}

/**
 * tar.gz из папки dir без пути exclude.
 *
 * sha256 архива должен меняться только вместе с содержимым: по нему deploy.php
 * решает, перекладывать ли /pay/. Обычный tar пишет в архив время изменения
 * файлов, а git checkout ставит им время клонирования, — хэш был бы новым при
 * каждой сборке, и «/pay/ без изменений» не наступало бы никогда. Поэтому у
 * GNU tar порядок файлов, время, владелец и заголовок gzip зафиксированы.
 */
function createArchive(file, dir, exclude) {
  const create = GNU_TAR
    ? ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner', '-I', 'gzip -n', '-cf']
    : ['-czf'];
  execFileSync(TAR, [...create, file, '-C', dir, '--exclude', exclude, '.']);
}

/**
 * Что build-info.json говорит о каталоге, из которого собран сайт: версия (по ней
 * админка показывает «на сайте» / «ждёт выкладки»), время последнего изменения и число товаров.
 *
 * Админка понимает время только в виде catalog_iso (2026-10-05T14:32:10+05:00): другой вид
 * спрятал бы отметки «на сайте» — лучше остановить сборку здесь, чем выложить такую.
 */
function catalogInfo(catalogFile) {
  const exp = JSON.parse(readFileSync(catalogFile, 'utf8'));
  if (!/^[0-9a-f]{64}$/.test(exp.version ?? '')) throw new Error('в выгрузке каталога нет версии');
  if (exp.changedAt !== null && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(exp.changedAt ?? '')) {
    throw new Error(`changedAt выгрузки не в виде catalog_iso: ${exp.changedAt}`);
  }
  if (!Array.isArray(exp.products)) throw new Error('в выгрузке каталога нет списка товаров');
  return { version: exp.version, changedAt: exp.changedAt, products: exp.products.length };
}

export function pack({ outDir, payDir, dest, commit, now = new Date(), catalogFile = null }) {
  // Читаем выгрузку до того, как трогать build/: негодная выгрузка не должна оставлять недособранную папку.
  const catalog = catalogFile ? catalogInfo(catalogFile) : null;
  rmSync(dest, { recursive: true, force: true });
  mkdirSync(dest, { recursive: true });
  const site = path.join(dest, 'pion-site.tar.gz');
  const pay = path.join(dest, 'pion-pay.tar.gz');

  createArchive(site, outDir, './images/catalog');
  createArchive(pay, payDir, './config.php');

  const siteFiles = listArchive(site);
  const leaked = siteFiles.filter((f) => f.startsWith('images/catalog/'));
  if (leaked.length > 0) {
    throw new Error(`в архив сайта попали фото каталога: ${leaked.slice(0, 3).join(', ')}`);
  }
  const missing = REQUIRED.filter((f) => !siteFiles.includes(f));
  if (missing.length > 0) {
    throw new Error(`в сборке нет ${missing.join(', ')} — сначала npm run build`);
  }
  if (listArchive(pay).includes('config.php')) {
    throw new Error('в платёжный архив попал config.php');
  }

  const info = { commit, builtAt: now.toISOString(), site: describeArchive(site), pay: describeArchive(pay) };
  if (catalog) info.catalog = catalog;
  writeFileSync(path.join(dest, 'build-info.json'), JSON.stringify(info, null, 2) + '\n');
  return info;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const commit =
    process.env.GITHUB_SHA || execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const info = pack({
    outDir: path.join(root, 'out'),
    payDir: path.join(root, 'server-pay'),
    dest: path.join(root, 'build'),
    commit,
    catalogFile: path.join(root, 'data', 'catalog-export.json'),
  });
  console.log(
    `[pack] build/: сайт ${(info.site.bytes / 1048576).toFixed(1)} МБ, /pay/ ${Math.round(info.pay.bytes / 1024)} КБ, коммит ${commit.slice(0, 7)}, каталог ${info.catalog.version.slice(0, 12)}`,
  );
}
