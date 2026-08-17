// Снимает идентификаторы магазина со всех категорий pionperm.ru.
//
// Зачем: сайт закрыт антибот-защитой Variti, которая пускает ваш браузер и не
// пускает меня. Но всё остальное — API магазина Tilda и сервер с фотографиями —
// мне доступно. Не хватает только двух чисел на категорию, которые лежат в коде
// страницы. Ваш браузер их видит.
//
// Как запустить:
//   1. Откройте https://pionperm.ru/ в Chrome и дождитесь загрузки.
//   2. F12 -> вкладка Console.
//   3. Вставьте весь этот файл и нажмите Enter.
//   4. Результат скопируется в буфер сам. Пришлите его мне.
//
// Логин в Tilda не нужен. Скрипт только читает страницы вашего же сайта.

(async () => {
  const slugs = [
    'bukety', 'korziny', 'korobki', 'wedding', 'balloons',
    'chocolate', 'luchshee', 'flame', 'pions', 'roses', 'mixflower',
  ];

  // storepart и recid лежат рядом в одном блоке настроек виджета. Ищем recid
  // возле storepart, а не по всей странице — на странице много других recid.
  const idsFrom = (html) => {
    const at = html.indexOf('storepart');
    if (at === -1) return { storepart: null, recid: null };
    const around = html.slice(Math.max(0, at - 800), at + 800);
    return {
      storepart: (around.match(/storepart:'(\d+)'/) || [])[1] || null,
      recid: (around.match(/recid:'(\d+)'/) || [])[1] || null,
    };
  };

  const out = {};
  for (const slug of slugs) {
    try {
      const res = await fetch('/' + slug, { credentials: 'include' });
      const html = await res.text();
      out[slug] = idsFrom(html);
      console.log(slug, out[slug]);
    } catch (err) {
      out[slug] = { error: String(err) };
      console.log(slug, 'ошибка:', err);
    }
  }

  const json = JSON.stringify(out, null, 2);
  console.log('\n======== скопировано в буфер, пришлите это ========\n' + json);
  try {
    copy(json);
  } catch {
    console.log('(скопируйте текст выше вручную)');
  }
})();
