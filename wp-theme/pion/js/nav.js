/**
 * Выдвижное меню на телефонах — то же поведение, что и на основном сайте:
 * панель выезжает справа, фон блокируется, Escape и клик по затемнению
 * закрывают.
 */
(function () {
  var burger = document.querySelector('.pion-burger');
  var panel = document.querySelector('[data-pion-panel]');
  var overlay = document.querySelector('[data-pion-overlay]');
  var closeBtn = document.querySelector('.pion-panel-close');

  if (!burger || !panel || !overlay) {
    return;
  }

  var prevOverflow = '';

  function open() {
    overlay.hidden = false;
    panel.hidden = false;
    // Между показом панели и сменой transform нужен пересчёт стилей, иначе
    // браузер схлопнет два состояния в одно и панель появится рывком. Читаем
    // offsetHeight — это заставляет пересчитать сразу. requestAnimationFrame
    // здесь не годится: в неактивной вкладке кадры не рисуются, и панель
    // осталась бы за краем экрана.
    void panel.offsetHeight;
    panel.classList.add('pion-panel-open');
    document.body.classList.add('pion-nav-open');
    burger.setAttribute('aria-expanded', 'true');
    prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }

  function close() {
    panel.classList.remove('pion-panel-open');
    document.body.classList.remove('pion-nav-open');
    burger.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = prevOverflow;
    overlay.hidden = true;

    // Прячем панель только после того, как она уехала за край экрана.
    var done = function () {
      panel.hidden = true;
      panel.removeEventListener('transitionend', done);
    };
    if (getComputedStyle(panel).transitionDuration === '0s') {
      done();
    } else {
      panel.addEventListener('transitionend', done);
    }
  }

  function toggle() {
    if (panel.hidden) {
      open();
    } else {
      close();
    }
  }

  burger.addEventListener('click', toggle);
  overlay.addEventListener('click', close);
  if (closeBtn) {
    closeBtn.addEventListener('click', close);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) {
      close();
    }
  });

  // Ушли по ссылке внутри панели — она должна закрыться, иначе при возврате
  // назад страница откроется с открытым меню.
  panel.addEventListener('click', function (e) {
    if (e.target.closest('a')) {
      close();
    }
  });

  // Растянули окно до десктопа — панель больше не нужна.
  window.addEventListener('resize', function () {
    if (window.innerWidth > 980 && !panel.hidden) {
      close();
    }
  });
})();
