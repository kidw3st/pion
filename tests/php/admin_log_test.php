<?php
/**
 * Журнал: кто, когда, что — было → стало, последние 500, отметки «на сайте /
 * ждёт выкладки»; история в карточке букета.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-log.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/catalog/sections.php';

t_case('журнал', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4800, 'sections' => ['bukety', 'roses']]), t_now('+1 minute'));
    catalog_publish($db, 'anna', $uid, 2, t_now('+2 minutes'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, "Цена: 4\u{00A0}400\u{00A0}₽ → 4\u{00A0}800\u{00A0}₽"), 'цена — рублями');
    t_true(str_contains($page, 'Статус: Черновик → В продаже'), 'статус — словами');
    t_true(str_contains($page, 'Разделы: Букеты → Букеты, Розы'), 'разделы — названиями');
    t_true(str_contains($page, 'Анна') && str_contains($page, '5 октября, 14:02'), 'кто и когда');
    t_true(str_contains($page, '14:02 · Анна'), 'имя сотрудника рядом со временем, а не логин');
    t_true(str_contains($page, 'href="/pay/admin/product.php?uid=' . $uid . '"'), 'ссылка на букет');
    t_true(strpos($page, 'Статус:') < strpos($page, 'Цена:'), 'свежие изменения — сверху');
});

t_case('экранирование и предел', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    catalog_create_product($db, 'anna', t_fields(['title' => '<script>alert(1)</script>']), t_now());
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, '<script>alert(1)</script>'), 'значения экранированы');
    t_true(str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'и название букета при этом на месте — как текст');
    // У каждой лишней строки своё значение: так видно, какие именно изменения отброшены.
    $add = $db->prepare("INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value) VALUES ('2026-10-05T15:00:00+05:00', 'anna', 'product', '1', 'title', ?, ?)");
    for ($i = 0; $i < 510; $i++) {
        $add->execute(["до-$i.", "запись-$i."]);
    }
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_equal(substr_count($page, '<li class="log-item">'), 500, 'последние 500 изменений');
    // Всего 511 строк: самая старая — создание букета с «<script>», потом строки 0…509; отбрасываются первые 11.
    t_true(!str_contains($page, '&lt;script&gt;') && !str_contains($page, 'запись-0.') && !str_contains($page, 'запись-9.'), 'отброшены самые старые');
    t_true(str_contains($page, 'запись-10.') && str_contains($page, 'запись-509.'), 'остались самые свежие');
    t_true(strpos($page, 'запись-509.') < strpos($page, 'запись-10.'), 'и свежие — сверху');
});

t_case('предел и странные значения не роняют журнал', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $add = $db->prepare("INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value) VALUES ('2026-10-05T15:00:00+05:00', 'anna', 'product', '1', ?, ?, ?)");
    $add->execute(['images', '5', '"строка"']);
    $add->execute(['covers', '{"a":1}', '[["вложенный"]]']);
    $add->execute(['images', '[]', '["/images/catalog/bukety/a.webp"]']);
    $result = t_admin_call($ctx, 'log', 'admin_page_log');
    t_equal($result['status'], 200, 'число и строка вместо списка фото — страница на месте');
    t_true(str_contains($result['body'], 'Фото: 5 → &quot;строка&quot;'), 'непонятное значение показано как есть');
    t_true(str_contains($result['body'], 'Фото: 0 фото → 1 фото'), 'а обычное — по-человечески');
    t_equal(admin_audit_value('images', '5', []), '5', 'число вместо списка фото');
    t_equal(admin_audit_value('covers', '"x"', []), '"x"', 'строка вместо списка фото');
    t_equal(admin_audit_value('images', '[1,2]', []), '[1,2]', 'не пути — не считаем фото');
    // В SQLite LIMIT -1 — «без предела»: отрицательное число не должно открыть весь журнал.
    t_equal(count(admin_audit_rows($db, null, -1)), 1, 'отрицательный предел — одна строка');
    t_equal(count(admin_audit_rows($db, null, 0)), 1, 'нулевой — тоже одна');
    t_equal(count(admin_audit_rows($db, null, 2)), 2, 'обычный предел как был');
});

t_case('отметки и история в карточке', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now('+1 minute'));
    catalog_update_product($db, 'anna', $uid, 2, t_fields(['price' => 4500]), t_now('+10 minutes'));
    $other = catalog_create_product($db, 'anna', t_fields(['title' => 'Другой букет']), t_now('+11 minutes'));
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'на сайте') && str_contains($page, 'ждёт выкладки'), 'у изменений — отметки');
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    t_true(str_contains($card, '<h2>История</h2>') && str_contains($card, 'Цена:'), 'в карточке — история этого букета');
    t_true(!str_contains($card, 'Другой букет'), 'и только его');
    unlink($ctx['deployHome'] . '/state.json');
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, 'на сайте') && !str_contains($page, 'ждёт выкладки'), 'выложенное неизвестно — без отметок');
});

t_case('отметки по границе выкладки и при совпадении версий', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4500]), t_now('+10 minutes'));
    // Версия в базе уже не та, что выложена: смотрим на время каждого изменения.
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'старая', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_equal(substr_count($page, 'ждёт выкладки'), 1, 'позднее выложенного — ждёт выкладки');
    t_equal(substr_count($page, 'на сайте'), 1, 'раньше выложенного — на сайте');
    // Версии совпали: на сайте всё, даже то, что новее отметки времени.
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => catalog_meta($db)['version'], 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, 'ждёт выкладки') && str_contains($page, 'на сайте'), 'версии совпали — всё на сайте');
});

t_case('разделы и порядок плиток — словами', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    catalog_update_section($db, 'anna', 'bukety', [
        'label' => 'Букеты и композиции', 'visible' => false, 'hasNotFound' => false, 'seoTitle' => 'Букеты в Перми',
        'covers' => ['/images/site/category-covers/a.webp', '/images/site/category-covers/b.webp'],
    ], t_now());
    catalog_reorder_tiles($db, 'anna', [1, 3, 2, 4], t_now('+1 minute'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'Название раздела: Букеты → Букеты и композиции'), 'название раздела — как есть');
    t_true(str_contains($page, 'Показывать в каталоге: да → нет'), 'галочка — «да» и «нет»');
    t_true(str_contains($page, 'Блок «Не нашли нужное?»: да → нет'), 'блок «Не нашли нужное?» — «да» и «нет»');
    t_true(str_contains($page, 'Фото обложки: 0 фото → 2 фото'), 'обложки — числом фото');
    t_true(str_contains($page, 'Заголовок для поиска: — → Букеты в Перми'), 'заголовок для поиска — без слова SEO, пусто — чёрточка');
    t_true(!str_contains($page, 'SEO'), 'технических слов нет');
    t_true(str_contains($page, '<div class="log-object"><a href="/pay/admin/section.php?slug=bukety">Букеты и композиции</a></div>'), 'ссылка на раздел — отдельной строкой');
    // Порядок плиток — номерами в базе, человеку они ничего не говорят: только «что поменялось».
    t_true(str_contains($page, '<div class="log-object">Плитки каталога</div><div>Порядок плиток</div>'), 'порядок плиток — без номеров');
    t_true(!str_contains($page, '1,2,3,4') && !str_contains($page, '1,3,2,4'), 'номеров плиток на странице нет');
});

t_case('ссылка на букет — своей строкой, не ниже 44 px; в карточке строки без неё', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4800]), t_now('+1 minute'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, '<div class="log-object"><a href="/pay/admin/product.php?uid=' . $uid . '">Букет «Нежность»</a></div><div>Цена:'), 'название букета — отдельная строка над изменением');
    t_true(!str_contains($page, '</a> · '), 'точка-разделитель после ссылки не нужна: она рвала бы строку');
    $css = (string)file_get_contents(__DIR__ . '/../../server-pay/admin/assets/admin.css');
    t_true((bool)preg_match('/\.log-object a[^{]*\{[^}]*min-height:\s*44px/', $css), 'ссылка в журнале — не ниже 44 px');
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    $history = substr($card, (int)strpos($card, '<h2>История</h2>'));
    t_true(!str_contains($history, 'log-object') && str_contains($history, '<li class="log-item"><div class="log-head">'), 'в истории букета отдельной строки с названием нет');
});

t_case('фото — словами, а не «1 фото → 1 фото»', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    [$a, $b, $c] = ['/images/catalog/bukety/a.webp', '/images/catalog/bukety/b.webp', '/images/catalog/bukety/c.webp'];
    $uid = catalog_create_product($db, 'anna', t_fields(['images' => [$a, $b]]), t_now());
    $steps = [[$b, $a], [$b, $c], [$b, $c, $a], [$b]];
    foreach ($steps as $i => $images) {
        catalog_update_product($db, 'anna', $uid, $i + 1, t_fields(['images' => $images]), t_now('+' . ($i + 1) . ' minutes'));
    }
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'Фото: порядок изменён'), 'те же фото в другом порядке');
    t_true(str_contains($page, 'Фото: заменены'), 'столько же фото, но другие');
    t_true(str_contains($page, 'Фото: 2 фото → 3 фото'), 'добавили — числа');
    t_true(str_contains($page, 'Фото: 3 фото → 1 фото'), 'убрали — числа');
    t_true(!str_contains($page, '2 фото → 2 фото'), 'одинаковых чисел «было → стало» нет');

    // Плитка и обложки раздела. Берём скрытый: у видимого раздела плитка обязательна, убрать её нельзя.
    $tile = fn (string $name): string => '/images/site/catalog-tiles/' . $name . '.webp';
    $cover = fn (string $name): string => '/images/site/category-covers/' . $name . '.webp';
    catalog_update_section($db, 'anna', 'novinki', ['tileImage' => $tile('x')], t_now('+10 minutes'));
    catalog_update_section($db, 'anna', 'novinki', ['tileImage' => $tile('y')], t_now('+11 minutes'));
    catalog_update_section($db, 'anna', 'novinki', ['tileImage' => ''], t_now('+12 minutes'));
    catalog_update_section($db, 'anna', 'novinki', ['covers' => [$cover('a'), $cover('b')]], t_now('+13 minutes'));
    catalog_update_section($db, 'anna', 'novinki', ['covers' => [$cover('b'), $cover('a')]], t_now('+14 minutes'));
    catalog_update_section($db, 'anna', 'novinki', ['covers' => [$cover('b'), $cover('c')]], t_now('+15 minutes'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'Фото плитки: — → есть фото'), 'плитки не было, появилась');
    t_true(str_contains($page, 'Фото плитки: заменено'), 'плитку заменили');
    t_true(str_contains($page, 'Фото плитки: есть фото → —'), 'плитку убрали');
    t_true(str_contains($page, 'Фото обложки: 0 фото → 2 фото'), 'обложки добавили');
    t_true(str_contains($page, 'Фото обложки: порядок изменён'), 'обложки переставили');
    t_true(str_contains($page, 'Фото обложки: заменены'), 'обложку заменили');
    t_true(!str_contains($page, '/images/'), 'путей к файлам на странице нет');
});

t_case('сотрудники и загрузка каталога', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    // Мария, а не anna: сброс пароля заставил бы anna сменить его и закрыл бы остальные страницы.
    catalog_user_add($db, 'maria', 'Мария', t_now());
    catalog_user_reset($db, 'maria', t_now('+1 minute'));
    // Смена пароля самой Марией — так её пишет admin_change_password (auth.php).
    catalog_audit($db, 'maria', t_now('+2 minutes'), 'user', 'maria', 'password', null, 'сменён');
    catalog_audit($db, 'import', t_now('+3 minutes'), 'catalog', '', 'imported', null, '12 букетов');
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, '<div class="log-object">Сотрудник maria</div><div>Создан</div>'), 'новая учётная запись называет сотрудника');
    t_true(str_contains($page, '<div class="log-object">Сотрудник maria</div><div>Пароль сброшен</div>'), 'сброс пароля — словами из журнала');
    t_true(str_contains($page, '<div class="log-object">Сотрудник maria</div><div>Пароль сменён</div>'), 'смена пароля — тоже');
    t_true(str_contains($page, '<div>Загрузка каталога</div>') && !str_contains($page, 'log-object">Загрузка') && !str_contains($page, '12 букетов'), 'загрузка каталога — без приставки и без числа');
    t_true(!str_contains($page, 'console') && !str_contains($page, 'import'), 'служебные имена вместо людей не показываются');
    t_equal(substr_count($page, 'Разработчик'), 3, 'то, что делали из консоли, — от имени разработчика');
    // Показываем только известные слова: что бы ни лежало в журнале, неизвестное значение поля пароля не выводится.
    catalog_audit($db, 'maria', t_now('+4 minutes'), 'user', 'maria', 'password', null, 'hunter2-секрет');
    catalog_audit($db, 'maria', t_now('+5 minutes'), 'user', 'maria', 'password', 'hunter2-старый', null);
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(!str_contains($page, 'hunter2'), 'неизвестное значение пароля не показывается');
    t_equal(substr_count($page, '<div>Пароль</div>'), 2, 'вместо него — одно название поля');
});

t_case('учётные записи на сайт не выкладываются — отметок у них нет', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_user_add($db, 'maria', 'Мария', t_now('+1 minute'));
    catalog_user_reset($db, 'maria', t_now('+2 minutes'));
    catalog_audit($db, 'import', t_now('+3 minutes'), 'catalog', '', 'imported', null, '12 букетов');
    // Версии совпали — всё, что идёт на сайт, на сайте.
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => catalog_meta($db)['version'], 'catalogChangedAt' => '2026-10-05T14:00:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    $items = array_slice(explode('<li class="log-item">', $page), 1);
    t_equal(count($items), 4, 'четыре строки журнала');
    $byText = fn (string $text): array => array_values(array_filter($items, fn (string $item): bool => str_contains($item, $text)));
    $users = $byText('Сотрудник maria');
    t_equal(count($users), 2, 'две строки про сотрудницу');
    foreach ($users as $item) {
        t_true(!str_contains($item, 'на сайте') && !str_contains($item, 'ждёт выкладки'), 'у строки про учётную запись отметки нет');
    }
    t_true(str_contains($byText('Загрузка каталога')[0] ?? '', 'на сайте'), 'у загрузки каталога отметка есть');
    t_true(str_contains($byText('href="/pay/admin/product.php?uid=' . $uid)[0] ?? '', 'на сайте'), 'у букета — тоже');
    // Не выложено — «ждёт выкладки» у букета и загрузки, у сотрудницы по-прежнему ничего.
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'старая', 'catalogChangedAt' => '2026-10-05T13:00:00+05:00',
    ]]));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    $items = array_slice(explode('<li class="log-item">', $page), 1);
    $waiting = array_values(array_filter($items, fn (string $item): bool => str_contains($item, 'ждёт выкладки')));
    t_equal(count($waiting), 2, 'ждут выкладки букет и загрузка каталога');
    t_true(!str_contains(implode('', $waiting), 'Сотрудник'), 'но не сотрудница');
});

t_case('удалённый черновик — название из журнала и без ссылки в пустоту', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет «Лето»']), t_now());
    $other = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет «Осень»']), t_now('+1 minute'));
    catalog_delete($db, 'anna', $uid, 1, t_now('+2 minutes'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_equal(substr_count($page, '<div class="log-object">Букет «Лето» (удалён)</div>'), 2, 'создан и удалён — оба раза под названием');
    t_true(!str_contains($page, 'product.php?uid=' . $uid), 'ссылки на несуществующую карточку нет');
    t_true(str_contains($page, 'product.php?uid=' . $other . '">Букет «Осень»</a>'), 'у живого букета ссылка на месте');
    t_true(!str_contains($page, 'букет удалён'), 'название известно — общей подписи нет');
    // Название из журнала — значение из базы: экранируется так же.
    $uid2 = catalog_create_product($db, 'anna', t_fields(['title' => '<b>Хитрый</b>']), t_now('+3 minutes'));
    catalog_delete($db, 'anna', $uid2, 1, t_now('+4 minutes'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, '&lt;b&gt;Хитрый&lt;/b&gt; (удалён)') && !str_contains($page, '<b>Хитрый</b>'), 'название удалённого экранировано');
    // Совсем без следа названия — общая подпись.
    $db->exec("INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value) VALUES ('2026-10-05T15:00:00+05:00', 'anna', 'product', '999', 'price', '1', '2')");
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, '<div class="log-object">букет удалён</div>'), 'названия нет нигде — «букет удалён»');
});

t_case('история в карточке: последние 10, без названия букета, пустая', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    for ($i = 1; $i <= 12; $i++) {
        catalog_update_product($db, 'anna', $uid, $i, t_fields(['price' => 4400 + $i * 100]), t_now("+$i minutes"));
    }
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    $history = substr($card, (int)strpos($card, '<h2>История</h2>'));
    t_equal(substr_count($history, '<li class="log-item">'), 10, 'в карточке — только последние 10');
    t_true(str_contains($history, "5\u{00A0}500\u{00A0}₽ → 5\u{00A0}600\u{00A0}₽"), 'самое свежее изменение на месте');
    t_true(!str_contains($history, "4\u{00A0}500\u{00A0}₽ → 4\u{00A0}600\u{00A0}₽"), 'а старые не влезают');
    t_true(!str_contains($history, 'product.php?uid='), 'в истории букета ссылки на сам букет нет');
    t_true(strpos($card, '<h2>Действия</h2>') < strpos($card, '<h2>История</h2>'), 'история — под действиями');

    t_put_product($db, '777777777777', 'draft', 'bukety', ['bukety' => 1]);
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => '777777777777'])['body'];
    t_true(str_contains($card, '<h2>История</h2>') && str_contains($card, 'Изменений пока нет.'), 'у букета без правок — «Изменений пока нет»');
});
