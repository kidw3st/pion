/**
 * Упаковывает готовую сборку для сервера: архив сайта, архив /pay/ и
 * build-info.json с sha256 обоих. По нему deploy.php на сервере проверяет,
 * что скачал ровно то, что собрал GitHub.
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

export function pack({ outDir, payDir, dest, commit, now = new Date() }) {
  rmSync(dest, { recursive: true, force: true });
  mkdirSync(dest, { recursive: true });
  const site = path.join(dest, 'pion-site.tar.gz');
  const pay = path.join(dest, 'pion-pay.tar.gz');

  execFileSync(TAR, ['-czf', site, '-C', outDir, '--exclude', './images/catalog', '.']);
  execFileSync(TAR, ['-czf', pay, '-C', payDir, '--exclude', './config.php', '.']);

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
  });
  console.log(
    `[pack] build/: сайт ${(info.site.bytes / 1048576).toFixed(1)} МБ, /pay/ ${Math.round(info.pay.bytes / 1024)} КБ, коммит ${commit.slice(0, 7)}`,
  );
}
