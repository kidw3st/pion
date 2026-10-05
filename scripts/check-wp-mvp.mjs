/**
 * Проверяет, что приложение MVP может работать с блогом через REST API:
 * WordPress принимает пароль приложения, а у пользователя есть нужные права.
 *
 * Только читает: запрашивает данные своего же пользователя, ничего не
 * создаёт. Пароль берёт из .env.mvp.local и нигде его не печатает.
 *
 *   node scripts/check-wp-mvp.mjs
 */
import { readFileSync } from 'node:fs';

const env = Object.fromEntries(
  readFileSync(new URL('../.env.mvp.local', import.meta.url), 'utf8')
    .split(/\r?\n/)
    .filter((line) => /^[A-Z_]+=/.test(line))
    .map((line) => {
      const i = line.indexOf('=');
      return [line.slice(0, i), line.slice(i + 1).trim().replace(/^"(.*)"$/, '$1')];
    }),
);

const { WP_API_URL, WP_USERNAME, WP_APP_PASSWORD } = env;
if (!WP_APP_PASSWORD) {
  console.error('В .env.mvp.local пустой WP_APP_PASSWORD — вставьте пароль приложения.');
  process.exit(1);
}

const auth = Buffer.from(`${WP_USERNAME}:${WP_APP_PASSWORD.replace(/\s+/g, '')}`).toString('base64');
const res = await fetch(new URL('wp/v2/users/me?context=edit', WP_API_URL.replace(/\/?$/, '/')), {
  headers: { Authorization: `Basic ${auth}`, 'User-Agent': 'PionMVP-check/1.0' },
});
const body = await res.json().catch(() => ({}));

if (!res.ok) {
  const hints = {
    rest_not_logged_in: 'заголовок Authorization не дошёл до WordPress — дело в настройках сервера.',
    invalid_username: 'WordPress не знает такого логина — проверьте WP_USERNAME.',
    incorrect_password: 'пароль не подошёл — вставьте пароль приложения заново.',
  };
  console.error(`Не вошли: HTTP ${res.status} ${body.code ?? ''} — ${hints[body.code] ?? body.message ?? ''}`);
  process.exit(1);
}

const need = ['edit_posts', 'publish_posts', 'edit_published_posts', 'upload_files'];
const missing = need.filter((cap) => !body.capabilities?.[cap]);
console.log(`Вошли как ${body.username} (id ${body.id}), роль: ${(body.roles ?? []).join(', ')}`);
console.log(
  missing.length
    ? `Не хватает прав: ${missing.join(', ')}`
    : 'Права на месте: создавать статьи, публиковать сразу и по расписанию, править свои материалы, загружать картинки.',
);
console.log(body.capabilities?.manage_options ? 'ВНИМАНИЕ: у пользователя права администратора.' : 'Прав администратора нет.');
