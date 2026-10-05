import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { listArchive, pack } from './pack-build.mjs';

/** Временная папка с файлами: путь => содержимое. */
function tree(files) {
  const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-pack-'));
  for (const [rel, body] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(dir, rel)), { recursive: true });
    writeFileSync(path.join(dir, rel), body);
  }
  return dir;
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
