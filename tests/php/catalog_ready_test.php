<?php
/**
 * Готовность к переносу (этап 3А): ловушки, которые админка не должна
 * пропускать, когда в базе появится настоящий каталог.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';

t_case('вернуть в продажу без цены нельзя', function (): void {
    $db = t_catalog_with_sections();
    // Фото есть: здесь проверяется только цена.
    $uid = catalog_create_product($db, 'anna', t_fields(['images' => ['/images/catalog/bukety/buket-nezhnost.webp']]), t_now());
    catalog_publish($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    catalog_hide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    // Так выглядят 19 коробок из Tilda после загрузки: сняты, цены нет.
    $db->prepare('UPDATE products SET price = 0 WHERE uid = ?')->execute([$uid]);
    $e = t_throws(fn () => catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now()), CatalogError::class, 'без цены — отказ');
    t_true($e !== null && str_contains($e->getMessage(), 'Сначала укажите цену'), 'понятное объяснение');
    t_equal(t_row($db, $uid)['status'], 'hidden', 'букет остался снятым');

    $db->prepare('UPDATE products SET price = 4400 WHERE uid = ?')->execute([$uid]);
    catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    t_equal(t_row($db, $uid)['status'], 'active', 'с ценой — вернулся в продажу');
});

t_case('опубликовать черновик без фото нельзя', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $post = [
        'action' => 'publish', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
    ];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_true($r['status'] === 422 && str_contains($r['body'], 'Добавьте хотя бы одно фото'), 'без фото — объяснение, форма на месте');
    t_equal(t_row($ctx['db'], $uid)['status'], 'draft', 'остался черновиком');

    $post['images'] = ['/images/catalog/bukety/buket-nezhnost-' . $uid . '-aaaaaaaa.webp'];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_equal($r['status'], 303, 'с фото — опубликован');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'в продаже');
});

t_case('новый раздел создаётся скрытым', function (): void {
    $db = t_catalog_with_sections();
    $slug = catalog_create_section($db, 'anna', 'Осень', t_now());
    $q = $db->prepare('SELECT visible FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    t_equal((int)$q->fetchColumn(), 0, 'без фото плитки раздел в каталоге не показывается');
    t_true(!in_array(['type' => 'section', 'slug' => $slug], catalog_export_data($db)['tiles'], true), 'в сетке выгрузки его нет');
    t_true(str_contains(ADMIN_NOTICES['section-created'], 'скрыт'), 'сотруднику объяснено, что раздел пока скрыт');
});

t_case('сохранение карточки возвращает фото из корзины', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $path = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-bbbbbbbb.webp';
    t_put_files($ctx['webroot'], [ltrim($path, '/') => 'webp']);
    // Фото загрузили, но карточку не сохраняли больше суток — ночная уборка унесла файл в корзину.
    catalog_photo_trash($ctx['webroot'], $path, t_now());
    t_true(!is_file($ctx['webroot'] . $path), 'файл в корзине');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'save', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => [$path],
    ]);
    t_equal($r['status'], 303, 'сохранено');
    t_true(is_file($ctx['webroot'] . $path), 'фото вернулось на место');
});

t_case('мусор вместо даты выкладки не роняет страницы', function (): void {
    $ctx = t_admin_ctx();
    $junkList = [
        'вчера вечером', 12345, ['2026'], '',
        // По форме похоже на дату, но не разбирается — на этом месте раньше падал журнал.
        '2026-13-45T10:00:00+05:00', '2026-10-05T25:61:61+05:00', '2026-10-05T14:05:00+99:00',
    ];
    foreach ($junkList as $junk) {
        $what = json_encode($junk, JSON_UNESCAPED_UNICODE);
        file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
            'catalogVersion' => 'v1', 'catalogChangedAt' => $junk,
        ]]));
        $deployed = admin_deployed_catalog($ctx['deployHome']);
        t_equal($deployed['changedAt'], '', 'значение ' . $what . ' — неизвестно');
        // Настоящее место падения: отметка у изменения в журнале и карточке.
        try {
            t_equal(admin_change_on_site('2026-10-05T14:00:00+05:00', $deployed), null, 'значение ' . $what . ' — отметка неизвестна, страница цела');
        } catch (Throwable $e) {
            t_true(false, 'значение ' . $what . ' уронило отметку: ' . $e->getMessage());
        }
    }
    file_put_contents($ctx['deployHome'] . '/state.json', json_encode(['current' => [
        'catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:05:00+05:00',
    ]]));
    $deployed = admin_deployed_catalog($ctx['deployHome']);
    t_equal($deployed['changedAt'], '2026-10-05T14:05:00+05:00', 'нормальная дата — как есть');
    t_equal(admin_change_on_site('2026-10-05T14:00:00+05:00', $deployed), true, 'с нормальной датой отметка считается');
});

t_case('публикация из карточки тоже возвращает фото из корзины', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $path = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-cccccccc.webp';
    t_put_files($ctx['webroot'], [ltrim($path, '/') => 'webp']);
    catalog_photo_trash($ctx['webroot'], $path, t_now());
    t_true(!is_file($ctx['webroot'] . $path), 'файл в корзине');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'publish', 'uid' => $uid, 'version' => (string)t_row($ctx['db'], $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => [$path],
    ]);
    t_equal($r['status'], 303, 'опубликовано');
    t_true(is_file($ctx['webroot'] . $path), 'фото вернулось на место');
    t_equal(t_row($ctx['db'], $uid)['status'], 'active', 'в продаже');
});

t_case('букет в продаже без фото сохранить нельзя', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $photo = '/images/catalog/bukety/buket-nezhnost-' . $uid . '-dddddddd.webp';
    $db->prepare('UPDATE products SET images = ? WHERE uid = ?')->execute([json_encode([$photo]), $uid]);
    catalog_publish($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    $post = [
        'action' => 'save', 'uid' => $uid, 'version' => (string)t_row($db, $uid)['version'],
        'title' => 'Букет «Нежность»', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
    ];
    $before = t_row($db, $uid);
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_equal($r['status'], 422, 'в продаже без фото — отказ');
    t_true(str_contains($r['body'], 'Добавьте хотя бы одно фото — у букета в продаже оно должно быть.'), 'сотруднику объяснено, что делать');
    t_true(str_contains($r['body'], 'value="Букет «Нежность»"'), 'форма на месте, введённое не потеряно');
    $after = t_row($db, $uid);
    t_equal($after['status'], 'active', 'букет остался в продаже');
    t_equal($after['images'], $before['images'], 'фото в базе прежние');
    t_equal($after['version'], $before['version'], 'карточка не менялась');

    $post['images'] = [$photo];
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: $post);
    t_equal($r['status'], 303, 'с фото — сохранилось');

    // Черновик без фото сохранять можно: фото добавят позже, а публикация без него закрыта отдельно.
    $draft = catalog_create_product($db, 'anna', t_fields(['title' => 'Черновик']), t_now());
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: [
        'action' => 'save', 'uid' => $draft, 'version' => (string)t_row($db, $draft)['version'],
        'title' => 'Черновик', 'price' => '4400', 'description' => '', 'sections' => ['bukety'],
    ]);
    t_equal($r['status'], 303, 'черновик без фото сохраняется');
});

t_case('вернуть в продажу без фото нельзя', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    catalog_hide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    $db->prepare("UPDATE products SET price = 4400, images = '[]' WHERE uid = ?")->execute([$uid]);
    $audit = (int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn();
    $e = t_throws(fn () => catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now()), CatalogError::class, 'без фото — отказ');
    t_equal($e?->getMessage(), 'Сначала добавьте фото в карточке, потом возвращайте букет в продажу.', 'понятное объяснение');
    t_equal(t_row($db, $uid)['status'], 'hidden', 'букет остался снятым');
    t_equal((int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn(), $audit, 'в журнал ничего не попало');

    // Цена проверяется первой: без цены и без фото сотрудник сначала услышит про цену.
    $db->prepare('UPDATE products SET price = 0 WHERE uid = ?')->execute([$uid]);
    $e = t_throws(fn () => catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now()), CatalogError::class, 'без цены и фото — отказ');
    t_true($e !== null && str_contains($e->getMessage(), 'Сначала укажите цену'), 'сначала про цену');

    $db->prepare('UPDATE products SET price = 4400, images = ? WHERE uid = ?')
        ->execute([json_encode(['/images/catalog/bukety/buket-nezhnost-' . $uid . '-eeeeeeee.webp']), $uid]);
    catalog_unhide($db, 'anna', $uid, t_row($db, $uid)['version'], t_now());
    t_equal(t_row($db, $uid)['status'], 'active', 'с фото — вернулся в продажу');
});

t_case('раздел без фото плитки в каталоге не показать', function (): void {
    $db = t_catalog_with_sections();
    $tile = '/images/site/catalog-tiles/tile-0.webp';
    $text = 'Чтобы показать раздел в каталоге, сначала добавьте фото плитки.';
    $section = fn (string $slug): array => $db->query("SELECT * FROM sections WHERE slug = '$slug'")->fetch();
    $audit = fn (): int => (int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn();
    $meta = fn (): array => catalog_meta($db);

    // Скрытый раздел без плитки: показать нельзя, и отказ не оставляет следов ни в разделе, ни в журнале.
    $logged = $audit();
    $was = $meta();
    $e = t_throws(fn () => catalog_update_section($db, 'anna', 'novinki', ['visible' => true], t_now()), CatalogError::class, 'показать без плитки — отказ');
    t_equal($e?->getMessage(), $text, 'понятное объяснение');
    t_equal((int)$section('novinki')['visible'], 0, 'раздел остался скрытым');
    t_equal($audit(), $logged, 'в журнал ничего не попало');
    t_equal($meta(), $was, 'каталог для сайта не изменился');
    // Другие поля в той же правке отказ не обходят и в журнал не попадают.
    t_throws(fn () => catalog_update_section($db, 'anna', 'novinki', ['label' => 'Новое', 'visible' => true], t_now()), CatalogError::class, 'вместе с названием — тоже отказ');
    t_equal($section('novinki')['label'], 'Новинки', 'название не поменялось');
    t_equal($audit(), $logged, 'в журнале по-прежнему пусто');

    // Плитка и «показывать» в одной правке — проходит.
    catalog_update_section($db, 'anna', 'novinki', ['visible' => true, 'tileImage' => $tile], t_now());
    t_equal([(int)$section('novinki')['visible'], $section('novinki')['tile_image']], [1, $tile], 'показан сразу с плиткой');

    // Видимому разделу плитку убрать нельзя; скрыть и убрать вместе — можно.
    $logged = $audit();
    $e = t_throws(fn () => catalog_update_section($db, 'anna', 'novinki', ['tileImage' => ''], t_now()), CatalogError::class, 'убрать плитку у видимого — отказ');
    t_equal($e?->getMessage(), $text, 'то же объяснение');
    t_equal($section('novinki')['tile_image'], $tile, 'плитка осталась');
    t_equal($audit(), $logged, 'в журнал ничего не попало');
    catalog_update_section($db, 'anna', 'novinki', ['visible' => false, 'tileImage' => ''], t_now());
    t_equal([(int)$section('novinki')['visible'], $section('novinki')['tile_image']], [0, ''], 'скрыт — плитка не нужна');
    // Скрытому разделу без плитки остальное править можно.
    catalog_update_section($db, 'anna', 'novinki', ['coverSub' => 'Свежее'], t_now());
    t_equal($section('novinki')['cover_sub'], 'Свежее', 'скрытый раздел правится');

    // Раздел, который уже стоит в каталоге без плитки (так быть не должно, но в базе бывает):
    // правка, которая ничего не меняет, остаётся пустой, любая настоящая — просит добавить фото.
    $logged = $audit();
    catalog_update_section($db, 'anna', 'roses', ['label' => 'Розы', 'visible' => true, 'tileImage' => ''], t_now());
    t_equal($audit(), $logged, 'ничего не поменялось — ничего и не происходит');
    t_throws(fn () => catalog_update_section($db, 'anna', 'roses', ['coverSub' => 'Прямые поставки'], t_now()), CatalogError::class, 'видимый без плитки — настоящая правка отказ');
    t_equal($audit(), $logged, 'в журнал ничего не попало');
});

t_case('карточка раздела без плитки: объяснение на странице, введённое не теряется', function (): void {
    $ctx = t_admin_ctx();
    $r = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: [
        'action' => 'save', 'slug' => 'novinki', 'label' => 'Новинки осени', 'visible' => '1', 'tileImage' => '',
        'coverTitle' => 'Новинки', 'heading' => 'НОВИНКИ', 'orig' => [
            'label' => 'Новинки', 'tileImage' => '', 'visible' => '0', 'coverTitle' => 'Новинки', 'heading' => 'НОВИНКИ',
        ],
    ]);
    t_equal($r['status'], 422, 'отказ');
    t_true(str_contains($r['body'], 'Чтобы показать раздел в каталоге, сначала добавьте фото плитки.'), 'объяснение на странице');
    t_true(str_contains($r['body'], 'value="Новинки осени"'), 'введённое название на месте');
    t_true(str_contains($r['body'], 'name="visible" value="1" checked'), 'галочка не потеряна');
    t_equal((int)$ctx['db']->query("SELECT visible FROM sections WHERE slug = 'novinki'")->fetchColumn(), 0, 'в базе раздел скрыт');
});
