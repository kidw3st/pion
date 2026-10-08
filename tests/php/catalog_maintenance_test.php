<?php
/**
 * Уборка фото: кандидаты, корзина через сутки и после выкладки, корзина
 * стирается через 90 дней, чужие файлы не трогаются; maintenance-cli.php:
 * состояние выкладки на входе, maintenance.json и maintenance.log на выходе.
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

t_case('уборка ждёт, пока сайт выложен без фото', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-eeeeeeee.webp";
    t_put_files($webroot, [ltrim($rel, '/') => 'webp']);
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), fn (string $version): bool => false);
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'), fn (string $version): bool => false);
    t_equal([$r['moved'], is_file($webroot . $rel)], [0, true], 'сутки прошли, но сайт ещё не выложен — фото на месте');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+26 hours'), fn (string $version): bool => true);
    t_equal([$r['moved'], is_file($webroot . $rel)], [1, false], 'сайт выложен — в корзину');
});

t_case('выложено ли: только та же версия, что в базе', function (): void {
    // Время правки в состоянии выкладки уборку не интересует: судим по версии.
    $covers = catalog_deploy_covers(['catalogVersion' => 'v1', 'catalogChangedAt' => '2026-10-05T14:00:00+05:00']);
    t_true($covers('v1'), 'версии совпадают — выложено всё');
    t_true(!$covers('v2'), 'версия другая — не выложено, хоть время правки и стоит');
    t_true(!catalog_deploy_covers(['catalogVersion' => 'v1', 'catalogChangedAt' => '2999-01-01T00:00:00+05:00'])('v2'), 'и время правки «из будущего» версию не заменяет');
    t_true(catalog_deploy_covers(['catalogVersion' => 'v1', 'catalogChangedAt' => ''])('v1'), 'каталог не менялся, версии те же — выложено');
    t_true(catalog_deploy_covers(['catalogVersion' => 'v1'])('v1'), 'времени в записи нет вовсе — версии те же, выложено');
    t_true(!catalog_deploy_covers(null)('v1'), 'состояния нет — ничего не двигаем');
});

t_case('выложено ли: чего не знаем, того не двигаем', function (): void {
    $unknown = [
        'состояния нет' => null,
        'в записи ничего нет' => [],
        'версии нет' => ['catalogChangedAt' => '2026-10-05T14:00:00+05:00'],
        'версия пустая' => ['catalogVersion' => '', 'catalogChangedAt' => '2026-10-05T14:00:00+05:00'],
        'версия не строкой' => ['catalogVersion' => 5, 'catalogChangedAt' => ''],
        'версия null' => ['catalogVersion' => null],
        'версия массивом' => ['catalogVersion' => ['v1']],
    ];
    foreach ($unknown as $what => $current) {
        t_true(!catalog_deploy_covers($current)('v1'), "$what — не выложено");
        t_true(!catalog_deploy_covers($current)(''), "$what, версия в базе пуста — тоже не выложено");
    }
    t_true(!catalog_deploy_covers(['catalogVersion' => 'v1'])(''), 'в базе версии нет — сравнивать не с чем, не выложено');
    t_true(!catalog_deploy_covers(['catalogVersion' => ''])(''), 'пустая версия у обеих сторон — это «неизвестно», а не «совпало»');
    t_true(!catalog_deploy_covers(['catalogVersion' => 'V1'])('v1'), 'версии сравниваются точно');
    t_true(!catalog_deploy_covers(['catalogVersion' => ' v1'])('v1'), 'пробел в версии — другая версия');
});

t_case('уборка: кандидат, что ждёт только выкладки, не теряет своё время', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-e1e1e1e1.webp";
    t_photo_file($webroot, $rel);
    $version = (string)(catalog_meta($db)['version'] ?? '');
    t_true($version !== '', 'у базы с букетом есть версия');
    $asked = [];
    $published = function (string $dbVersion) use (&$asked): bool {
        $asked[] = $dbVersion;
        return false;
    };
    $first = t_now()->getTimestamp();
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), $published);
    t_equal($asked, [$version], 'о выкладке спрашивают один раз за уборку и с версией базы');
    foreach (['+25 hours', '+49 hours', '+73 hours'] as $shift) {
        $r = catalog_photos_sweep($db, $webroot, $candidates, t_now($shift), $published);
        t_equal([$r['candidates'], $r['moved'], is_file($webroot . $rel)], [1, 0, true], "$shift: фото ждёт выкладки");
        t_equal(json_decode((string)file_get_contents($candidates), true), [$rel => $first], "$shift: в списке прежнее время, не сегодняшнее");
    }
    t_equal($asked, [$version, $version, $version, $version], 'каждая уборка спрашивает ровно один раз');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+97 hours'), fn (string $dbVersion): bool => $dbVersion === $version);
    t_equal([$r['moved'], $r['candidates'], is_file($webroot . $rel)], [1, 0, false], 'выкладка дошла — фото уходит с прежним временем, ждать ещё сутки не надо');
});

t_case('уборка: выкладке называют свежую версию базы, один раз за уборку', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    for ($i = 0; $i < 3; $i++) {
        t_photo_file($webroot, sprintf("/images/catalog/bukety/buket-nezhnost-$uid-d%07x.webp", $i));
    }
    $asked = [];
    $published = function (string $dbVersion) use (&$asked): bool {
        $asked[] = $dbVersion;
        return false;
    };
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), $published);
    catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'), $published);
    $db->exec("UPDATE meta SET value = 'после-правки' WHERE key = 'version'");
    catalog_photos_sweep($db, $webroot, $candidates, t_now('+26 hours'), $published);
    t_equal(count($asked), 3, 'три уборки — три вопроса, а не по вопросу на каждое из трёх фото');
    t_equal($asked[2], 'после-правки', 'версия читается при каждой уборке заново');
    t_true($asked[0] !== '' && $asked[0] === $asked[1], 'пока база не менялась, версия та же');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+27 hours'), fn (string $dbVersion): bool => $dbVersion === 'после-правки');
    t_equal($r['moved'], 3, 'выложена нынешняя версия базы — фото уходят');
});

t_case('уборка: сборка из середины суток не считается выложенной', function (): void {
    // Фото без ссылок увидела первая ночь; днём на него сослались (сборка с этой правкой
    // выложена), вечером убрали снова. Вторая ночь не видела ссылки между своими уборками
    // и хранит старое время «без ссылок»: по времени правки та сборка сошла бы за выложенную
    // «без фото», хотя на сайте оно есть.
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    catalog_publish($db, 'anna', $uid, 1, t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-c1c1c1c1.webp";
    t_photo_file($webroot, $rel);
    $since = t_now()->getTimestamp();
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), fn (string $dbVersion): bool => false);

    catalog_update_product($db, 'anna', $uid, 2, t_fields(['images' => [$rel]]), t_now('+20 hours'));
    $midday = catalog_meta($db);
    $deployed = ['catalogVersion' => $midday['version'], 'catalogChangedAt' => $midday['changed_at']];
    catalog_update_product($db, 'anna', $uid, 3, t_fields(['images' => []]), t_now('+30 hours'));
    $evening = catalog_meta($db)['version'];
    t_true($evening !== $midday['version'], 'вечерняя правка изменила версию выгрузки');
    t_true(strtotime($midday['changed_at']) >= $since, 'время правки на выложенной сборке позже, чем «без ссылок» у фото: по времени это сошло бы за выложенное');

    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+37 hours'), catalog_deploy_covers($deployed));
    t_equal([$r['moved'], $r['candidates'], is_file($webroot . $rel)], [0, 1, true], 'выложена версия из середины суток, а не нынешняя — фото на месте');
    t_equal(json_decode((string)file_get_contents($candidates), true), [$rel => $since], 'и ждёт со своим прежним временем');

    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+38 hours'), catalog_deploy_covers(['catalogVersion' => $evening] + $deployed));
    t_equal([$r['moved'], is_file($webroot . $rel)], [1, false], 'выложена нынешняя версия — в корзину');
});

t_case('уборка: ссылки и версия читаются одной транзакцией, она не остаётся открытой', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), fn (string $dbVersion): bool => true);
    t_true(!$db->inTransaction(), 'после уборки транзакции нет');
    // Таблица букетов пропала — ссылки не прочитать: исключение наружу, транзакция отменена.
    $db->exec('ALTER TABLE products RENAME TO products_ушла');
    t_throws(fn () => catalog_photos_sweep($db, $webroot, $candidates, t_now(), null), PDOException::class, 'ссылки не прочитались — исключение наружу');
    t_true(!$db->inTransaction(), 'транзакция после ошибки отменена, база не заперта');
    $db->exec('ALTER TABLE products_ушла RENAME TO products');
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now(), null);
    t_equal($r['candidates'], 0, 'после починки уборка идёт как прежде');
});

/**
 * PDO, который между чтением ссылок на фото и чтением версии каталога
 * пытается из второго соединения поменять версию: так проверяется, что обе
 * половины читаются из одного снимка базы.
 */
