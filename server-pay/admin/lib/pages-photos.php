<?php
/**
 * Приём фото (pay/admin/photo.php): один снимок за запрос, ответ — JSON с
 * путём к сохранённому WebP. Путь попадает в форму карточки и сохраняется
 * вместе с ней.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/products.php';

function admin_page_photo(array $req, array $ctx): array
{
    if ($req['method'] !== 'POST') {
        return admin_json(['ok' => false, 'error' => 'Фото принимаются только из формы.'], 405);
    }
    $file = $req['files']['photo'] ?? null;
    $bytes = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($file['tmp_name'] ?? null)
        ? (string)@file_get_contents($file['tmp_name'])
        : '';
    if ($bytes === '') {
        return admin_json(['ok' => false, 'error' => 'Фото не дошло — попробуйте ещё раз.'], 422);
    }
    try {
        $section = admin_str($req['post'], 'section');
        if ($section !== '') {
            $q = $ctx['db']->prepare('SELECT 1 FROM sections WHERE slug = ?');
            $q->execute([$section]);
            if ($q->fetchColumn() === false) {
                throw new CatalogError('Такого раздела нет — обновите страницу.');
            }
            $kind = admin_str($req['post'], 'kind') === 'cover' ? 'cover' : 'tile';
            $path = admin_store_section_photo($ctx['webroot'], $section, $kind, $bytes);
        } else {
            $product = catalog_product_row($ctx['db'], admin_str($req['post'], 'uid'));
            if ($product['status'] === 'deleted') {
                throw new CatalogError('Букет удалён — сначала восстановите его.');
            }
            $path = admin_store_product_photo($ctx['webroot'], $product, $bytes);
        }
        return admin_json(['ok' => true, 'path' => $path]);
    } catch (CatalogError $e) {
        return admin_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}
