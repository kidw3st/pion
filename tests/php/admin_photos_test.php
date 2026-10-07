<?php
/**
 * Фото: пересохранение в WebP, имена файлов, приём через photo.php, виджет в
 * карточке, корзина и возврат фото при восстановлении букета.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-photos.php';
require_once __DIR__ . '/../../server-pay/catalog/sections.php';

function t_photo_call(array $ctx, array $post, array $files): array
{
    return t_admin_call($ctx, 'photo', 'admin_page_photo', 'POST', post: $post, files: $files);
}

t_case('пересохранение в WebP', function (): void {
    $webp = admin_photo_webp(t_jpeg(2400, 1200), ADMIN_PHOTO_WIDTH);
    t_true(substr($webp, 0, 4) === 'RIFF' && substr($webp, 8, 4) === 'WEBP', 'на выходе WebP');
    $image = imagecreatefromstring($webp);
    t_equal([imagesx($image), imagesy($image)], [900, 450], 'уменьшено до 900 px по ширине, пропорции те же');
    $small = imagecreatefromstring(admin_photo_webp(t_jpeg(600, 800), ADMIN_PHOTO_WIDTH));
    t_equal([imagesx($small), imagesy($small)], [600, 800], 'маленькое фото не растягивается');
    t_throws(fn () => admin_photo_webp('это не картинка', ADMIN_PHOTO_WIDTH), CatalogError::class, 'не картинка — отказ');
    t_throws(fn () => admin_photo_webp('', ADMIN_PHOTO_WIDTH), CatalogError::class, 'пустой файл — отказ');
});

/** Только заголовок PNG с заявленными размерами: GD картинку не откроет, а размеры прочитать можно. */
function t_png_header(int $width, int $height): string
{
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";
}

t_case('слишком большие и вытянутые снимки', function (): void {
    $big = t_throws(fn () => admin_photo_webp(t_png_header(20000, 20000), ADMIN_PHOTO_WIDTH), CatalogError::class, '400 мегапикселей — отказ');
    t_true($big !== null && str_contains($big->getMessage(), 'слишком большой'), 'отказ до открытия снимка и с понятным объяснением');
    $tall = t_throws(fn () => admin_photo_webp(t_png_header(900, 20000), ADMIN_PHOTO_WIDTH), CatalogError::class, 'выше предела WebP — отказ');
    t_true($tall !== null && str_contains($tall->getMessage(), 'слишком вытянутый'), 'про вытянутый снимок говорим отдельно');
    $real = t_throws(fn () => admin_photo_webp(t_jpeg(100, 17000), ADMIN_PHOTO_WIDTH), CatalogError::class, 'настоящий узкий снимок выше 16383 px — отказ');
    t_true($real !== null && str_contains($real->getMessage(), 'слишком вытянутый'), 'плохой файл вместо него не появляется');
    // Снимок меньше 40 мегапикселей, но в память при нынешнем лимите не влезет — отказ, а не фатальная ошибка PHP.
    $limit = (string)ini_get('memory_limit');
    ini_set('memory_limit', (string)(memory_get_usage() + 40 * 1024 * 1024));
    $heavy = t_throws(fn () => admin_photo_webp(t_png_header(6000, 6000), ADMIN_PHOTO_WIDTH), CatalogError::class, 'не хватит памяти — отказ');
    ini_set('memory_limit', $limit);
    t_true($heavy !== null && str_contains($heavy->getMessage(), 'слишком большой'), 'и это понятно сказано');
    $thin = imagecreatefromstring(admin_photo_webp(t_jpeg(20000, 5), ADMIN_PHOTO_WIDTH));
    t_equal([imagesx($thin), imagesy($thin)], [900, 1], 'очень тонкая полоса: высота не обнуляется');
});

t_case('запись файла', function (): void {
    $dir = t_tmpdir();
    admin_write_file($dir . '/a/b.webp', 'x');
    admin_write_file($dir . '/a/b.webp', 'x');
    t_equal(array_map('basename', glob($dir . '/a/*') ?: []), ['b.webp'], 'временных файлов не остаётся');
    // Временное имя не фиксированное: одинаковые снимки, присланные разом, не пишут в один и тот же файл.
    mkdir($dir . '/c');
    mkdir($dir . '/c/d.webp.part');
    admin_write_file($dir . '/c/d.webp', 'y');
    t_equal(file_get_contents($dir . '/c/d.webp'), 'y', 'занятое имя «.part» записи не мешает');
});

