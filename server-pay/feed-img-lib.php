<?php
/**
 * JPG-копии фото каталога для товарного фида (2ГИС, Яндекс Карты).
 *
 * 2ГИС принимает фото только в JPG, PNG или GIF, а фото каталога лежат в WebP.
 * Копии делаются здесь, на сервере, при первом обращении и дальше отдаются
 * как обычные файлы: так они не раздувают архив сборки и подхватывают фото,
 * которые позже будет загружать админка.
 */

declare(strict_types=1);

const FEED_IMG_QUALITY = 85;

/**
 * Путь к JPG-копии фото /images/catalog/<раздел>/<имя>.webp; при первом
 * обращении копия создаётся. null — такого фото нет или адрес чужой.
 */
function feed_img_serve(string $webroot, string $section, string $file): ?string
{
    // Только имена из одной папки — никаких «..» и чужих путей.
    if (preg_match('/^[a-z0-9-]+$/', $section) !== 1 || preg_match('/^[a-z0-9-]+$/', $file) !== 1) {
        return null;
    }
    $jpg = "$webroot/feed/img/$section/$file.jpg";
    if (is_file($jpg)) {
        return $jpg;
    }
    $webp = "$webroot/images/catalog/$section/$file.webp";
    if (!is_file($webp)) {
        return null;
    }
    $photo = @imagecreatefromwebp($webp);
    if ($photo === false) {
        return null;
    }
    // У JPG нет прозрачности: прозрачные места фото становятся белыми.
    $width = imagesx($photo);
    $height = imagesy($photo);
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $photo, 0, 0, 0, 0, $width, $height);
    imagedestroy($photo);

    $dir = dirname($jpg);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($canvas);
        return null;
    }
    // Пишем рядом и переименовываем: робот не получит недописанный файл.
    $part = $jpg . '.part';
    $saved = imagejpeg($canvas, $part, FEED_IMG_QUALITY) && rename($part, $jpg);
    imagedestroy($canvas);
    if (!$saved) {
        @unlink($part);
        return null;
    }
    return $jpg;
}
