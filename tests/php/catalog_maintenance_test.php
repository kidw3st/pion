<?php
/**
 * Уборка фото: кандидаты, корзина через сутки, корзина стирается через 90
 * дней, чужие файлы не трогаются; maintenance-cli.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/products.php';
require_once __DIR__ . '/../../server-pay/catalog/maintenance.php';

/** Файл фото во временном корне сайта. */
function t_photo_file(string $webroot, string $rel): void
{
    if (!is_dir(dirname($webroot . $rel))) {
        mkdir(dirname($webroot . $rel), 0777, true);
    }
    file_put_contents($webroot . $rel, 'webp');
}

t_case('уборка фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $kept = "/images/catalog/bukety/buket-nezhnost-$uid-aaaaaaaa.webp";
    $dropped = "/images/catalog/bukety/buket-nezhnost-$uid-bbbbbbbb.webp";
    $foreign = '/images/catalog/bukety/buket-barhatnye-grani-553645466981.webp';
    foreach ([$kept, $dropped, $foreign] as $rel) {
        t_photo_file($webroot, $rel);
    }
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$kept]]), t_now());

    $first = catalog_photos_sweep($db, $webroot, $candidates, t_now());
    t_equal($first, ['candidates' => 1, 'moved' => 0, 'purged' => 0], 'фото без ссылок — пока только кандидат');
    t_true(is_file($webroot . $dropped), 'сразу не уносится: старая страница на сайте может его ещё показывать');

    $second = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal($second['moved'], 1, 'через сутки — в корзину');
    t_true(!is_file($webroot . $dropped) && is_file($webroot . '/images/catalog/_deleted/bukety/' . basename($dropped)), 'лежит в _deleted');
    t_true(is_file($webroot . $kept), 'фото со ссылкой не тронуто');
    t_true(is_file($webroot . $foreign), 'фото, хозяина которого база не знает, не тронуто');
});

t_case('снова нужное фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-cccccccc.webp";
    t_photo_file($webroot, $rel);
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now('+1 hour'));
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal([$r['moved'], $r['candidates'], is_file($webroot . $rel)], [0, 0, true], 'на фото снова ссылаются — остаётся, из кандидатов выбывает');
});

t_case('фото удалённого букета и разделов', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-dddddddd.webp";
    t_photo_file($webroot, $rel);
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now());
    catalog_publish($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    $tileOld = '/images/catalog/_sections/roses-tile-eeeeeeee.webp';
    $tileNow = '/images/catalog/_sections/roses-tile-ffffffff.webp';
    $alien = '/images/catalog/_sections/nope-tile-12345678.webp';
    foreach ([$tileOld, $tileNow, $alien] as $file) {
        t_photo_file($webroot, $file);
    }
    catalog_update_section($db, 'anna', 'roses', ['tileImage' => $tileNow], t_now());
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_true(!is_file($webroot . $rel), 'фото удалённого букета — в корзине');
    t_true(!is_file($webroot . $tileOld) && is_file($webroot . $tileNow), 'заменённая плитка раздела — в корзине, нынешняя — на месте');
    t_true(is_file($webroot . $alien), 'плитка неизвестного раздела не тронута');
});

t_case('фото черновика, удалённого насовсем', function (): void {
    // Черновик при удалении теряет строку в products, но его uid остаётся в
    // журнале: хозяин фото известен, и фото не лежит в папке вечно.
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-99999999.webp";
    $foreign = '/images/catalog/bukety/buket-barhatnye-grani-553645466981.webp';
    $foreignHash = '/images/catalog/bukety/buket-lyubimaya-553645466982-5a3f9c01.webp';
    foreach ([$rel, $foreign, $foreignHash] as $file) {
        t_photo_file($webroot, $file);
    }
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now());
    catalog_delete($db, 'anna', $uid, 2, t_now());
    t_equal((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 0, 'черновик удалён совсем — строки в products нет');

    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal($r['moved'], 1, 'фото удалённого черновика — в корзину');
    t_true(!is_file($webroot . $rel) && is_file($webroot . '/images/catalog/_deleted/bukety/' . basename($rel)), 'лежит в _deleted');
    t_true(is_file($webroot . $foreign) && is_file($webroot . $foreignHash), 'uid, которого база не видела, — файлы не тронуты');
});

