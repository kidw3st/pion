/*
 * Фото в карточках букетов и разделов. Браузер сам уменьшает снимок до
 * 2000 px по длинной стороне и отправляет JPEG — по одному снимку за запрос,
 * так он быстро уходит и с мобильного интернета. Остальное делает сервер
 * (pay/admin/photo.php): пересохраняет в WebP и возвращает путь к файлу,
 * а путь попадает в форму и сохраняется вместе с ней. Порядок меняют
 * стрелками; первое фото — главное.
 */
(function () {
  'use strict';
  var MAX_SIDE = 2000;

  function shrink(file) {
    return createImageBitmap(file).then(function (bitmap) {
      var scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          if (blob) { resolve(blob); } else { reject(new Error('')); }
        }, 'image/jpeg', 0.9);
      });
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
      var type = r.headers.get('Content-Type') || '';
      if (r.redirected || type.indexOf('application/json') !== 0) {
        throw new Error('Сессия закончилась — обновите страницу и войдите заново.');
      }
      return r.json();
    }).then(function (data) {
      if (!data.ok) { throw new Error(data.error); }
      return data.path;
    });
  }

  function setup(box) {
    var list = box.querySelector('[data-photo-list]');
    var input = box.querySelector('input[type=file]');
    var message = box.querySelector('[data-photo-message]');
    var limit = Number(box.dataset.limit);

    function refresh() {
      var full = list.children.length >= limit;
      input.disabled = full;
      box.classList.toggle('photos-full', full);
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
      var files = Array.prototype.slice.call(input.files, 0, Math.max(0, limit - list.children.length));
      input.value = '';
      message.textContent = files.length ? 'Загружаю…' : '';
      files.reduce(function (chain, file) {
        return chain.then(function () {
          return shrink(file).then(function (blob) {
            return send(box, blob);
          }).then(function (path) {
            list.appendChild(item(box.dataset.name, path));
            refresh();
          });
        });
      }, Promise.resolve()).then(function () {
        message.textContent = '';
      }, function (error) {
        message.textContent = error && error.message
          ? error.message
          : 'Фото не загрузилось. Попробуйте ещё раз или выберите другое.';
      });
    });

    refresh();
  }

  document.querySelectorAll('[data-photo-upload]').forEach(setup);
})();