t_case('фото букета на диске', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $p = t_row($ctx['db'], $uid);
    $path = admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 1000));
    t_true((bool)preg_match('~^/images/catalog/bukety/buket-nezhnost-' . $uid . '-[0-9a-f]{8}\.webp$~', $path), 'имя: slug, uid и 8 знаков хэша; папка главного раздела');
    t_true(is_file($ctx['webroot'] . $path), 'файл записан');
    t_true((bool)preg_match(CATALOG_IMAGE_PATH, $path), 'путь годится для карточки букета');
    t_equal(admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 1000)), $path, 'то же фото — то же имя');
    t_true(admin_store_product_photo($ctx['webroot'], $p, t_jpeg(1000, 999)) !== $path, 'другое фото — другое имя: кэш браузеров не покажет старое');
});

t_case('приём фото', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(), t_now());
    $r = t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload(t_jpeg(1200, 1600))]);
    $data = json_decode($r['body'], true);
    t_true($r['status'] === 200 && $data['ok'] === true && is_file($ctx['webroot'] . $data['path']), 'фото принято, путь вернулся');
    t_equal($r['headers']['Content-Type'], 'application/json; charset=utf-8', 'ответ — JSON');
    $bad = json_decode(t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload('не картинка')])['body'], true);
    t_true($bad['ok'] === false && str_contains($bad['error'], 'не открылся как изображение'), 'не картинка — понятная ошибка');
    $none = json_decode(t_photo_call($ctx, ['uid' => $uid], [])['body'], true);
    t_equal($none['ok'], false, 'без файла — ошибка');
    $anon = admin_handle(admin_request('POST', post: ['uid' => $uid], files: ['photo' => t_upload(t_jpeg(10, 10))]), $ctx, 'photo', 'admin_page_photo');
    t_equal($anon['status'], 303, 'без входа фото не принимается');
    catalog_publish($ctx['db'], 'anna', $uid, 1, t_now());
    catalog_delete($ctx['db'], 'anna', $uid, 2, t_now());
    t_equal(json_decode(t_photo_call($ctx, ['uid' => $uid], ['photo' => t_upload(t_jpeg(10, 10))])['body'], true)['ok'], false, 'удалённому букету фото не добавить');
});

t_case('снимок тяжелее предела загрузки', function (): void {
    // Если файл больше upload_max_filesize, PHP не кладёт его на диск и возвращает только код ошибки.
    // Сотрудница должна услышать про вес снимка, а не «не дошло — попробуйте ещё раз»: повтор не поможет.
    $ctx = t_admin_ctx();
    $failed = static fn (int $code): array => ['name' => 'photo.jpg', 'type' => '', 'tmp_name' => '', 'error' => $code, 'size' => 0];
    foreach (['INI_SIZE' => UPLOAD_ERR_INI_SIZE, 'FORM_SIZE' => UPLOAD_ERR_FORM_SIZE] as $name => $code) {
        $r = t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => $failed($code)]);
        $data = json_decode($r['body'], true);
        t_equal(
            [$r['status'], $data['ok'] ?? null, $data['error'] ?? null],
            [422, false, 'Снимок слишком тяжёлый — выберите другой или уменьшите его.'],
            "UPLOAD_ERR_$name: ответ про вес снимка",
        );
    }
    // Остальные сбои передачи по-прежнему «не дошло»: тут повтор может помочь.
    $partial = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => $failed(UPLOAD_ERR_PARTIAL)])['body'], true);
    t_equal($partial['error'] ?? null, 'Фото не дошло — попробуйте ещё раз.', 'оборванная передача — прежнее сообщение');
});