t_case('хозяин фото: букет, журнал, раздел', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    t_true(catalog_photo_owner_known($db, 'bukety', "buket-$uid-aaaaaaaa.webp"), 'букет с uid в базе');
    t_true(catalog_photo_owner_known($db, 'bukety', "buket-$uid-12345678.webp"), 'хэш из одних цифр — тоже имя фото');
    t_true(!catalog_photo_owner_known($db, 'bukety', 'buket-553645466981-aaaaaaaa.webp'), 'uid нет ни в базе, ни в журнале');
    t_true(!catalog_photo_owner_known($db, 'bukety', 'buket-nezhnost.webp'), 'в имени нет uid');
    // Журнал помнит uid и без строки в products.
    catalog_audit($db, 'anna', t_now(), 'product', '553645466981', 'deleted', 'Старый', null);
    t_true(catalog_photo_owner_known($db, 'bukety', 'buket-553645466981-aaaaaaaa.webp'), 'uid есть в журнале букетов');
    catalog_audit($db, 'anna', t_now(), 'section', '553645466982', 'created', null, 'Чужой тип');
    t_true(!catalog_photo_owner_known($db, 'bukety', 'buket-553645466982-aaaaaaaa.webp'), 'то же число в журнале раздела — не хозяин букета');
    t_true(catalog_photo_owner_known($db, '_sections', 'roses-cover-0a1b2c3d.webp'), 'раздел есть');
    t_true(!catalog_photo_owner_known($db, '_sections', 'nope-cover-0a1b2c3d.webp'), 'раздела нет');
    t_true(!catalog_photo_owner_known($db, '_sections', 'roses.webp'), 'имя не по образцу');
});

t_case('список кандидатов пишется целиком', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $dir = t_tmpdir();
    $candidates = $dir . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-abababab.webp";
    t_photo_file($webroot, $rel);
    // Обрыв прошлого запуска: недописанный временный файл не должен мешать.
    file_put_contents($candidates . '.part', '{"/images/catalog/bukety/obr');
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    $saved = json_decode((string)file_get_contents($candidates), true);
    t_equal($saved, [$rel => t_now()->getTimestamp()], 'в файле — целый список с временем, когда фото стало кандидатом');
    t_equal(glob($dir . '/*'), [$candidates], 'временного файла не осталось');
    t_throws(
        fn () => catalog_photos_sweep($db, $webroot, $dir . '/нет-такой-папки/c.json', t_now()),
        RuntimeException::class,
        'список не записать — об этом говорят, а не молчат',
    );
});

t_case('корзина стирается через 90 дней', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $old = '/images/catalog/_deleted/bukety/old.webp';
    $fresh = '/images/catalog/_deleted/bukety/fresh.webp';
    t_photo_file($webroot, $old);
    t_photo_file($webroot, $fresh);
    touch($webroot . $old, t_now('-91 days')->getTimestamp());
    touch($webroot . $fresh, t_now('-89 days')->getTimestamp());
    $r = catalog_photos_sweep($db, $webroot, t_tmpdir() . '/c.json', t_now());
    t_equal([$r['purged'], is_file($webroot . $old), is_file($webroot . $fresh)], [1, false, true], 'старше 90 дней — стёрто, остальное ждёт');
});

t_case('maintenance-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    t_equal([$code, str_contains($out, 'ещё нет')], [0, true], 'базы нет — спокойно выходит');
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    putenv('PION_WEBROOT=' . t_tmpdir());
    try {
        [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    } finally {
        putenv('PION_WEBROOT');
    }
    t_true($code === 0 && str_contains($out, 'Копия:') && str_contains($out, 'Фото:'), 'копия и уборка сделаны');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия на месте');
});
