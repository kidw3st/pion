<?php
/**
 * Чтение каталога для экранов админки. Только SELECT — правила изменений
 * живут в catalog/products.php и catalog/sections.php.
 */

declare(strict_types=1);

/** Разделы в порядке сетки каталога; у каждого раздела есть плитка, у скрытого visible = 0. */
function admin_sections(PDO $db): array
{
    return $db->query('SELECT s.slug, s.label, s.visible FROM sections s LEFT JOIN tiles t ON t.section = s.slug
        ORDER BY t.position IS NULL, t.position, s.slug')->fetchAll();
}

/** Все букеты для списка: с фото и разделами, последние изменённые — сверху. */
function admin_products(PDO $db): array
{
    $members = [];
    foreach ($db->query('SELECT uid, section FROM product_sections ORDER BY section') as $m) {
        $members[$m['uid']][] = $m['section'];
    }
    $rows = $db->query('SELECT uid, slug, title, price, images, main_section, status, updated_at FROM products
        ORDER BY updated_at DESC, uid')->fetchAll();
    foreach ($rows as &$row) {
        $row['images'] = json_decode($row['images'], true) ?: [];
        $row['sections'] = $members[$row['uid']] ?? [];
    }
    return $rows;
}
