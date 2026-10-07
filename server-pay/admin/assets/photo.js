/*
 * Фото в карточках букетов и разделов. Браузер сам уменьшает снимок до
 * 2000 px по длинной стороне и отправляет JPEG — по одному снимку за запрос,
 * так он быстро уходит и с мобильного интернета. Тяжелее 1,9 МБ не отправляет:
 * сначала снижает качество, потом уменьшает ещё. Остальное делает сервер
 * (pay/admin/photo.php): пересохраняет в WebP и возвращает путь к файлу,
 * а путь попадает в форму и сохраняется вместе с ней. Порядок меняют
 * стрелками; первое фото — главное.
 *
 * Пока снимки загружаются, выбор файлов и кнопки сохранения формы закрыты:
 * иначе можно сохранить карточку без ещё не дошедших фото или выйти за предел.
 */
(function () {
  'use strict';
  var MAX_SIDE = 2000;
  // Предел загрузки в PHP по умолчанию — 2 МБ: снимок тяжелее может не дойти. Берём с запасом.
  var MAX_BYTES = 1.9 * 1024 * 1024;
  // Качество JPEG: сначала лучшее, при тяжёлом файле — хуже. Не помогло — уменьшаем сторону и повторяем.
  var QUALITIES = [0.9, 0.8, 0.7];
  var MAX_SHRINKS = 4;
  var SHRINK_FACTOR = 0.8;
  var GENERIC = 'Фото не загрузилось. Попробуйте ещё раз или выберите другое.';
  var SESSION = 'Сессия закончилась — обновите страницу и войдите заново.';
  var UNREADABLE = 'Не получилось открыть снимок — выберите другой файл.';
  var TOO_HEAVY = 'Снимок получился слишком тяжёлым — выберите другой.';
  var WAIT = 'Фото ещё загружаются — подождите немного.';
  // Сколько загрузок сейчас идёт в каждой форме: кнопки сохранения открываются, когда не осталось ни одной.
  var busy = new Map();

  /*
   * Сотруднице показываем только наши русские тексты и ответы сервера. Всё
   * остальное («Failed to fetch», «The source image could not be decoded.»)
   * заменяется общей фразой: по-английски ей не понять, что делать.
   */
  function shown(text, fatal) {
    var error = new Error(text);
    error.shown = true;
    error.fatal = Boolean(fatal);
    return error;
  }

  function encode(canvas, quality) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) { resolve(blob); } else { reject(new Error('')); }
      }, 'image/jpeg', quality);
    });
  }

  // Та же картинка, но с длинной стороной на пятую часть короче. Берём её с готового холста,
  // а не со снимка: снимок уже закрыт и память освобождена.
  function scaled(canvas) {
    var smaller = document.createElement('canvas');
    smaller.width = Math.max(1, Math.round(canvas.width * SHRINK_FACTOR));
    smaller.height = Math.max(1, Math.round(canvas.height * SHRINK_FACTOR));
    var context = smaller.getContext('2d');
    context.imageSmoothingQuality = 'high';
    context.drawImage(canvas, 0, 0, smaller.width, smaller.height);
    return smaller;
  }

  // Первый вариант, который уложился в предел по весу. Обычный снимок проходит с первой попытки.
  function fit(canvas, shrinks) {
    var index = 0;
    function next() {
      return encode(canvas, QUALITIES[index]).then(function (blob) {
        if (blob.size <= MAX_BYTES) { return blob; }
        index += 1;
        if (index < QUALITIES.length) { return next(); }
        if (shrinks >= MAX_SHRINKS) { throw shown(TOO_HEAVY); }
        return fit(scaled(canvas), shrinks + 1);
      });
    }
    return next();
  }

  function shrink(file) {
    return createImageBitmap(file).then(function (bitmap) {
      var scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      var context = canvas.getContext('2d');
      // У JPEG нет прозрачности: без белой подложки прозрачные места PNG стали бы чёрными.
      context.fillStyle = '#fff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      if (bitmap.close) { bitmap.close(); }
      return fit(canvas, 0);
    }, function () {
      throw shown(UNREADABLE);
    });
  }

  function button(text, act) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn-small';
    b.textContent = text;
    b.dataset.act = act;
    return b;
  }

  function item(name, path) {
    var li = document.createElement('li');
    var img = document.createElement('img');
    img.src = path;
    img.alt = '';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = path;
    li.append(img, input, button('←', 'left'), button('→', 'right'), button('Убрать', 'remove'));
    return li;
  }

  function send(box, blob) {
    var form = new FormData();
    form.append('csrf', box.dataset.csrf);
    ['uid', 'section', 'kind'].forEach(function (key) {
      if (box.dataset[key]) { form.append(key, box.dataset[key]); }
    });
    form.append('photo', blob, 'photo.jpg');
    return fetch(box.dataset.endpoint, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (r) {
      // Сессия закончилась — сервер перенаправил на страницу входа. Это единственный случай для такого текста.
      if (r.redirected || /\/login\.php(\?|$)/.test(r.url)) {
        throw shown(SESSION, true);
      }
      if (r.status === 413) {
        throw shown(TOO_HEAVY);
      }
      if ((r.headers.get('Content-Type') || '').indexOf('application/json') !== 0) {
        throw new Error('ответ не JSON');
      }
      return r.json();
    }).then(function (data) {
      if (!data || !data.ok || typeof data.path !== 'string') {
        // Текст ошибки — только тот, что прислал сам сервер в JSON.
        throw data && typeof data.error === 'string' && data.error ? shown(data.error) : new Error('непонятный ответ');
      }
      return data.path;
    });
  }

  function summary(added, problems, dropped, limit) {
    var parts = [];
    if (added > 0 && (problems.length > 0 || dropped > 0)) {
      parts.push('Добавлено ' + added + '.');
    }
    if (problems.length > 0) {
      parts.push((problems.length > 1 ? 'Не загрузилось снимков: ' + problems.length + '. ' : '') + problems[0]);
    }
    if (dropped > 0) {
      parts.push('Лишние снимки (' + dropped + ') не добавлены — не больше ' + limit + ' фото.');
    }
    return parts.join(' ');
  }

  function setup(box) {
    var list = box.querySelector('[data-photo-list]');
    var input = box.querySelector('input[type=file]');
    var message = box.querySelector('[data-photo-message]');
    var form = box.closest('form');
    var limit = Number(box.dataset.limit);
    var working = false;

    function refresh() {
      var full = list.children.length >= limit;
      input.disabled = full || working;
      box.classList.toggle('photos-full', full);
    }

    function lock(on) {
      working = on;
      if (form) {
        var count = (busy.get(form) || 0) + (on ? 1 : -1);
        busy.set(form, count);
        form.querySelectorAll('button:not([type=button]), input[type=submit]').forEach(function (b) {
          b.disabled = count > 0;
        });
      }
      refresh();
    }

    if (form) {
      // Запасная защита: Enter в поле формы мог бы отправить её, пока фото ещё в пути.
      form.addEventListener('submit', function (event) {
        if (busy.get(form) > 0) {
          event.preventDefault();
          message.textContent = WAIT;
        }
      });
    }

    list.addEventListener('click', function (event) {
      var b = event.target.closest('button[data-act]');
      if (!b) { return; }
      var li = b.closest('li');
      if (b.dataset.act === 'remove') {
        li.remove();
      } else if (b.dataset.act === 'left' && li.previousElementSibling) {
        list.insertBefore(li, li.previousElementSibling);
      } else if (b.dataset.act === 'right' && li.nextElementSibling) {
        list.insertBefore(li.nextElementSibling, li);
      }
      refresh();
    });

    input.addEventListener('change', function () {
      var chosen = Array.prototype.slice.call(input.files);
      input.value = '';
      var files = chosen.slice(0, Math.max(0, limit - list.children.length));
      var dropped = chosen.length - files.length;
      var added = 0;
      var problems = [];
      var stopped = false;

      function finish() {
        lock(false);
        message.textContent = summary(added, problems, dropped, limit);
      }

      if (files.length === 0) {
        message.textContent = summary(0, problems, dropped, limit);
        return;
      }
      message.textContent = 'Загружаю…';
      lock(true);
      files.reduce(function (chain, file) {
        return chain.then(function () {
          if (stopped) { return null; }
          // Каждый снимок отдельно: сбой одного не отменяет остальные, но и молча не пропадает.
          return Promise.resolve().then(function () {
            return shrink(file);
          }).then(function (blob) {
            return send(box, blob);
          }).then(function (path) {
            list.appendChild(item(box.dataset.name, path));
            added += 1;
          }).catch(function (error) {
            problems.push(error && error.shown ? error.message : GENERIC);
            if (error && error.fatal) { stopped = true; }
          });
        });
      }, Promise.resolve()).then(finish, finish);
    });

    refresh();
  }

  document.querySelectorAll('[data-photo-upload]').forEach(setup);
})();
