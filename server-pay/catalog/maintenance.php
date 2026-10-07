<?php
/**
 * Ежедневное обслуживание каталога: копия базы и уборка фото.
 *
 * Фото, на которые больше не ссылается ни один букет (кроме удалённых) и ни
 * один раздел, — заменили, убрали из карточки, букет удалён, — переезжают в
 * корзину images/catalog/_deleted/. Но не сразу: сначала фото становится
 * кандидатом, и только если через сутки ссылок на него так и нет, уходит —
 * до выкладки старая страница на сайте ещё показывает его. Корзина стирает
 * фото через 90 дней. Файлы, хозяина которых база не знает (uid букета ни в
 * базе, ни в журнале, раздела из имени нет), не трогаются никогда.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/photo-files.php';

/** Сколько фото должно пробыть без ссылок, прежде чем уйти в корзину. */
const CATALOG_PHOTO_GRACE = 86400;
const CATALOG_TRASH_DAYS = 90;

function catalog_photos_referenced(PDO $db): array
{
    $paths = [];
    foreach ($db->query("SELECT images FROM products WHERE status <> 'deleted'") as $row) {
        foreach (json_decode($row['images'], true) ?: [] as $path) {
            $paths[$path] = true;
        }
    }
    foreach ($db->query('SELECT tile_image, covers FROM sections') as $row) {
        if ($row['tile_image'] !== '') {
            $paths[$row['tile_image']] = true;
        }
        foreach (json_decode($row['covers'], true) ?: [] as $path) {
            $paths[$path] = true;
        }
    }
    return $paths;
}

/**
 * Знает ли база хозяина файла: раздел — для фото из _sections, букет по uid из
 * имени — для остальных. Букет известен, если он есть в products или его uid
 * есть в журнале: черновик, удалённый совсем, строку в products теряет, но
 * журнал о нём помнит — иначе его фото остались бы в папке навсегда. Фото
 * каталога до переноса в базу (uid в журнале не появлялся) по-прежнему чужие.
 */
function catalog_photo_owner_known(PDO $db, string $dir, string $name): bool
{
    if ($dir === '_sections') {
        if (!preg_match('/^(.+)-(?:tile|cover)-[0-9a-f]{8}\.webp$/', $name, $m)) {
            return false;
        }
        $q = $db->prepare('SELECT 1 FROM sections WHERE slug = ?');
        $q->execute([$m[1]]);
        return $q->fetchColumn() !== false;
    }
    if (!preg_match('/-(\d{12})(?:-\d+|-[0-9a-f]{8})?\.webp$/', $name, $m)) {
        return false;
    }
    $q = $db->prepare('SELECT 1 FROM products WHERE uid = ?');
    $q->execute([$m[1]]);
    if ($q->fetchColumn() !== false) {
        return true;
    }
    $q = $db->prepare("SELECT 1 FROM audit WHERE object_type = 'product' AND object_id = ? LIMIT 1");
    $q->execute([$m[1]]);
    return $q->fetchColumn() !== false;
}

/** @return array{candidates: int, moved: int, purged: int} */
function catalog_photos_sweep(PDO $db, string $webroot, string $candidatesFile, DateTimeImmutable $now): array
{
    $time = $now->getTimestamp();
    $referenced = catalog_photos_referenced($db);
    $previous = json_decode((string)@file_get_contents($candidatesFile), true);
    $previous = is_array($previous) ? $previous : [];
    $candidates = [];
    $moved = 0;
    foreach (glob($webroot . '/images/catalog/*', GLOB_ONLYDIR) ?: [] as $dirPath) {
        $dir = basename($dirPath);
        if ($dir === '_deleted') {
            continue;
        }
        foreach (glob($dirPath . '/*.webp') ?: [] as $file) {
            $name = basename($file);
            $rel = '/images/catalog/' . $dir . '/' . $name;
            if (isset($referenced[$rel]) || !catalog_photo_owner_known($db, $dir, $name)) {
                continue;
            }
            $since = is_int($previous[$rel] ?? null) ? $previous[$rel] : $time;
            if ($time - $since >= CATALOG_PHOTO_GRACE && catalog_photo_trash($webroot, $rel, $now)) {
                $moved++;
                continue;
            }
            $candidates[$rel] = $since;
        }
    }
    $old = [];
    $trash = $webroot . CATALOG_TRASH_DIR;
    if (is_dir($trash)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getMTime() < $time - CATALOG_TRASH_DAYS * 86400) {
                $old[] = $f->getPathname();
            }
        }
    }
    $purged = 0;
    foreach ($old as $path) {
        if (unlink($path)) {
            $purged++;
        }
    }
    catalog_photo_candidates_save($candidatesFile, $candidates);
    return ['candidates' => count($candidates), 'moved' => $moved, 'purged' => $purged];
}

/**
 * Список кандидатов пишется во временный файл и получает своё имя только
 * целым: запуск, прерванный на полуслове, не оставит обрезанный JSON — иначе
 * все кандидаты стали бы «новыми» и уборка отложилась бы ещё на сутки.
 */
function catalog_photo_candidates_save(string $file, array $candidates): void
{
    $part = $file . '.part';
    $json = json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents($part, $json) !== strlen($json) || !@rename($part, $file)) {
        if (is_file($part)) {
            unlink($part);
        }
        throw new RuntimeException("Не записать список фото на уборку: $file");
    }
}
