<?php
/**
 * Отдаёт JPG-копию фото каталога для товарного фида: /feed/img/<раздел>/<имя>.jpg.
 *
 * Сюда запрос приходит правилом из .htaccess сайта, только пока копии ещё
 * нет; дальше готовый файл отдаёт сам Apache. Логика — в feed-img-lib.php.
 */

declare(strict_types=1);

require __DIR__ . '/feed-img-lib.php';

$section = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$file = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

$jpg = feed_img_serve(dirname(__DIR__), $section, $file);
if ($jpg === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($jpg));
header('Cache-Control: public, max-age=2592000');
readfile($jpg);
