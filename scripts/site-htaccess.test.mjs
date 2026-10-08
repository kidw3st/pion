import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const site = readFileSync(path.join(root, 'public/.htaccess'), 'utf8');
const pay = readFileSync(path.join(root, 'server-pay/.htaccess'), 'utf8');

describe('правило переадресаций каталога в .htaccess', () => {
  const rule = 'RewriteRule ^([a-z0-9_-]+)/([a-z0-9_-]+)/?$ /pay/catalog-redirect.php [L]';

  it('есть и срабатывает, только когда у адреса нет своей страницы', () => {
    const at = site.indexOf(rule);
    expect(at).toBeGreaterThan(0);
    expect(site.slice(0, at)).toMatch(/RewriteCond %\{DOCUMENT_ROOT\}\/\$1\/\$2\/index\.html !-f\s*$/);
  });

  it('не трогает служебные папки', () => {
    for (const dir of ['blog', 'pay', 'api', 'images', 'md', '_next', 'feed', 'fonts', '\\.well-known']) {
      expect(site).toContain(dir);
    }
    expect(site).toContain('RewriteCond %{REQUEST_URI} !^/(blog|pay|api|images|md|_next|feed|fonts|\\.well-known)/ [NC]');
  });

  it('стоит после правил для адресов Tilda и защиты от парсеров', () => {
    expect(site.indexOf(rule)).toBeGreaterThan(site.indexOf('tilda-redirect.php'));
    expect(site.indexOf(rule)).toBeGreaterThan(site.lastIndexOf('RewriteRule .* - [F,L]'));
  });

  it('библиотека переадресаций закрыта снаружи', () => {
    expect(pay).toContain('<Files "catalog-redirect-lib.php">');
  });
});
