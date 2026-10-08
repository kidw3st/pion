import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { applyCatalogExport } from './apply-catalog-export.mjs';
import { formatExport } from './lib/catalog-export.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(root, 'scripts/apply-catalog-export.mjs');
const FIXTURE = path.join(root, 'tests/php/fixtures/catalog-export-small.json');
const tmp = () => mkdtempSync(path.join(os.tmpdir(), 'pion-apply-'));

describe('applyCatalogExport', () => {
  it('принимает выгрузку и пишет её в каноническом виде', async () => {
    const out = path.join(tmp(), 'catalog-export.json');
    const r = await applyCatalogExport({ source: FIXTURE, out });
    expect(r).toMatchObject({ ok: true, errors: [], products: 5, sections: 3 });
    expect(readFileSync(out, 'utf8')).toBe(formatExport(JSON.parse(readFileSync(FIXTURE, 'utf8'))));
  });

  it('плохая выгрузка — не принята, файл не тронут', async () => {
    const dir = tmp();
    const bad = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    bad.products[1].uid = bad.products[0].uid;
    writeFileSync(path.join(dir, 'bad.json'), JSON.stringify(bad));
    const out = path.join(dir, 'catalog-export.json');
    const r = await applyCatalogExport({ source: path.join(dir, 'bad.json'), out });
    expect(r.ok).toBe(false);
    expect(r.errors.join('\n')).toContain('встречается дважды');
    expect(existsSync(out)).toBe(false);
  });

  it('букетов на 30% меньше, чем в лежащем файле, — стоп; allowShrink — можно', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    writeFileSync(out, JSON.stringify({ products: Array.from({ length: 10 }, (_, i) => ({ uid: String(i) })) }));
    const stopped = await applyCatalogExport({ source: FIXTURE, out });
    expect(stopped.ok).toBe(false);
    expect(stopped.errors.join('\n')).toContain('allow_shrink');
    expect((await applyCatalogExport({ source: FIXTURE, out, allowShrink: true })).ok).toBe(true);
  });

  it('--check проверяет на месте и ничего не пишет', async () => {
    const out = path.join(tmp(), 'never.json');
    const r = await applyCatalogExport({ source: FIXTURE, out, check: true });
    expect(r.ok).toBe(true);
    expect(existsSync(out)).toBe(false);
  });

  it('файла нет — понятная ошибка', async () => {
    const r = await applyCatalogExport({ source: path.join(tmp(), 'missing.json'), out: path.join(tmp(), 'x.json') });
    expect(r.ok).toBe(false);
    expect(r.errors[0]).toMatch(/^Не удалось прочитать выгрузку/);
  });
});

describe('запуск из командной строки', () => {
  const run = (...args) => spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8' });

  it('--check на хорошем файле — код 0', () => {
    const r = run('--check', FIXTURE);
    expect(r.status).toBe(0);
    expect(r.stdout).toContain('в порядке');
  });

  it('плохой файл — код 1 и список ошибок', () => {
    const dir = tmp();
    const bad = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    bad.products[0].price = 0;
    writeFileSync(path.join(dir, 'bad.json'), JSON.stringify(bad));
    const r = run('--check', path.join(dir, 'bad.json'));
    expect(r.status).toBe(1);
    expect(r.stderr).toContain('Выгрузка не принята');
  });

  it('без аргументов — код 2 и подсказка', () => {
    const r = run();
    expect(r.status).toBe(2);
    expect(r.stderr).toContain('Как вызывать');
  });
});

describe('готовность к выгрузке с сервера', () => {
  it('--previous-count важнее файла по --out', async () => {
    const out = path.join(tmp(), 'catalog-export.json');
    const stopped = await applyCatalogExport({ source: FIXTURE, out, previousCount: 10 });
    expect(stopped).toMatchObject({ ok: false, kind: 'invalid' });
    expect(stopped.errors.join('\n')).toContain('allow_shrink');
    expect((await applyCatalogExport({ source: FIXTURE, out, previousCount: 6 })).ok).toBe(true);
  });

  it('не скачалось — вид ошибки unreachable', async () => {
    const r = await applyCatalogExport({ source: path.join(tmp(), 'missing.json'), out: path.join(tmp(), 'x.json') });
    expect(r).toMatchObject({ ok: false, kind: 'unreachable' });
  });

  it('не JSON — понятная ошибка', async () => {
    const dir = tmp();
    writeFileSync(path.join(dir, 'page.json'), '<!doctype html><title>503</title>');
    const r = await applyCatalogExport({ source: path.join(dir, 'page.json'), out: path.join(dir, 'x.json') });
    expect(r.kind).toBe('unreachable');
    expect(r.errors[0]).toContain('не JSON');
  });

  it('запись атомарная: временный файл не остаётся', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    expect((await applyCatalogExport({ source: FIXTURE, out })).ok).toBe(true);
    expect(existsSync(`${out}.part`)).toBe(false);
  });

  it('не записать — вид ошибки write', async () => {
    const r = await applyCatalogExport({ source: FIXTURE, out: path.join(tmp(), 'нет-папки', 'x.json') });
    expect(r).toMatchObject({ ok: false, kind: 'write' });
    expect(r.errors[0]).toMatch(/^Не удалось записать/);
  });

  it('битый прошлый файл — предупреждение, а не молчание', async () => {
    const dir = tmp();
    const out = path.join(dir, 'catalog-export.json');
    writeFileSync(out, '<<<<<<< HEAD');
    const r = spawnSync(process.execPath, [SCRIPT, FIXTURE, '--out', out], { encoding: 'utf8' });
    expect(r.status).toBe(0);
    expect(r.stderr).toContain('не читается');
  });

  const bad = [
    ['--out без значения', ['--check', '--out']],
    ['--out, за которым флаг', [FIXTURE, '--out', '--allow-shrink']],
    ['одиночный дефис', ['-h']],
    ['неизвестный флаг', [FIXTURE, '--force']],
    ['--check вместе с --out', ['--check', FIXTURE, '--out', 'x.json']],
    ['--previous-count не число', [FIXTURE, '--previous-count', 'много']],
  ];
  for (const [name, args] of bad) {
    it(`неверный вызов: ${name} — код 2`, () => {
      const r = spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8', cwd: tmp() });
      expect(r.status).toBe(2);
      expect(r.stderr).toContain('Как вызывать');
    });
  }
});
