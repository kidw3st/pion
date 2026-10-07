/**
 * Slug товара из названия: транслитерация, всё прочее — дефис, не длиннее
 * 60 знаков. По этой функции получены адреса всех нынешних букетов, и так же
 * их строит админка на сервере (server-pay/catalog/slug.php). Что обе дают
 * одно и то же, проверяют tests/php/fixtures/slugs.json и slugify.test.mjs.
 *
 * @param {string} title
 * @returns {string}
 */
export function slugify(title) {
  const map = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
    й: 'i', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
    у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y',
    ь: '', э: 'e', ю: 'yu', я: 'ya',
  };
  return title
    .toLowerCase()
    .split('')
    .map((ch) => map[ch] ?? ch)
    .join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60) || 'tovar';
}
