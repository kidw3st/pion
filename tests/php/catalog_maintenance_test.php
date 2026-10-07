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
    t_equal($first, ['candidates' => 1, 'moved' => 0, 'purged' => 0, 'returned' => 0], 'фото без ссылок — пока только кандидат');
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

t_case('фото загрузили, две уборки прошли, карточку сохранили', function (): void {
    // Сотрудник загрузил снимок и ушёл пить чай: карточка сохраняется, когда
    // фото уже в корзине. Живой букет не должен остаться без фото.
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-12ab34cd.webp";
    $inTrash = '/images/catalog/_deleted/bukety/' . basename($rel);
    t_photo_file($webroot, $rel);
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_true($r['moved'] === 1 && is_file($webroot . $inTrash), 'две уборки — и фото в корзине');

    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now('+26 hours'));
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+27 hours'));
    t_equal($r, ['candidates' => 0, 'moved' => 0, 'purged' => 0, 'returned' => 1], 'на фото сослались — оно вернулось из корзины');
    t_true(is_file($webroot . $rel) && !is_file($webroot . $inTrash), 'лежит на своём месте, в корзине его нет');

    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+93 days'));
    t_equal([$r['purged'], $r['returned'], is_file($webroot . $rel)], [0, 0, true], 'и через 90 дней фото живого букета на месте');
});

t_case('корзина не стирает то, на что ссылаются', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-1a2b3c4d.webp";
    $inTrash = '/images/catalog/_deleted/bukety/' . basename($rel);
    $other = '/images/catalog/_deleted/bukety/buket-staryi-' . $uid . '-0f0f0f0f.webp';
    t_photo_file($webroot, $inTrash);
    t_photo_file($webroot, $other);
    touch($webroot . $inTrash, t_now('-91 days')->getTimestamp());
    touch($webroot . $other, t_now('-91 days')->getTimestamp());
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now('-2 days'));

    $r = catalog_photos_sweep($db, $webroot, t_tmpdir() . '/c.json', t_now());
    t_equal([$r['returned'], $r['purged'], is_file($webroot . $rel), is_file($webroot . $inTrash), is_file($webroot . $other)],
        [1, 1, true, false, false], 'старое фото со ссылкой вернулось, а не стёрлось; без ссылки — стёрто');

    // Место занято (вернуть нельзя): корзинный экземпляр всё равно не стирается.
    t_photo_file($webroot, $inTrash);
    touch($webroot . $inTrash, t_now('-91 days')->getTimestamp());
    $r = catalog_photos_sweep($db, $webroot, t_tmpdir() . '/c.json', t_now());
    t_equal([$r['returned'], $r['purged'], is_file($webroot . $inTrash)], [0, 0, true], 'вернуть некуда — в корзине остаётся, пока на фото ссылаются');

    // Фото раздела — то же самое.
    $tile = '/images/catalog/_sections/roses-tile-0a0a0a0a.webp';
    t_photo_file($webroot, '/images/catalog/_deleted/_sections/roses-tile-0a0a0a0a.webp');
    touch($webroot . '/images/catalog/_deleted/_sections/roses-tile-0a0a0a0a.webp', t_now('-100 days')->getTimestamp());
    catalog_update_section($db, 'anna', 'roses', ['tileImage' => $tile], t_now());
    $r = catalog_photos_sweep($db, $webroot, t_tmpdir() . '/c.json', t_now());
    t_equal([$r['returned'], is_file($webroot . $tile)], [1, true], 'плитка раздела из корзины тоже возвращается');
});

t_case('предохранитель: слишком много к переносу', function (): void {
    // Пути в базе сбились (без начального «/») — «без ссылок» выглядят все фото.
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $paths = [];
    for ($i = 0; $i < 60; $i++) {
        $paths[] = sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i);
        t_photo_file($webroot, $paths[$i]);
    }
    $db->prepare('UPDATE products SET images = ? WHERE uid = ?')
        ->execute([json_encode(array_map(fn (string $p): string => ltrim($p, '/'), $paths)), $uid]);

    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now());
    t_equal($r['candidates'], 60, 'первый раз — просто кандидаты: суток ещё не прошло');
    $e = t_throws(
        fn () => catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours')),
        RuntimeException::class,
        'через сутки 60 из 60 к переносу — уборка останавливается',
    );
    t_true($e !== null && str_contains($e->getMessage(), 'Уборка фото остановлена') && str_contains($e->getMessage(), '60'), 'сказано по-русски и сколько');
    t_equal(count(array_filter($paths, fn (string $p): bool => is_file($webroot . $p))), 60, 'ни одно фото не тронуто');
    t_true(!is_dir($webroot . '/images/catalog/_deleted'), 'корзина не создавалась');
    t_equal(count(json_decode((string)file_get_contents($candidates), true)), 60, 'список кандидатов цел: время «без ссылок» не теряется');

    // Починили пути — уборка идёт дальше, и фото остаются на месте.
    $db->prepare('UPDATE products SET images = ? WHERE uid = ?')->execute([json_encode($paths), $uid]);
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+26 hours'));
    t_equal([$r['moved'], $r['candidates']], [0, 0], 'после исправления фото со ссылками остаются');
});

