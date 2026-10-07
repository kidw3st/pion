<?php
/**
 * Букеты: черновик и правка — проверка полей, uid и slug, главный раздел по
 * умолчанию, место в разделе, журнал и одновременная правка.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';

t_case('черновик', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $p = t_row($db, $uid);
    t_true((bool)preg_match('/^[1-9][0-9]{11}$/', $uid), 'uid — 12 цифр, как у букетов из Tilda');
    t_equal([$p['status'], $p['slug'], $p['slug_pinned'], $p['version']], ['draft', 'buket-nezhnost', 0, 1],
        'новый букет — черновик, slug из названия, ещё не закреплён');
    t_equal([$p['title'], $p['price'], $p['main_section'], $p['updated_by']], ['Букет «Нежность»', 4400, 'bukety', 'anna'],
        'поля сохранены');
    t_equal(catalog_export_data($db)['products'], [], 'черновика в выгрузке нет');
    t_equal(t_section_order($db, 'bukety'), [$uid], 'черновик уже стоит в своём разделе');
});

t_case('черновик не трогает время выкладки', function (): void {
    $db = t_catalog_with_sections();
    catalog_touch($db, t_now());
    catalog_create_product($db, 'anna', t_fields(), t_now('+1 hour'));
    t_equal(catalog_meta($db)['changed_at'], '2026-10-05T14:00:00+05:00', 'на сайте черновика нет — и ждать выкладки нечего');
});

t_case('проверка полей', function (): void {
    $db = t_catalog_with_sections();
    $bad = [
        'пустое название' => ['title' => '   '],
        'название длиннее 120 знаков' => ['title' => str_repeat('я', 121)],
        'состав длиннее 1000 знаков' => ['description' => str_repeat('я', 1001)],
        'цена меньше 100' => ['price' => 99],
        'цена больше 300 000' => ['price' => 300001],
        'цена строкой' => ['price' => '4400'],
        'цена с копейками' => ['price' => 4400.5],
        'пять фото' => ['images' => array_fill(0, 5, '/images/catalog/bukety/a.webp')],
        'фото не из каталога' => ['images' => ['/etc/passwd']],
        'ни одного раздела' => ['sections' => []],
        'нет такого раздела' => ['sections' => ['nope']],
        'главный не среди отмеченных' => ['sections' => ['bukety'], 'mainSection' => 'roses'],
    ];
    foreach ($bad as $what => $over) {
        t_throws(fn () => catalog_create_product($db, 'anna', t_fields($over), t_now()), CatalogError::class, "не сохраняется: $what");
    }
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'ничего из этого не записано');
    $uid = catalog_create_product($db, 'anna', t_fields([
        'title' => str_repeat('я', 120),
        'price' => 100,
        'images' => array_fill(0, 4, '/images/catalog/bukety/a.webp'),
    ]), t_now());
    t_equal(t_row($db, $uid)['price'], 100, 'границы допустимы: 120 знаков, 100 ₽, четыре фото');
    $e = t_throws(fn () => catalog_create_product($db, 'anna', t_fields(['price' => 50]), t_now()), CatalogError::class, 'цена 50 ₽');
    t_equal($e?->getMessage(), 'Цена — целое число рублей, от 100 до 300 000.', 'ошибка понятна без разработчика');
});

t_case('пути фото строго по форме', function (): void {
    $db = t_catalog_with_sections();
    $bad = [
        'перевод строки в конце' => "/images/catalog/bukety/a.webp\n",
        'выход из папки каталога через «..»' => '/images/catalog/../pay/x.webp',
    ];
    foreach ($bad as $what => $path) {
        t_throws(
            fn () => catalog_create_product($db, 'anna', t_fields(['images' => [$path]]), t_now()),
            CatalogError::class,
            "фото не принимается: $what",
        );
    }
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'ничего из этого не записано');
});

t_case('главный раздел по умолчанию', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(['sections' => ['novinki', 'roses']]), t_now());
    t_equal(t_row($db, $uid)['main_section'], 'roses', 'не «Новинки»: новизна проходит, а адрес должен остаться');
    $only = catalog_create_product($db, 'anna', t_fields(['sections' => ['novinki']]), t_now());
    t_equal(t_row($db, $only)['main_section'], 'novinki', 'отмечены только «Новинки» — главными становятся они');
    $two = catalog_create_product($db, 'anna', t_fields(['sections' => ['roses', 'bukety']]), t_now());
    t_equal(t_row($db, $two)['main_section'], 'bukety', 'первый отмеченный в порядке сетки');
});

t_case('новый — первым в разделе', function (): void {
    $db = t_catalog_with_sections();
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А']), t_now());
    $b = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б']), t_now());
    t_equal(t_section_order($db, 'bukety'), [$b, $a], 'добавленный встаёт первым');
});

t_case('правка черновика', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields([
        'title' => 'Букет «Весна»',
        'price' => 4800,
        'sections' => ['bukety', 'roses'],
    ]), t_now('+1 hour'));
    $p = t_row($db, $uid);
    t_equal([$p['slug'], $p['price'], $p['version']], ['buket-vesna', 4800, 2], 'slug черновика следует за названием, версия растёт');
    t_equal(t_section_order($db, 'roses'), [$uid], 'добавлен в отмеченный раздел');
    t_equal(
        $db->query("SELECT field, old_value, new_value FROM audit WHERE field <> 'created' ORDER BY id")->fetchAll(),
        [
            ['field' => 'title', 'old_value' => 'Букет «Нежность»', 'new_value' => 'Букет «Весна»'],
            ['field' => 'price', 'old_value' => '4400', 'new_value' => '4800'],
            ['field' => 'sections', 'old_value' => 'bukety', 'new_value' => 'bukety, roses'],
            ['field' => 'slug', 'old_value' => 'buket-nezhnost', 'new_value' => 'buket-vesna'],
        ],
        'в журнале — каждое изменённое поле: было → стало',
    );
    catalog_update_product($db, 'anna', $uid, 2, t_fields([
        'title' => 'Букет «Весна»',
        'price' => 4800,
        'sections' => ['roses'],
    ]), t_now('+2 hours'));
    t_equal(t_section_order($db, 'bukety'), [], 'сняли галочку — убран из раздела');
    t_equal(t_row($db, $uid)['main_section'], 'roses', 'главный раздел пересчитан по отмеченным');
});

t_case('одновременная правка', function (): void {
    $db = t_catalog_with_sections();
    $db->exec("INSERT INTO users (login, name, password_hash, created_at, updated_at)
        VALUES ('olga', 'Ольга', 'x', '2026-10-01T10:00:00+05:00', '2026-10-01T10:00:00+05:00')");
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_update_product($db, 'olga', $uid, 1, t_fields(['price' => 5000]), t_now('+5 minutes'));
    $e = t_throws(
        fn () => catalog_update_product($db, 'anna', $uid, 1, t_fields(['price' => 4600]), t_now('+6 minutes')),
        CatalogConflict::class,
        'сохранение поверх чужой правки отклоняется',
    );
    t_true(str_contains((string)$e?->getMessage(), 'Ольга'), 'в сообщении — кто успел сохранить');
    t_equal(t_row($db, $uid)['price'], 5000, 'чужая правка не затёрта');
});

t_case('нет такого букета', function (): void {
    $db = t_catalog_with_sections();
    t_throws(
        fn () => catalog_update_product($db, 'anna', '999999999999', 1, t_fields(), t_now()),
        CatalogError::class,
        'правка несуществующего — понятная ошибка',
    );
});
