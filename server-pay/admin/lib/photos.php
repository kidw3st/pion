<?php
/**
 * Фото букетов и разделов. Браузер присылает JPEG до 2000 px (assets/photo.js);
 * сервер открывает его через GD — не открылся как картинка, значит не фото, —
 * уменьшает по ширине и сохраняет WebP качества 82, как все фото каталога.
 * От присланного файла остаётся только картинка: ни EXIF, ни чужих данных.
 *
 * Новое фото — новое имя (8 знаков хэша содержимого): фото на сайте
 * кэшируются на 30 дней, и под старым именем браузеры показывали бы прежний снимок.
 */

declare(strict_types=1);

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/../../catalog/db.php';

const ADMIN_PHOTO_MAX_BYTES = 20971520;
const ADMIN_PHOTO_WIDTH = 900;
const ADMIN_COVER_WIDTH = 1600;
const ADMIN_WEBP_QUALITY = 82;

function admin_photo_webp(string $bytes, int $maxWidth): string
{
    if ($bytes === '' || strlen($bytes) > ADMIN_PHOTO_MAX_BYTES) {
        throw new CatalogError('Фото пустое или слишком большое — попробуйте другое.');
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        throw new CatalogError('Файл не открылся как изображение — пришлите фото в JPEG или PNG.');
    }
    $width = imagesx($image);
    if ($width > $maxWidth) {
        $scaled = imagescale($image, $maxWidth, (int)round(imagesy($image) * $maxWidth / $width), IMG_BICUBIC);
        if ($scaled === false) {
            throw new RuntimeException('Не удалось уменьшить фото');
        }
        $image = $scaled;
    }
    // GIF и PNG с палитрой: WebP пишется только из полноцветной картинки.
    imagepalettetotruecolor($image);
    ob_start();
    $ok = imagewebp($image, null, ADMIN_WEBP_QUALITY);
    $webp = (string)ob_get_clean();
    if (!$ok || $webp === '') {
        throw new RuntimeException('Не удалось сохранить WebP');
    }
    return $webp;
}

/** Файл пишется во временный и получает своё имя только целым. */
function admin_write_file(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку: $dir");
    }
    $part = $path . '.part';
    if (file_put_contents($part, $bytes) !== strlen($bytes) || !rename($part, $path)) {
        if (is_file($part)) {
            unlink($part);
        }
        throw new RuntimeException("Не записать файл: $path");
    }
}

function admin_store_product_photo(string $webroot, array $product, string $bytes): string
{
    $webp = admin_photo_webp($bytes, ADMIN_PHOTO_WIDTH);
    $rel = '/images/catalog/' . $product['main_section'] . '/' . $product['slug'] . '-' . $product['uid']
        . '-' . substr(hash('sha256', $webp), 0, 8) . '.webp';
    admin_write_file($webroot . $rel, $webp);
    return $rel;
}

function admin_store_section_photo(string $webroot, string $slug, string $kind, string $bytes): string
{
    $webp = admin_photo_webp($bytes, $kind === 'cover' ? ADMIN_COVER_WIDTH : ADMIN_PHOTO_WIDTH);
    $rel = '/images/catalog/_sections/' . $slug . '-' . $kind . '-' . substr(hash('sha256', $webp), 0, 8) . '.webp';
    admin_write_file($webroot . $rel, $webp);
    return $rel;
}

/**
 * Фото в форме: превью, скрытые поля с путями (их и сохраняет форма),
 * кнопки «←», «→», «Убрать» и выбор файлов. Без JavaScript фото видны и
 * сохраняются как есть; загрузка и перестановка — через assets/photo.js.
 *
 * @param array<string, string> $data data-* для запроса загрузки: uid или section и kind
 */
function admin_photo_widget(array $user, string $name, array $paths, int $limit, array $data): string
{
    $attrs = '';
    foreach ($data as $key => $value) {
        $attrs .= ' data-' . h($key) . '="' . h($value) . '"';
    }
    $items = '';
    foreach ($paths as $path) {
        $items .= '<li><img src="' . h($path) . '" alt=""><input type="hidden" name="' . h($name) . '" value="' . h($path) . '">'
            . '<button type="button" class="btn-small" data-act="left">←</button>'
            . '<button type="button" class="btn-small" data-act="right">→</button>'
            . '<button type="button" class="btn-small" data-act="remove">Убрать</button></li>';
    }
    return '<div class="photos" data-photo-upload data-endpoint="' . ADMIN_BASE . 'photo.php" data-csrf="' . h($user['csrf']) . '"'
        . ' data-name="' . h($name) . '" data-limit="' . $limit . '"' . $attrs . '>'
        . '<ul data-photo-list>' . $items . '</ul>'
        . '<label class="upload">Добавить фото<input type="file" accept="image/*" multiple></label>'
        . '<p class="hint" data-photo-message></p></div>';
}