t_case('предохранитель: доля от всех фото', function (): void {
    // 575 фото с известным хозяином, 55 из них без ссылок: это меньше 10 %
    // (57,5) — уборка идёт. Было бы 58 — остановилась бы.
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $live = [];
    for ($i = 0; $i < 520; $i++) {
        $live[] = sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i);
        t_photo_file($webroot, $live[$i]);
    }
    $db->prepare('UPDATE products SET images = ? WHERE uid = ?')->execute([json_encode($live), $uid]);
    foreach ([55, 58] as $count) {
        $stray = [];
        for ($i = 0; $i < $count; $i++) {
            $stray[] = sprintf("/images/catalog/bukety/buket-nezhnost-$uid-ff%06x.webp", $i);
            t_photo_file($webroot, $stray[$i]);
        }
        $file = t_tmpdir() . '/c.json';
        catalog_photos_sweep($db, $webroot, $file, t_now());
        $total = 520 + $count;
        if ($count === 55) {
            $r = catalog_photos_sweep($db, $webroot, $file, t_now('+25 hours'));
            t_equal([$r['moved'], $r['candidates']], [55, 0], "$total фото, $count без ссылок — меньше 10 %, уборка идёт");
        } else {
            t_throws(fn () => catalog_photos_sweep($db, $webroot, $file, t_now('+50 hours')), RuntimeException::class,
                "$total фото, $count без ссылок — больше 10 %, уборка останавливается");
        }
    }
});

t_case('корзина не стирает ничего, пока предохранитель сработал', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $old = '/images/catalog/_deleted/bukety/old.webp';
    for ($i = 0; $i < 51; $i++) {
        t_photo_file($webroot, sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i));
    }
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    t_photo_file($webroot, $old);
    touch($webroot . $old, t_now('-91 days')->getTimestamp());
    t_throws(fn () => catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours')), RuntimeException::class, 'остановились');
    t_true(is_file($webroot . $old), 'и старое в корзине не стёрто: ссылкам, что сбились, верить нельзя');
});

t_case('фото убирается в корзину со временем переноса', function (): void {
    // rename время не меняет: старое фото в корзине со старым временем стёрлось бы тут же.
    $webroot = t_tmpdir();
    $rel = '/images/catalog/bukety/buket-nezhnost-123456789012-aaaaaaaa.webp';
    t_photo_file($webroot, $rel);
    touch($webroot . $rel, t_now('-200 days')->getTimestamp());
    t_true(catalog_photo_trash($webroot, $rel, t_now()), 'перенесено');
    t_equal(filemtime($webroot . '/images/catalog/_deleted/bukety/' . basename($rel)), t_now()->getTimestamp(), 'время файла в корзине — момент переноса');

    // Время поставить не удалось — фото не двигается (где файл с запретом на запись такое допускает).
    $locked = '/images/catalog/bukety/buket-nezhnost-123456789012-bbbbbbbb.webp';
    t_photo_file($webroot, $locked);
    chmod($webroot . $locked, 0444);
    if (!@touch($webroot . $locked, t_now()->getTimestamp())) {
        t_equal(catalog_photo_trash($webroot, $locked, t_now()), false, 'время не поставить — фото не переносится');
        t_true(is_file($webroot . $locked) && !is_file($webroot . '/images/catalog/_deleted/bukety/' . basename($locked)), 'остаётся на месте');
    }
    chmod($webroot . $locked, 0644);
});

