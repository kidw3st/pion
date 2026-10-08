import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { decideBuild } from './catalog-source.mjs';
import { formatExport } from './lib/catalog-export.mjs';

const prev = { commit: 'c1', catalog: { version: 'v1', products: 487 } };

describe('decideBuild', () => {
  it('переключатель выключен: по коммиту — из снимка, по расписанию — ничего', () => {
    expect(decideBuild({ event: 'push', source: '', commit: 'c2', previous: prev, exportVersion: null }))
      .toMatchObject({ build: true, fail: false, catalog: 'snapshot' });
    expect(decideBuild({ event: 'schedule', source: '', commit: 'c1', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: false });
  });

  it('сервер не ответил: по расписанию — тихо, по коммиту — ошибка, из снимка не собираем', () => {
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: false });
    expect(decideBuild({ event: 'push', source: 'server', commit: 'c2', previous: prev, exportVersion: null }))
      .toMatchObject({ build: false, fail: true });
  });

  it('по расписанию без изменений — не собирать; изменился каталог или код — собирать из выгрузки', () => {
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: 'v1' }))
      .toMatchObject({ build: false, fail: false });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: prev, exportVersion: 'v2' }))
      .toMatchObject({ build: true, catalog: 'server' });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c2', previous: prev, exportVersion: 'v1' }))
      .toMatchObject({ build: true, catalog: 'server' });
    expect(decideBuild({ event: 'schedule', source: 'server', commit: 'c1', previous: null, exportVersion: 'v1' }))
      .toMatchObject({ build: true, catalog: 'server' });
  });

  it('по коммиту и вручную — всегда собирать из выгрузки', () => {
    for (const event of ['push', 'workflow_dispatch']) {
      expect(decideBuild({ event, source: 'server', commit: 'c1', previous: prev, exportVersion: 'v1' }))
        .toMatchObject({ build: true, catalog: 'server' });
    }
  });
});

