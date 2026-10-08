import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, utimesSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { GNU_TAR, listArchive, pack } from './pack-build.mjs';

/** Временная папка с файлами: путь => содержимое. */
function tree(files) {
  const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-pack-'));
  for (const [rel, body] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(dir, rel)), { recursive: true });
    writeFileSync(path.join(dir, rel), body);
  }
  return dir;
}

/** Ставит время изменения всем файлам и папкам внутри dir и ей самой. */
function touchAll(dir, when) {
  for (const rel of readdirSync(dir, { recursive: true })) {
    utimesSync(path.join(dir, rel), when, when);
  }
  utimesSync(dir, when, when);
}

const SITE = {
  'index.html': 'home',
  '404.html': '404',
  '.htaccess': 'rules',
  'sitemap.xml': '<urlset/>',
  'robots.txt': 'User-agent: *',
  'images/site/logo.webp': 'logo',
  'images/catalog/bukety/a.webp': 'photo',
  'bukety/index.html': 'section',
};

describe('pack', () => {
  it('кладёт сайт без фото каталога, /pay/ без config.php и sha256 обоих', () => {
    const dest = path.join(tree({}), 'build');
    const info = pack({
      outDir: tree(SITE),
      payDir: tree({ 'init.php': '<?php', 'config.php': '<?php // secret', '.htaccess': 'deny' }),
      dest,
      commit: 'abc1234def',
      now: new Date('2026-10-05T10:00:00Z'),
    });

    const site = listArchive(path.join(dest, 'pion-site.tar.gz'));
    expect(site).toContain('bukety/index.html');
    expect(site).toContain('images/site/logo.webp');
    expect(site.filter((f) => f.startsWith('images/catalog'))).toEqual([]);
    expect(listArchive(path.join(dest, 'pion-pay.tar.gz')).sort()).toEqual(['.htaccess', 'init.php']);

    expect(JSON.parse(readFileSync(path.join(dest, 'build-info.json'), 'utf8'))).toEqual(info);
    expect(info.commit).toBe('abc1234def');
    expect(info.builtAt).toBe('2026-10-05T10:00:00.000Z');
    const sha = createHash('sha256').update(readFileSync(path.join(dest, 'pion-site.tar.gz'))).digest('hex');
    expect(info.site.sha256).toBe(sha);
    expect(info.site.file).toBe('pion-site.tar.gz');
    const paySha = createHash('sha256').update(readFileSync(path.join(dest, 'pion-pay.tar.gz'))).digest('hex');
    expect(info.pay.sha256).toBe(paySha);
    expect(info.pay.file).toBe('pion-pay.tar.gz');
    // Без catalogFile каталог в build-info не пишется — как до 3Б.
    expect(info).not.toHaveProperty('catalog');
    expect(JSON.parse(readFileSync(path.join(dest, 'build-info.json'), 'utf8'))).not.toHaveProperty('catalog');
  });

  describe('каталог в build-info.json', () => {
    const FIXTURE = fileURLToPath(new URL('../tests/php/fixtures/catalog-export-small.json', import.meta.url));
    const VERSION = '4e8ad6165702c28cc49e0e9e14eb37f7de4771608bd1e349162eba59946dfafc';

    /** Копия образца выгрузки с подставленными полями. */
    function exportWith(changes) {
      const file = path.join(tree({}), 'catalog-export.json');
      writeFileSync(file, JSON.stringify({ ...JSON.parse(readFileSync(FIXTURE, 'utf8')), ...changes }));
      return file;
    }

    function packWith(catalogFile) {
      const dest = path.join(tree({}), 'build');
      const info = pack({
        outDir: tree(SITE),
        payDir: tree({ 'init.php': '<?php' }),
        dest,
        commit: 'abc1234def',
        catalogFile,
      });
      return { info, dest };
    }

    it('пишет версию, время и число товаров выгрузки', () => {
      const { info, dest } = packWith(FIXTURE);
      expect(info.catalog).toEqual({ version: VERSION, changedAt: '2026-10-05T14:32:10+05:00', products: 5 });
      expect(JSON.parse(readFileSync(path.join(dest, 'build-info.json'), 'utf8'))).toEqual(info);
    });

    it('принимает выгрузку, в которой каталог ещё не менялся (changedAt: null)', () => {
      const { info } = packWith(exportWith({ changedAt: null }));
      expect(info.catalog.changedAt).toBeNull();
    });

    it('останавливает сборку, если changedAt не в виде catalog_iso', () => {
      const good = '2026-10-05T14:32:10+05:00';
      // Массив: RegExp.test превращает его в строку, и без проверки типа ['2026-…+05:00'] прошёл бы.
      const bads = ['2026-10-05T14:32:10.000Z', '2026-10-05T14:32:10Z', '2026-10-05 14:32:10+05:00', '', 1760000000, undefined, [good]];
      for (const bad of bads) {
        const file = exportWith({ changedAt: bad });
        expect(() => packWith(file), `changedAt = ${JSON.stringify(bad)}`).toThrow(/changedAt/);
      }
    });

    it('останавливает сборку, если версия выгрузки — не sha256 строкой', () => {
      for (const bad of ['abc', '', VERSION.toUpperCase(), VERSION.slice(1), [VERSION], 7, null, undefined]) {
        expect(() => packWith(exportWith({ version: bad })), `version = ${JSON.stringify(bad)}`).toThrow(/версия выгрузки каталога — не sha256/);
      }
    });

    it('останавливает сборку, если в выгрузке нет списка товаров', () => {
      for (const bad of [undefined, null, 'abc', { length: 5 }]) {
        expect(() => packWith(exportWith({ products: bad })), `products = ${JSON.stringify(bad)}`).toThrow(/товаров/);
      }
    });
  });

  // По sha256 /pay/ deploy.php решает, перекладывать ли платёжную часть. Обычный
  // tar пишет в архив время файлов, а git checkout ставит им время клонирования, —
  // хэш был бы новым при каждой сборке. Нормализует только GNU tar (CI), не bsdtar.
  it.skipIf(!GNU_TAR)('архивы те же, если изменилось только время файлов, и другие, если содержимое', () => {
    const outDir = tree(SITE);
    const payDir = tree({ 'init.php': '<?php', 'config.php': '<?php // secret', '.htaccess': 'deny' });
    const run = () => pack({ outDir, payDir, dest: path.join(tree({}), 'build'), commit: 'x' });

    const first = run();
    touchAll(outDir, new Date('2031-02-03T04:05:06Z'));
    touchAll(payDir, new Date('2031-02-03T04:05:06Z'));
    const later = run();
    expect(later.site.sha256).toBe(first.site.sha256);
    expect(later.pay.sha256).toBe(first.pay.sha256);

    writeFileSync(path.join(outDir, 'index.html'), 'home 2');
    writeFileSync(path.join(payDir, 'init.php'), '<?php echo 2;');
    const changed = run();
    expect(changed.site.sha256).not.toBe(first.site.sha256);
    expect(changed.pay.sha256).not.toBe(first.pay.sha256);
  });

  it('не пакует сборку без обязательных файлов', () => {
    const { 'robots.txt': _robots, ...site } = SITE;
    expect(() =>
      pack({
        outDir: tree(site),
        payDir: tree({ 'init.php': '<?php' }),
        dest: path.join(tree({}), 'build'),
        commit: 'x',
      }),
    ).toThrow(/robots\.txt/);
  });
});