class T_RacingPdo extends PDO
{
    public ?string $raceFile = null;
    /** @var list<string> */
    public array $raced = [];

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->raceFile !== null && str_contains($query, 'FROM meta')) {
            try {
                $other = new PDO('sqlite:' . $this->raceFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $other->exec('PRAGMA busy_timeout = 0');
                $other->exec("UPDATE meta SET value = 'гонка' WHERE key = 'version'");
                $this->raced[] = 'записано';
            } catch (PDOException) {
                $this->raced[] = 'заперто';
            }
            $this->raceFile = null;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

t_case('уборка: правка посреди чтения не раздвигает ссылки и версию', function (): void {
    $file = t_tmpdir() . '/catalog.sqlite';
    $plain = t_catalog_with_sections(catalog_db_open($file));
    catalog_create_product($plain, 'anna', t_fields(), t_now());
    $version = (string)(catalog_meta($plain)['version'] ?? '');
    $plain = null;
    $racing = new T_RacingPdo('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $racing->raceFile = $file;
    $asked = [];
    catalog_photos_sweep($racing, t_tmpdir(), t_tmpdir() . '/c.json', t_now(), function (string $dbVersion) use (&$asked): bool {
        $asked[] = $dbVersion;
        return false;
    });
    t_equal(count($racing->raced), 1, 'попытка второй правки между чтением ссылок и версии была');
    t_equal($asked, [$version], 'уборка получила ту версию, что была при чтении ссылок, а не «гонку»');
});

t_case('предохранитель считает только то, что выложено', function (): void {
    $db = t_catalog_with_sections();
    $webroot = t_tmpdir();
    $candidates = t_tmpdir() . '/photo-candidates.json';
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    for ($i = 0; $i < 60; $i++) {
        t_photo_file($webroot, sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i));
    }
    catalog_photos_sweep($db, $webroot, $candidates, t_now(), fn (string $version): bool => false);
    $r = catalog_photos_sweep($db, $webroot, $candidates, t_now('+25 hours'), fn (string $version): bool => false);
    t_equal([$r['candidates'], $r['moved']], [60, 0], 'ни одно не выложено — к переносу ничего, предохранитель молчит');
    t_equal(count(glob($webroot . '/images/catalog/bukety/*.webp') ?: []), 60, 'фото на месте');
    t_throws(
        fn () => catalog_photos_sweep($db, $webroot, $candidates, t_now('+26 hours'), fn (string $version): bool => true),
        RuntimeException::class,
        'выложено — и 60 из 60 к переносу: уборка останавливается',
    );
    t_equal(count(glob($webroot . '/images/catalog/bukety/*.webp') ?: []), 60, 'и после этого фото на месте');
});

/**
 * maintenance-cli.php отдельным процессом. Корень сайта и папка выкладки — во
 * временных папках: ничего не читается с настоящего диска. $deployHome null —
 * переменная PION_DEPLOY_HOME снята, путь берётся по умолчанию от корня сайта.
 *
 * @return array{0: int, 1: string} код выхода и вывод
 */
function t_maintenance_run(string $scripts, string $home, string $webroot, ?string $deployHome): array
{
    putenv('PION_WEBROOT=' . $webroot);
    putenv($deployHome === null ? 'PION_DEPLOY_HOME' : 'PION_DEPLOY_HOME=' . $deployHome);
    try {
        return t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    } finally {
        putenv('PION_WEBROOT');
        putenv('PION_DEPLOY_HOME');
        // Файлы двигал дочерний процесс: is_file() этого процесса помнит, что было до него.
        clearstatcache();
    }
}

/** Итог обслуживания из catalog_home()/maintenance.json; null — файла нет или он не JSON. */
function t_maintenance_result(string $home): ?array
{
    $done = json_decode((string)@file_get_contents("$home/maintenance.json"), true);
    return is_array($done) ? $done : null;
}

/** Строки maintenance.log. */
function t_maintenance_log(string $home): array
{
    return is_file("$home/maintenance.log") ? (file("$home/maintenance.log", FILE_IGNORE_NEW_LINES) ?: []) : [];
}

t_case('maintenance-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/maintenance-cli.php", $home);
    t_equal([$code, str_contains($out, 'ещё нет')], [0, true], 'базы нет — спокойно выходит');
    t_true(!is_file("$home/maintenance.json") && !is_file("$home/maintenance.log"), 'и итога не пишет: обслуживать было нечего');
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $webroot = t_tmpdir();
    $started = time();
    [$code, $out] = t_maintenance_run($scripts, $home, $webroot, t_tmpdir());
    $finished = time();
    t_true($code === 0 && str_contains($out, 'Копия:') && str_contains($out, 'Фото:'), 'копия и уборка сделаны');
    t_true(str_contains($out, 'возвращено из корзины 0'), 'в строке про фото сказано и про возврат из корзины');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия на месте');

    $done = t_maintenance_result($home);
    t_equal($done === null ? null : array_keys($done), ['ok', 'at', 'message'], 'итог — три поля, как ждёт сторож');
    t_equal($done['ok'] ?? null, true, 'всё получилось — ok: true');
    t_true(is_string($done['at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d\z/', $done['at']) === 1, 'время — в виде catalog_iso');
    $at = (int)strtotime((string)($done['at'] ?? ''));
    t_true($at >= $started && $at <= $finished, 'время — когда запуск закончился, а не когда начался или когда-то ещё');
    t_true(is_string($done['message'] ?? null) && $done['message'] !== '' && !str_contains($done['message'], "\n"), 'сообщение — одна непустая строка');
    t_equal(glob("$home/*.part") ?: [], [], 'временных файлов не осталось');
    $log = t_maintenance_log($home);
    t_equal($log, [($done['at'] ?? '') . ' ok'], 'в журнале — одна строка на запуск');

    [$code] = t_maintenance_run($scripts, $home, $webroot, t_tmpdir());
    $again = t_maintenance_result($home);
    t_equal([$code, $again['ok'] ?? null, (int)strtotime((string)($again['at'] ?? '')) >= $at, count(t_maintenance_log($home))], [0, true, true, 2], 'второй запуск: итог заменён целиком, в журнал дописана вторая строка');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия за день одна');
});

t_case('maintenance-cli.php: испорченная база', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    file_put_contents("$home/catalog.sqlite", str_repeat("это не база SQLite\n", 300));
    $started = time();
    [$code, $out] = t_maintenance_run($scripts, $home, t_tmpdir(), t_tmpdir());
    $finished = time();
    t_true($code === 1 && str_contains($out, 'Обслуживание не удалось:'), 'код 1 и сообщение для того, кто смотрит вывод cron');
    $done = t_maintenance_result($home);
    t_equal($done['ok'] ?? null, false, 'в итоге ok: false');
    $message = (string)($done['message'] ?? '');
    t_true($message !== '' && !str_contains($message, "\n") && mb_strlen($message) <= 300, 'причина — непустая короткая строка');
    t_true(preg_match('/\p{Cyrillic}/u', $message) === 1, 'и по-русски, даже если ошибку назвал SQLite');
    t_true(!str_contains($message, 'Обслуживание не удалось'), 'без слов, которые сторож и сам ставит перед причиной');
    $at = (int)strtotime((string)($done['at'] ?? ''));
    t_true($at >= $started && $at <= $finished, 'время — когда запуск закончился');
    $log = t_maintenance_log($home);
    t_equal($log, [($done['at'] ?? '') . ' ошибка: ' . $message], 'в журнале — строка с причиной');
    t_equal(glob("$home/*.part") ?: [], [], 'временных файлов не осталось');

    // Базу поправили — следующий запуск пишет «получилось» поверх сбоя.
    unlink("$home/catalog.sqlite");
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    [$code] = t_maintenance_run($scripts, $home, t_tmpdir(), t_tmpdir());
    $fixed = t_maintenance_result($home);
    t_equal([$code, $fixed['ok'] ?? null, count(t_maintenance_log($home))], [0, true, 2], 'после починки — ok: true, в журнале обе строки');
    t_true((int)strtotime((string)($fixed['at'] ?? '')) >= $at, 'время нового итога не раньше прежнего');
});

t_case('maintenance-cli.php: фото ждут выкладки', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $root = t_tmpdir();
    $webroot = "$root/www/pionperm.ru";
    $deployHome = "$root/pion-deploy";
    mkdir($webroot, 0777, true);
    mkdir($deployHome);
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $version = (string)(catalog_meta($db)['version'] ?? '');
    $db = null;
    t_true($version !== '', 'у базы с букетом есть версия');
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-a1a1a1a1.webp";
    $inTrash = '/images/catalog/_deleted/bukety/' . basename($rel);
    $stale = '/images/catalog/_deleted/bukety/stale.webp';
    t_photo_file($webroot, $rel);
    t_photo_file($webroot, $stale);
    touch($webroot . $stale, time() - 91 * 86400);
    $since = time() - 3 * 86400;
    file_put_contents("$home/photo-candidates.json", json_encode([$rel => $since]));
    $run = fn (): array => t_maintenance_run($scripts, $home, $webroot, $deployHome);
    $listed = fn (): array => json_decode((string)file_get_contents("$home/photo-candidates.json"), true);

    // Состояния выкладки нет: ничего не двигаем, но копия, корзина и итог — как обычно.
    [$code, $out] = $run();
    t_true($code === 0 && is_file($webroot . $rel) && !is_file($inTrash) && $listed() === [$rel => $since], 'выкладка о каталоге не знает — фото на месте и со своим временем');
    t_true(!is_file($webroot . $stale) && str_contains($out, 'стёрто из корзины 1'), 'старое из корзины стёрто и без выкладки');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия базы сделана');
    t_equal(t_maintenance_result($home)['ok'] ?? null, true, 'итог ok: true');
    t_true(str_contains($out, 'неизвестно'), 'вывод говорит, почему фото не уходят');

    // Нечитаемое состояние — то же самое.
    file_put_contents("$deployHome/state.json", '{"current": ');
    [$code] = $run();
    t_true($code === 0 && is_file($webroot . $rel), 'состояние испорчено — фото на месте, запуск не падает');
    unlink("$deployHome/state.json");
    mkdir("$deployHome/state.json");
    [$code] = $run();
    t_true($code === 0 && is_file($webroot . $rel) && (t_maintenance_result($home)['ok'] ?? null) === true, 'вместо файла папка — тоже без падения');
    rmdir("$deployHome/state.json");

    // Выложена другая версия каталога — ждём, какое бы время правки ни стояло в состоянии выкладки.
    $state = fn (array $current): string => json_encode(['current' => $current], JSON_UNESCAPED_UNICODE) ?: '';
    $at = fn (int $time): string => catalog_iso(new DateTimeImmutable('@' . $time));
    foreach (['правка старше, чем «без ссылок»' => $since - 3600, 'правка новее, чем «без ссылок»' => $since + 3600, 'правка «из будущего»' => time() + 86400, 'времени нет' => null] as $what => $changed) {
        file_put_contents("$deployHome/state.json", $state(['catalogVersion' => 'old', 'catalogChangedAt' => $changed === null ? '' : $at($changed)]));
        [$code] = $run();
        t_true($code === 0 && is_file($webroot . $rel) && !is_file($webroot . $inTrash) && $listed() === [$rel => $since], "выложена другая версия ($what) — фото ждёт, время прежнее");
    }
    t_equal(t_maintenance_result($home)['ok'] ?? null, true, 'ожидание выкладки — не сбой: ok: true');

    // Версия выложенного та же, что в базе, — сутки прошли, фото уходит.
    file_put_contents("$deployHome/state.json", $state(['catalogVersion' => $version, 'catalogChangedAt' => '']));
    [$code, $out] = $run();
    t_true($code === 0 && !is_file($webroot . $rel) && is_file($webroot . $inTrash) && $listed() === [], 'версия выложенного равна версии базы — фото в корзине');
    t_true(str_contains($out, 'убрано в корзину 1') && !str_contains($out, 'неизвестно'), 'и вывод об этом говорит без жалоб на состояние');

    // Сутки ещё не прошли — выложено или нет, фото не уходит.
    $rel2 = "/images/catalog/bukety/buket-nezhnost-$uid-a2a2a2a2.webp";
    t_photo_file($webroot, $rel2);
    file_put_contents("$home/photo-candidates.json", json_encode([$rel2 => time() - 3600]));
    [$code] = $run();
    t_true($code === 0 && is_file($webroot . $rel2), 'версия та же, но «без ссылок» всего час — фото на месте');
});