describe('запуск из командной строки', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const SCRIPT = path.join(root, 'scripts/catalog-source.mjs');
  const FIXTURE = path.join(root, 'tests/php/fixtures/catalog-export-small.json');

  /** Окружение как в GitHub Actions; свои GITHUB_* машины, где идут проверки, не должны в него попасть. */
  function run(dir, catalogUrl, overrides = {}) {
    const env = {
      ...process.env,
      GITHUB_EVENT_NAME: 'schedule',
      CATALOG_SOURCE: 'server',
      GITHUB_SHA: 'c1',
      ALLOW_SHRINK: '',
      PREVIOUS_BUILD_INFO: path.join(dir, 'нет-такого', 'build-info.json'),
      CATALOG_OUT: path.join(dir, 'catalog-export.json'),
      CATALOG_URL: catalogUrl,
      GITHUB_OUTPUT: path.join(dir, 'github-output'),
      ...overrides,
    };
    // undefined в overrides — «переменной нет вовсе».
    for (const key of Object.keys(env)) if (env[key] === undefined) delete env[key];
    const r = spawnSync(process.execPath, [SCRIPT], { env, encoding: 'utf8' });
    const output = existsSync(env.GITHUB_OUTPUT) ? readFileSync(env.GITHUB_OUTPUT, 'utf8') : '';
    return { ...r, output: output.split('\n'), out: env.CATALOG_OUT };
  }

  it('выгрузка в порядке, прошлой сборки нет — код 0, собирать с сервера, файл записан', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const r = run(dir, FIXTURE);
    expect(r.status, r.stderr).toBe(0);
    expect(r.output).toContain('build=true');
    expect(r.output).toContain('catalog=server');
    expect(existsSync(r.out)).toBe(true);
    expect(JSON.parse(readFileSync(r.out, 'utf8')).products).toHaveLength(5);
  });

  it('выгрузка испорчена (цена 0 у букета в продаже) — код 1 и не собирать', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const bad = JSON.parse(readFileSync(FIXTURE, 'utf8'));
    const onSale = bad.products.find((p) => p.status === 'active');
    onSale.price = 0;
    writeFileSync(path.join(dir, 'bad.json'), JSON.stringify(bad));
    const r = run(dir, path.join(dir, 'bad.json'));
    expect(r.status).toBe(1);
    expect(r.output).toContain('build=false');
    expect(r.stderr).toContain('Выгрузка не принята');
    expect(existsSync(r.out)).toBe(false);
  });

  it('сервер не ответил: по расписанию — код 0, по коммиту — код 1; собирать — ни там, ни там', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const missing = path.join(dir, 'нет-выгрузки.json');
    const quiet = run(dir, missing);
    expect(quiet.status).toBe(0);
    expect(quiet.output).toContain('build=false');
    const loud = run(mkdtempSync(path.join(os.tmpdir(), 'pion-source-')), missing, { GITHUB_EVENT_NAME: 'push' });
    expect(loud.status).toBe(1);
    expect(loud.output).toContain('build=false');
  });

  it('по расписанию тот же коммит и та же версия, что в прошлой сборке, — не собирать', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const version = JSON.parse(formatExport(JSON.parse(readFileSync(FIXTURE, 'utf8')))).version;
    const info = path.join(dir, 'previous-build-info.json');
    writeFileSync(info, JSON.stringify({ commit: 'c1', catalog: { version, products: 5 } }));
    const same = run(dir, FIXTURE, { PREVIOUS_BUILD_INFO: info });
    expect(same.status).toBe(0);
    expect(same.output).toContain('build=false');
    const newCommit = run(mkdtempSync(path.join(os.tmpdir(), 'pion-source-')), FIXTURE, { PREVIOUS_BUILD_INFO: info, GITHUB_SHA: 'c2' });
    expect(newCommit.status).toBe(0);
    expect(newCommit.output).toContain('build=true');
    expect(newCommit.output).toContain('catalog=server');
  });

  it('пустой файл прошлой сборки (git show не удался) — как будто её нет', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const info = path.join(dir, 'previous-build-info.json');
    writeFileSync(info, '');
    const r = run(dir, FIXTURE, { PREVIOUS_BUILD_INFO: info });
    expect(r.status).toBe(0);
    expect(r.output).toContain('build=true');
  });

  it('в прошлой сборке нет числа букетов — предупреждение, что защиты от потери нет', () => {
    const warning = 'Внимание: защиты от потери нет — в прошлой сборке нет числа букетов.';
    const cases = {
      'файла нет': null,
      'не JSON': '{не json',
      'нет catalog': JSON.stringify({ commit: 'c0' }),
      'число не целое': JSON.stringify({ commit: 'c0', catalog: { version: 'v0', products: '487' } }),
    };
    for (const [label, text] of Object.entries(cases)) {
      const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
      const info = path.join(dir, 'previous-build-info.json');
      if (text !== null) writeFileSync(info, text);
      const r = run(dir, FIXTURE, { PREVIOUS_BUILD_INFO: info });
      expect(r.status, label).toBe(0);
      expect(r.stderr, label).toContain(warning);
      expect(r.output, label).toContain('build=true');
    }
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const info = path.join(dir, 'previous-build-info.json');
    writeFileSync(info, JSON.stringify({ commit: 'c0', catalog: { version: 'v0', products: 5 } }));
    expect(run(dir, FIXTURE, { PREVIOUS_BUILD_INFO: info }).stderr).not.toContain('защиты от потери нет');
    // Переключатель выключен — выгрузку не берём, и предупреждать не о чем.
    expect(run(dir, FIXTURE, { CATALOG_SOURCE: '', GITHUB_EVENT_NAME: 'push' }).stderr).not.toContain('защиты от потери нет');
  });

  it('букетов намного меньше, чем в прошлой сборке: стоп; с ALLOW_SHRINK=true — собирать', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const info = path.join(dir, 'previous-build-info.json');
    writeFileSync(info, JSON.stringify({ commit: 'c0', catalog: { version: 'v0', products: 100 } }));
    const stopped = run(dir, FIXTURE, { PREVIOUS_BUILD_INFO: info, GITHUB_EVENT_NAME: 'workflow_dispatch' });
    expect(stopped.status).toBe(1);
    expect(stopped.output).toContain('build=false');
    expect(stopped.stderr).toContain('allow_shrink');
    const dir2 = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const allowed = run(dir2, FIXTURE, { PREVIOUS_BUILD_INFO: info, GITHUB_EVENT_NAME: 'workflow_dispatch', ALLOW_SHRINK: 'true' });
    expect(allowed.status, allowed.stderr).toBe(0);
    expect(allowed.output).toContain('build=true');
    expect(allowed.output).toContain('catalog=server');
  });

  it('CATALOG_OUT не задан или пуст — код 2, в data/ ничего не пишем', () => {
    const snapshot = path.join(root, 'data', 'catalog-export.json');
    const before = readFileSync(snapshot);
    for (const value of [undefined, '']) {
      const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
      // Адрес выгрузки — несуществующий файл: даже если бы скрипт взял запасной путь,
      // он ничего не записал бы, а код был бы 0 (сервер не ответил, расписание).
      const r = run(dir, path.join(dir, 'нет-выгрузки.json'), { CATALOG_OUT: value });
      expect(r.status).toBe(2);
      expect(r.stderr).toContain('CATALOG_OUT не задан — выгрузку с сервера некуда положить.');
      expect(r.output.join('\n')).not.toContain('build=');
    }
    expect(readFileSync(snapshot).equals(before)).toBe(true);
    // Переключатель выключен — CATALOG_OUT не нужен.
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    expect(run(dir, FIXTURE, { CATALOG_SOURCE: '', GITHUB_EVENT_NAME: 'push', CATALOG_OUT: undefined }).status).toBe(0);
  });

  it('«Server» — тоже включено: регистр не важен, как в concurrency и gate', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const r = run(dir, FIXTURE, { CATALOG_SOURCE: 'Server' });
    expect(r.status, r.stderr).toBe(0);
    expect(r.output).toContain('build=true');
    expect(r.output).toContain('catalog=server');
  });

  it('переключатель выключен — сервер не спрашиваем; по коммиту из снимка, по расписанию ничего', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'pion-source-'));
    const missing = path.join(dir, 'нет-выгрузки.json');
    const push = run(dir, missing, { CATALOG_SOURCE: '', GITHUB_EVENT_NAME: 'push' });
    expect(push.status).toBe(0);
    expect(push.output).toContain('build=true');
    expect(push.output).toContain('catalog=snapshot');
    expect(existsSync(push.out)).toBe(false);
    const schedule = run(mkdtempSync(path.join(os.tmpdir(), 'pion-source-')), missing, { CATALOG_SOURCE: '' });
    expect(schedule.status).toBe(0);
    expect(schedule.output).toContain('build=false');
  });
});