t_case('имя фото: последняя группа из 12 цифр и алфавит сайта', function (): void {
    $db = t_catalog_with_sections();
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    t_true(catalog_photo_owner_known($db, 'bukety', "roza-111111111111-$uid-aaaaaaaa.webp"), 'в названии есть своя группа из 12 цифр — хозяин по последней');
    t_true(catalog_photo_owner_known($db, 'bukety', "roza-111111111111-$uid.webp"), 'то же у старого имени без хэша');
    t_true(!catalog_photo_owner_known($db, 'bukety', "roza-$uid-111111111111.webp"), 'известный uid в середине, чужая группа последняя — хозяин не наш');
    t_true(catalog_photo_owner_known($db, 'bukety', "roza-$uid-1.webp"), 'старое имя с номером снимка');
    t_true(!catalog_photo_owner_known($db, 'bukety', "Roza-$uid-aaaaaaaa.webp"), 'заглавные буквы — не наше имя');
    t_true(!catalog_photo_owner_known($db, 'bukety', "roza nezhnost-$uid-aaaaaaaa.webp"), 'пробел — не наше имя');
    t_true(!catalog_photo_owner_known($db, 'bukety', "buket-розы-$uid-aaaaaaaa.webp"), 'кириллица — не наше имя');
    t_true(!catalog_photo_owner_known($db, 'bukety', "buket-\xff-$uid-aaaaaaaa.webp"), 'битые байты в имени — не наше имя');
    t_true(!catalog_photo_owner_known($db, 'bukety', "roza_$uid-aaaaaaaa.webp"), 'подчёркивание — не наше имя');
    t_true(!catalog_photo_owner_known($db, '_sections', "Roses-tile-0a1b2c3d.webp"), 'заглавные в имени плитки — не наше');
    t_true(!catalog_photo_owner_known($db, '_sections', "ro ses-tile-0a1b2c3d.webp"), 'пробел в имени плитки — не наше');

    $webroot = t_tmpdir();
    $odd = ["/images/catalog/bukety/Roza-$uid-aaaaaaaa.webp", "/images/catalog/bukety/roza nezhnost-$uid-aaaaaaaa.webp"];
    foreach ($odd as $file) {
        t_photo_file($webroot, $file);
    }
    $candidates = t_tmpdir() . '/c.json';
    $r1 = catalog_photos_sweep($db, $webroot, $candidates, t_now());
    $r2 = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal([$r1['candidates'], $r2['moved'], is_file($webroot . $odd[0]), is_file($webroot . $odd[1])], [0, 0, true, true], 'файлы с чужими именами уборка не трогает');

    t_throws(fn () => catalog_photo_candidates_save(t_tmpdir() . '/c.json', ["/images/catalog/bukety/\xff.webp" => 1]), RuntimeException::class,
        'список, который не собрать в JSON, — понятная ошибка, а не TypeError');
});

t_case('скрытый букет и восстановление удалённого', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-abcdef01.webp";
    $inTrash = '/images/catalog/_deleted/bukety/' . basename($rel);
    t_photo_file($webroot, $rel);
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now());
    catalog_publish($db, 'anna', $uid, 2, t_now());
    catalog_hide($db, 'anna', $uid, 3, t_now());
    catalog_photos_sweep($db, $webroot, $candidates, t_now());
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'));
    t_equal([$r['candidates'], $r['moved'], is_file($webroot . $rel)], [0, 0, true], 'фото скрытого букета остаются: он ещё может вернуться в продажу');

    catalog_unhide($db, 'anna', $uid, 4, t_now('+26 hours'));
    catalog_delete($db, 'anna', $uid, 5, t_now('+26 hours'));
    catalog_photos_sweep($db, $webroot, $candidates, t_now('+27 hours'));
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+52 hours'));
    t_true($r['moved'] === 1 && is_file($webroot . $inTrash) && !is_file($webroot . $rel), 'удалили — через две уборки фото в корзине');

    // Восстановление, как в admin_product_action: букет в базе, затем фото из корзины.
    catalog_restore($db, 'anna', $uid, 6, t_now('+53 hours'));
    foreach (json_decode(t_row($db, $uid)['images'], true) as $path) {
        t_true(catalog_photo_untrash($webroot, $path), 'фото вернулось из корзины');
    }
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+54 hours'));
    t_equal($r, ['candidates' => 0, 'moved' => 0, 'purged' => 0, 'returned' => 0], 'после восстановления уборка ничего не переносит');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+80 hours'));
    t_equal([$r['moved'], is_file($webroot . $rel), is_file($webroot . $inTrash)], [0, true, false], 'и на следующий день фото на месте');
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
    t_true(str_contains($out, 'возвращено из корзины 0'), 'в строке про фото сказано и про возврат из корзины');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия на месте');
});

t_case('maintenance-cli.php: предохранитель', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $webroot = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $db = null;
    $since = time() - 3 * 86400;
    $list = [];
    for ($i = 0; $i < 60; $i++) {
        $rel = sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i);
        t_photo_file($webroot, $rel);
        $list[$rel] = $since;
    }
    file_put_contents("$home/photo-candidates.json", json_encode($list));
    putenv('PION_WEBROOT=' . $webroot);
    try {
        [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    } finally {
        putenv('PION_WEBROOT');
    }
    t_true($code === 1 && str_contains($out, 'Обслуживание не удалось: Уборка фото остановлена'), 'слишком много к переносу — сообщение и код 1');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия базы к этому времени уже сделана');
    t_equal(count(glob($webroot . '/images/catalog/bukety/*.webp') ?: []), 60, 'фото на месте');
});