t_case('сбои при приёме фото', function (): void {
    $ctx = t_admin_ctx();
    $big = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => t_upload(t_png_header(20000, 20000))])['body'], true);
    t_true($big['ok'] === false && str_contains($big['error'], 'слишком большой'), 'огромный снимок — понятная ошибка, а не 500');

    // На месте папки разделов лежит файл — папку не создать. Подробности уходят в журнал сервера, не в ответ.
    mkdir($ctx['webroot'] . '/images/catalog', 0777, true);
    file_put_contents($ctx['webroot'] . '/images/catalog/_sections', 'занято');
    $log = t_tmpdir() . '/php-errors.log';
    $before = ini_set('error_log', $log);
    $r = t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => t_upload(t_jpeg(300, 300))]);
    ini_set('error_log', (string)$before);
    $data = json_decode($r['body'], true);
    t_equal($r['status'], 500, 'сбой записи — ответ 500');
    t_equal($r['headers']['Content-Type'], 'application/json; charset=utf-8', 'но тоже JSON: страница покажет текст, а не «сессия закончилась»');
    t_true(is_array($data) && $data['ok'] === false && str_contains($data['error'], 'Не получилось сохранить фото'), 'по-русски и без технических слов');
    t_true(!str_contains($r['body'], $ctx['webroot']) && !str_contains($r['body'], 'RuntimeException') && !str_contains($r['body'], '#0'), 'в ответе нет путей и следа ошибки');
    t_true(str_contains((string)@file_get_contents($log), 'RuntimeException'), 'подробности записаны в журнал сервера');
    t_equal(glob($ctx['webroot'] . '/images/catalog/*.part') ?: [], [], 'недописанных файлов нет');
});

t_case('фото раздела', function (): void {
    $ctx = t_admin_ctx();
    $cover = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'cover'], ['photo' => t_upload(t_jpeg(3000, 1200))])['body'], true);
    t_true($cover['ok'] && str_starts_with($cover['path'], '/images/catalog/_sections/roses-cover-') && preg_match(CATALOG_SITE_IMAGE, $cover['path']) === 1, 'обложка раздела');
    t_equal(imagesx(imagecreatefromstring((string)file_get_contents($ctx['webroot'] . $cover['path']))), 1600, 'обложка — до 1600 px, как нынешние');
    $tile = json_decode(t_photo_call($ctx, ['section' => 'roses', 'kind' => 'tile'], ['photo' => t_upload(t_jpeg(3000, 3000))])['body'], true);
    t_equal(imagesx(imagecreatefromstring((string)file_get_contents($ctx['webroot'] . $tile['path']))), 900, 'плитка — до 900 px');
    t_equal(json_decode(t_photo_call($ctx, ['section' => 'nope', 'kind' => 'tile'], ['photo' => t_upload(t_jpeg(10, 10))])['body'], true)['ok'], false, 'нет такого раздела');
});

t_case('фото в карточке', function (): void {
    $ctx = t_admin_ctx();
    $uid = catalog_create_product($ctx['db'], 'anna', t_fields(['images' => ['/images/catalog/bukety/a.webp']]), t_now());
    $card = t_admin_call($ctx, 'product', 'admin_page_product', query: ['uid' => $uid])['body'];
    t_true(str_contains($card, 'data-photo-upload') && str_contains($card, 'data-uid="' . $uid . '"') && str_contains($card, 'data-limit="4"'), 'загрузка фото подключена');
    t_true(str_contains($card, 'name="images[]" value="/images/catalog/bukety/a.webp"') && str_contains($card, 'data-act="remove"'), 'фото можно убрать и переставить');
});

t_case('корзина фото и восстановление букета', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $uid = catalog_create_product($db, 'anna', t_fields(), t_now());
    $path = admin_store_product_photo($ctx['webroot'], t_row($db, $uid), t_jpeg(100, 100));
    catalog_update_product($db, 'anna', $uid, 1, t_fields(['images' => [$path]]), t_now());
    catalog_publish($db, 'anna', $uid, 2, t_now());
    catalog_delete($db, 'anna', $uid, 3, t_now());
    t_true(catalog_photo_trash($ctx['webroot'], $path, t_now()), 'фото удалённого убрано в корзину');
    t_true(is_file($ctx['webroot'] . '/images/catalog/_deleted/bukety/' . basename($path)) && !is_file($ctx['webroot'] . $path), 'лежит в _deleted под тем же путём');
    t_equal(filemtime($ctx['webroot'] . '/images/catalog/_deleted/bukety/' . basename($path)), t_now()->getTimestamp(), 'время файла — момент переноса: по нему корзина чистится');
    $r = t_admin_call($ctx, 'product', 'admin_page_product', 'POST', post: ['action' => 'restore', 'uid' => $uid, 'version' => '4']);
    t_equal($r['status'], 303, 'букет восстановлен');
    t_true(is_file($ctx['webroot'] . $path), 'и фото вернулось на место');
    t_equal(catalog_photo_trash($ctx['webroot'], '/images/../pay/config.php', t_now()), false, 'за пределы фото каталога корзина не ходит');
    t_equal(catalog_photo_untrash($ctx['webroot'], '/images/catalog/_deleted/x/y.webp'), false, 'и из самой корзины — тоже');
});
