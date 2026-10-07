<?php
/**
 * Корзина фото каталога: images/catalog/_deleted/ — с тем же путём внутри.
 * Туда фото уносит ежедневная уборка (maintenance.php), оттуда их
 * возвращает восстановление букета. Через 90 дней корзина стирает их насовсем.
 */

declare(strict_types=1);

const CATALOG_TRASH_DIR = '/images/catalog/_deleted';

/** /images/catalog/bukety/x.webp → /images/catalog/_deleted/bukety/x.webp; не фото каталога — null. */
function catalog_photo_trash_path(string $rel): ?string
{
    if (!preg_match('~^/images/catalog/(?!_deleted/)([A-Za-z0-9_-]+/[A-Za-z0-9._-]+\.webp)\z~', $rel, $m) || str_contains($rel, '..')) {
        return null;
    }
    return CATALOG_TRASH_DIR . '/' . $m[1];
}

/** Убрать фото в корзину; время файла — момент переноса (по нему корзина и чистится). */
function catalog_photo_trash(string $webroot, string $rel, DateTimeImmutable $now): bool
{
    $trash = catalog_photo_trash_path($rel);
    if ($trash === null || !is_file($webroot . $rel)) {
        return false;
    }
    $to = $webroot . $trash;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
        return false;
    }
    if (!rename($webroot . $rel, $to)) {
        return false;
    }
    touch($to, $now->getTimestamp());
    return true;
}

/** Вернуть фото из корзины на место — если оно там и место свободно. */
function catalog_photo_untrash(string $webroot, string $rel): bool
{
    $trash = catalog_photo_trash_path($rel);
    if ($trash === null || !is_file($webroot . $trash) || is_file($webroot . $rel)) {
        return false;
    }
    $to = $webroot . $rel;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true) && !is_dir(dirname($to))) {
        return false;
    }
    return rename($webroot . $trash, $to);
}