t_case('maintenance-cli.php: без состояния выкладки возврат из корзины идёт', function (): void {
    // Возврат того, на что снова сослались, от выкладки не зависит: это защита, а не уборка.
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $webroot = t_tmpdir();
    $deployHome = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-f1f1f1f1.webp";
    $inTrash = '/images/catalog/_deleted/bukety/' . basename($rel);
    t_photo_file($webroot, $inTrash);
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$rel]]), t_now());
    $db = null;
    t_true(!is_file("$deployHome/state.json"), 'состояния выкладки нет');
    [$code, $out] = t_maintenance_run($scripts, $home, $webroot, $deployHome);
    t_true($code === 0 && str_contains($out, 'возвращено из корзины 1'), 'фото, на которое снова сослались, вернулось из корзины');
    t_true(is_file($webroot . $rel) && !is_file($webroot . $inTrash), 'лежит на своём месте, в корзине его нет');
    t_equal(t_maintenance_result($home)['ok'] ?? null, true, 'итог ok: true');
});

t_case('maintenance-cli.php: папка выкладки по умолчанию — как у админки', function (): void {
    // Без PION_DEPLOY_HOME: dirname(корень сайта, 2) . '/pion-deploy'.
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $root = t_tmpdir();
    $webroot = "$root/www/pionperm.ru";
    mkdir($webroot, 0777, true);
    mkdir("$root/pion-deploy");
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $version = (string)(catalog_meta($db)['version'] ?? '');
    $db = null;
    $rel = "/images/catalog/bukety/buket-nezhnost-$uid-b1b1b1b1.webp";
    t_photo_file($webroot, $rel);
    file_put_contents("$home/photo-candidates.json", json_encode([$rel => time() - 3 * 86400]));
    [$code] = t_maintenance_run($scripts, $home, $webroot, null);
    t_true($code === 0 && is_file($webroot . $rel), 'в папке выкладки по умолчанию состояния нет — фото на месте');
    file_put_contents("$root/pion-deploy/state.json", json_encode(['current' => ['catalogVersion' => $version, 'catalogChangedAt' => '']]));
    [$code] = t_maintenance_run($scripts, $home, $webroot, null);
    t_true($code === 0 && !is_file($webroot . $rel), 'состояние лежит в <корень сайта>/../../pion-deploy — прочитано, фото в корзине');
});

