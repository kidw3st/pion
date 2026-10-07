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
    $add = $db->prepare("INSERT INTO audit (at, login, object_type, object_id, field, old_value, new_value) VALUES (?, 'anna', 'product', '1', 'price', '1', '2')");
    for ($i = 0; $i < 510; $i++) {
        $add->execute(['2026-10-05T15:00:00+05:00']);
    }
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_equal(substr_count($page, '<li class="log-item">'), 500, 'последние 500 изменений');
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
    t_true(str_contains($page, 'href="/pay/admin/section.php?slug=bukety"'), 'ссылка на раздел');
    // Порядок плиток — номерами в базе, человеку они ничего не говорят: только «что поменялось».
    t_true(str_contains($page, 'Плитки каталога · Порядок плиток</div>'), 'порядок плиток — без номеров');
    t_true(!str_contains($page, '1,2,3,4') && !str_contains($page, '1,3,2,4'), 'номеров плиток на странице нет');
});

t_case('сотрудники и загрузка каталога', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    // Мария, а не anna: сброс пароля заставил бы anna сменить его и закрыл бы остальные страницы.
    catalog_user_add($db, 'maria', 'Мария', t_now());
    catalog_user_reset($db, 'maria', t_now('+1 minute'));
    catalog_audit($db, 'import', t_now('+2 minutes'), 'catalog', '', 'imported', null, '12 букетов');
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'Сотрудник maria · Создан</div>'), 'новая учётная запись называет сотрудника');
    t_true(str_contains($page, 'Сотрудник maria · Пароль</div>'), 'сброс пароля называет сотрудника, самого пароля нет');
    t_true(str_contains($page, 'Загрузка каталога</div>') && !str_contains($page, '12 букетов'), 'загрузка каталога — без приставки и без числа');
    t_true(!str_contains($page, 'console') && !str_contains($page, 'import'), 'служебные имена вместо людей не показываются');
    t_equal(substr_count($page, 'Разработчик'), 3, 'то, что делали из консоли, — от имени разработчика');
});

t_case('удалённый черновик — без ссылки в пустоту', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_delete($db, 'anna', $uid, 1, t_now('+1 minute'));
    $page = t_admin_call($ctx, 'log', 'admin_page_log')['body'];
    t_true(str_contains($page, 'букет удалён'), 'в журнале след остался');
    t_true(!str_contains($page, 'product.php?uid=' . $uid), 'а ссылки на несуществующую карточку нет');
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
