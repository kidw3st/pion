import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, utimesSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
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