t_case('maintenance-cli.php: предохранитель', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    $webroot = t_tmpdir();
    $deployHome = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $version = (string)(catalog_meta($db)['version'] ?? '');
    $db = null;
    // Всё выложено: без этого фото не были бы «к переносу», и предохранителю нечего считать.
    file_put_contents("$deployHome/state.json", json_encode(['current' => ['catalogVersion' => $version, 'catalogChangedAt' => '']]));
    $since = time() - 3 * 86400;
    $list = [];
    for ($i = 0; $i < 60; $i++) {
        $rel = sprintf("/images/catalog/bukety/buket-nezhnost-$uid-%08x.webp", $i);
        t_photo_file($webroot, $rel);
        $list[$rel] = $since;
    }
    file_put_contents("$home/photo-candidates.json", json_encode($list));
    [$code, $out] = t_maintenance_run($scripts, $home, $webroot, $deployHome);
    t_true($code === 1 && str_contains($out, 'Обслуживание не удалось: Уборка фото остановлена'), 'слишком много к переносу — сообщение и код 1');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия базы к этому времени уже сделана');
    t_equal(count(glob($webroot . '/images/catalog/bukety/*.webp') ?: []), 60, 'фото на месте');
    $done = t_maintenance_result($home);
    t_equal($done['ok'] ?? null, false, 'итог: ok: false');
    t_true(str_starts_with((string)($done['message'] ?? ''), 'Уборка фото остановлена') && !str_contains((string)$done['message'], "\n"), 'причина — первой строкой и своими словами, без приставок');
    t_equal(count(t_maintenance_log($home)), 1, 'в журнале одна строка');
});

