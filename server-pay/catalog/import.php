<?php
/**
 * Загрузка выгрузки в пустую базу: перенос нынешнего каталога (этап 3) и
 * проверка «туда-обратно» — выгрузка из загруженной базы даёт ту же версию.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/export.php';

/** @return array{sections: int, products: int, redirects: int} */
function catalog_import(PDO $db, array $export, DateTimeImmutable $now): array
{
    return catalog_tx($db, function () use ($db, $export, $now): array {
        foreach (['sections', 'tiles', 'products', 'redirects'] as $part) {
            if (!is_array($export[$part] ?? null)) {
                throw new CatalogError("В выгрузке нет части $part.");
            }
        }
        if (isset($export['version']) && $export['version'] !== catalog_export_version($export)) {
            throw new CatalogError('Выгрузка повреждена: версия не сходится с содержимым.');
        }
        if ((int)$db->query('SELECT (SELECT COUNT(*) FROM sections) + (SELECT COUNT(*) FROM products)')->fetchColumn() > 0) {
            throw new CatalogError('База не пустая — загружать можно только в пустую.');
        }
        $at = catalog_iso($now);

        $inGrid = [];
        foreach ($export['tiles'] as $tile) {
            if ($tile['type'] === 'section') {
                $inGrid[] = $tile['slug'];
            }
        }
        $addSection = $db->prepare('INSERT INTO sections (slug, label, tile_image, visible, cover_title, cover_sub,
                covers, heading, heading_sub, has_not_found, seo_title, seo_description, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $members = [];
        foreach ($export['sections'] as $s) {
            if ((bool)$s['visible'] !== in_array($s['slug'], $inGrid, true)) {
                throw new CatalogError("Раздел {$s['slug']}: видимость не совпадает с плитками сетки.");
            }
            $addSection->execute([
                $s['slug'], $s['label'], $s['tileImage'], $s['visible'] ? 1 : 0, $s['coverTitle'], $s['coverSub'],
                json_encode($s['covers'], CATALOG_JSON), $s['heading'], $s['headingSub'], $s['hasNotFound'] ? 1 : 0,
                $s['seoTitle'], $s['seoDescription'], $at,
            ]);
            foreach ($s['products'] as $position => $uid) {
                $members[] = [$uid, $s['slug'], $position];
            }
        }

        $addTile = $db->prepare('INSERT INTO tiles (position, type, section, label, href, image) VALUES (?, ?, ?, ?, ?, ?)');
        $position = 0;
        foreach ($export['tiles'] as $t) {
            $position++;
            if ($t['type'] === 'section') {
                $addTile->execute([$position, 'section', $t['slug'], '', '', '']);
            } else {
                $addTile->execute([$position, $t['type'], null, $t['label'], $t['href'], $t['image']]);
            }
        }
        // Скрытых разделов в сетке нет — их плитки встают в конец: покажут раздел — плитка появится там.
        foreach ($export['sections'] as $s) {
            if (!$s['visible']) {
                $addTile->execute([++$position, 'section', $s['slug'], '', '', '']);
            }
        }

        $listedIn = [];
        foreach ($members as [$uid, $section]) {
            $listedIn[$uid][] = $section;
        }
        $addProduct = $db->prepare("INSERT INTO products (uid, slug, slug_pinned, title, description, price, images,
                main_section, status, status_changed_at, created_at, updated_at, updated_by, published_at)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?)");
        foreach ($export['products'] as $p) {
            if (!in_array($p['status'], ['active', 'hidden'], true)) {
                throw new CatalogError("Букет {$p['uid']}: в выгрузке не может быть статуса {$p['status']}.");
            }
            if (!in_array($p['mainSection'], $listedIn[$p['uid']] ?? [], true)) {
                throw new CatalogError("Букет {$p['uid']}: его нет в его главном разделе {$p['mainSection']}.");
            }
            $addProduct->execute([
                $p['uid'], $p['slug'], $p['title'], $p['description'], $p['price'],
                json_encode($p['images'], CATALOG_JSON), $p['mainSection'], $p['status'], $at, $at, $at, $at,
            ]);
        }
        $addMember = $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)');
        foreach ($members as $member) {
            $addMember->execute($member);
        }
        $addRedirect = $db->prepare('INSERT INTO redirects (from_path, to_path, created_at) VALUES (?, ?, ?)');
        foreach ($export['redirects'] as $r) {
            $addRedirect->execute([$r['from'], $r['to'], $at]);
        }

        catalog_audit($db, 'import', $now, 'catalog', '', 'imported', null, count($export['products']) . ' букетов');
        catalog_touch($db, $now);
        return [
            'sections' => count($export['sections']),
            'products' => count($export['products']),
            'redirects' => count($export['redirects']),
        ];
    });
}