t_case('итог обслуживания: причина сбоя — по-русски и в одну строку', function (): void {
    $own = new RuntimeException('Уборка фото остановлена: к переносу в корзину 60 фото — слишком много.');
    t_equal(maintenance_reason($own, 'Уборка фото'), 'Уборка фото остановлена: к переносу в корзину 60 фото — слишком много.', 'своё сообщение по-русски — без приставки');
    // RecursiveDirectoryIterator бросает UnexpectedValueException (это RuntimeException), текст по-английски.
    $iterator = new UnexpectedValueException('RecursiveDirectoryIterator::__construct(/var/www/images/catalog/_deleted): Failed to open directory: Permission denied');
    t_equal(
        maintenance_reason($iterator, 'Уборка фото'),
        'Уборка фото: RecursiveDirectoryIterator::__construct(/var/www/images/catalog/_deleted): Failed to open directory: Permission denied',
        'RuntimeException по классу, но по-английски — с приставкой шага',
    );
    t_equal(maintenance_reason(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'), 'Открытие базы'), 'Открытие базы: SQLSTATE[HY000]: General error: 5 database is locked', 'PDOException — с приставкой');
    t_equal(maintenance_reason(new Exception('Backup failed: 26, not an error'), 'Копия базы'), 'Копия базы: Backup failed: 26, not an error', 'Exception из SQLite — с приставкой');
    t_equal(maintenance_reason(new ValueError('strlen(): bad'), 'Уборка фото'), 'Уборка фото: strlen(): bad', 'Error — с приставкой');
    t_equal(maintenance_reason(new Exception('Не создать папку копий: /x'), 'Копия базы'), 'Не создать папку копий: /x', 'Exception, но сообщение по-русски — без приставки');
    t_equal(maintenance_reason(new Exception("disk\n  full\r\n\tat line 5"), 'Копия базы'), 'Копия базы: disk full at line 5', 'перевод строк и отступы схлопнуты в пробелы');
    $long = maintenance_reason(new RuntimeException(str_repeat('я', 400)), 'Уборка фото');
    t_equal([mb_strlen($long), mb_substr($long, -1), mb_substr($long, 0, 299) === str_repeat('я', 299)], [300, '…', true], 'длинная причина — ровно 300 знаков, на конце «…»');
    t_true(mb_check_encoding(maintenance_reason(new Exception("bad \xff byte"), 'Копия базы'), 'UTF-8'), 'битые байты в сообщении не ломают UTF-8');
    t_equal(maintenance_one_line(str_repeat('я', 300)), str_repeat('я', 300), 'ровно 300 знаков — без «…»');
});

t_case('итог обслуживания: запись в папку каталога', function (): void {
    $home = t_tmpdir();
    $before = time();
    t_equal(maintenance_record($home, true, 'Фото: ждут уборки 0.'), null, 'запись удалась — ошибки нет');
    $done = json_decode((string)file_get_contents("$home/maintenance.json"), true);
    t_equal([$done['ok'], $done['message']], [true, 'Фото: ждут уборки 0.'], 'ok и сообщение на месте');
    t_true((int)strtotime($done['at']) >= $before && (int)strtotime($done['at']) <= time(), '«at» — момент записи');
    t_equal(file("$home/maintenance.log", FILE_IGNORE_NEW_LINES), [$done['at'] . ' ok'], 'в журнале — "<время> ok"');

    maintenance_record($home, false, "Копия базы: диск\nполон");
    $done = json_decode((string)file_get_contents("$home/maintenance.json"), true);
    t_equal([$done['ok'], $done['message']], [false, 'Копия базы: диск полон'], 'сообщение приведено к одной строке');
    t_equal(count(file("$home/maintenance.log", FILE_IGNORE_NEW_LINES)), 2, 'в журнале две строки, а не три');
    t_equal(glob("$home/*.part") ?: [], [], 'временных файлов нет');

    $problem = maintenance_record("$home/нет-такой-папки", true, 'ok');
    t_true(is_string($problem) && str_contains($problem, 'Итог обслуживания не записан'), 'папки нет — ошибка текстом, не исключением');
});

t_case('maintenance-cli.php: причина с приставкой шага для чужой ошибки', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    file_put_contents("$home/catalog.sqlite", str_repeat("это не база SQLite\n", 300));
    t_maintenance_run($scripts, $home, t_tmpdir(), t_tmpdir());
    $message = (string)(t_maintenance_result($home)['message'] ?? '');
    t_true(str_starts_with($message, 'Копия базы: '), 'ошибку SQLite назвали по шагу: «Копия базы: …»');
});

/**
 * То же, что t_maintenance_run, но PHP запускается с лимитом памяти — чтобы
 * запуск оборвался фатальной ошибкой, мимо catch.
 *
 * @return array{0: int, 1: string} код выхода и вывод
 */
function t_maintenance_run_limited(string $scripts, string $home, string $webroot, string $deployHome, string $memoryLimit): array
{
    putenv('PION_CATALOG_HOME=' . $home);
    putenv('PION_WEBROOT=' . $webroot);
    putenv('PION_DEPLOY_HOME=' . $deployHome);
    try {
        exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $memoryLimit . ' ' . escapeshellarg("$scripts/catalog/maintenance-cli.php") . ' 2>&1', $out, $code);
    } finally {
        putenv('PION_CATALOG_HOME');
        putenv('PION_WEBROOT');
        putenv('PION_DEPLOY_HOME');
        clearstatcache();
    }
    return [$code, implode("\n", $out)];
}

t_case('maintenance-cli.php: оборванный фатальной ошибкой запуск оставляет итог', function (): void {
    // Список кандидатов в 48 МБ при лимите памяти 32 МБ: file_get_contents падает фатально, catch этого не видит.
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $list = fopen("$home/photo-candidates.json", 'wb');
    for ($i = 0; $i < 48; $i++) {
        fwrite($list, str_repeat('x', 1024 * 1024));
    }
    fclose($list);
    // Вчерашний удачный итог: без записи при обрыве сторож остался бы с ним.
    file_put_contents("$home/maintenance.json", json_encode(['ok' => true, 'at' => catalog_iso(t_now()), 'message' => 'вчера']));
    $started = time();
    [$code, $out] = t_maintenance_run_limited($scripts, $home, t_tmpdir(), t_tmpdir(), '32M');
    $finished = time();
    t_true($code !== 0 && str_contains($out, 'Allowed memory size'), 'запуск оборвался из-за памяти (' . $code . ')');
    $done = t_maintenance_result($home);
    t_equal($done['ok'] ?? null, false, 'в итоге ok: false вместо вчерашнего ok: true');
    $message = (string)($done['message'] ?? '');
    t_true(str_starts_with($message, 'Обслуживание оборвалось: ') && str_contains($message, 'Allowed memory size') && !str_contains($message, "\n"), 'причина — «Обслуживание оборвалось» и текст фатальной ошибки, одной строкой');
    $at = (int)strtotime((string)($done['at'] ?? ''));
    t_true($at >= $started && $at <= $finished, 'время — когда запуск оборвался');
    t_equal(t_maintenance_log($home), [($done['at'] ?? '') . ' ошибка: ' . $message], 'в журнале одна строка');
    t_equal(count(glob("$home/backups/catalog-*.sqlite") ?: []), 1, 'копия к этому времени уже была сделана');

    // Нормальный запуск после починки пишет итог один раз — обрыв не дописывается следом.
    unlink("$home/photo-candidates.json");
    [$code] = t_maintenance_run_limited($scripts, $home, t_tmpdir(), t_tmpdir(), '32M');
    t_equal([$code, t_maintenance_result($home)['ok'] ?? null, count(t_maintenance_log($home))], [0, true, 2], 'починили — ok: true, и ровно одна новая строка в журнале');
});
