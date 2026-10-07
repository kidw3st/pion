<?php
/**
 * Выгрузка каталога для сборки сайта — формат из спецификации, раздел 3.
 * В ней только то, что и так окажется на страницах: без черновиков,
 * удалённых букетов, пользователей и журнала.
 *
 * Версия — sha256 канонического JSON без version и changedAt: одинаковый
 * каталог всегда даёт одинаковую версию, и GitHub Actions по ней понимает,
 * пора ли собирать сайт. changedAt — когда выгрузка менялась последний раз;
 * его ставит catalog_touch() в конце каждого сохранения.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** JSON выгрузки: кириллица и слэши как есть — так же пишет JSON.stringify на стороне сборки. */
const CATALOG_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

function catalog_export_data(PDO $db): array
{
    $members = [];
    $rows = $db->query("SELECT ps.section, ps.uid FROM product_sections ps
        JOIN products p ON p.uid = ps.uid
        WHERE p.status IN ('active', 'hidden')
        ORDER BY ps.section, ps.position, ps.uid");
    foreach ($rows as $row) {
        $members[$row['section']][] = $row['uid'];
    }

    $sections = [];
    foreach ($db->query('SELECT * FROM sections ORDER BY slug') as $s) {
        $sections[] = [
            'slug' => $s['slug'],
            'label' => $s['label'],
            'tileImage' => $s['tile_image'],
            'visible' => (bool)$s['visible'],
            'coverTitle' => $s['cover_title'],
            'coverSub' => $s['cover_sub'],
            'covers' => json_decode($s['covers'], true, 8, JSON_THROW_ON_ERROR),
            'heading' => $s['heading'],
            'headingSub' => $s['heading_sub'],
            'hasNotFound' => (bool)$s['has_not_found'],
            'seoTitle' => $s['seo_title'],
            'seoDescription' => $s['seo_description'],
            // Все букеты раздела в его порядке, включая снятые: вернувшийся
            // в продажу встанет на своё место.
            'products' => $members[$s['slug']] ?? [],
        ];
    }

    $tiles = [];
    $rows = $db->query('SELECT t.*, s.visible FROM tiles t LEFT JOIN sections s ON s.slug = t.section ORDER BY t.position, t.id');
    foreach ($rows as $t) {
        if ($t['type'] !== 'section') {
            $tiles[] = ['type' => $t['type'], 'label' => $t['label'], 'href' => $t['href'], 'image' => $t['image']];
        } elseif ($t['visible']) {
            $tiles[] = ['type' => 'section', 'slug' => $t['section']];
        }
    }

    $products = [];
    foreach ($db->query("SELECT * FROM products WHERE status IN ('active', 'hidden') ORDER BY uid") as $p) {
        $products[] = [
            'uid' => $p['uid'],
            'slug' => $p['slug'],
            'title' => $p['title'],
            'description' => $p['description'],
            'price' => (int)$p['price'],
            'images' => json_decode($p['images'], true, 8, JSON_THROW_ON_ERROR),
            'mainSection' => $p['main_section'],
            'status' => $p['status'],
        ];
    }

    $redirects = [];
    foreach ($db->query('SELECT from_path, to_path FROM redirects ORDER BY from_path') as $r) {
        $redirects[] = ['from' => $r['from_path'], 'to' => $r['to_path']];
    }

    return ['sections' => $sections, 'tiles' => $tiles, 'products' => $products, 'redirects' => $redirects];
}

/** Версия — по четырём частям в постоянном порядке; version и changedAt в неё не входят. */
function catalog_export_version(array $export): string
{
    $canonical = [
        'sections' => $export['sections'],
        'tiles' => $export['tiles'],
        'products' => $export['products'],
        'redirects' => $export['redirects'],
    ];
    return hash('sha256', json_encode($canonical, CATALOG_JSON));
}

function catalog_export(PDO $db): array
{
    $data = catalog_export_data($db);
    return ['version' => catalog_export_version($data), 'changedAt' => catalog_meta($db)['changed_at'] ?? null] + $data;
}

/**
 * Конец сохранения: если выгрузка изменилась — запомнить новую версию и
 * время. Правка черновика выгрузку не меняет, и время не трогается: иначе
 * админка показывала бы «ждёт выкладки», а GitHub не видел бы, что собирать.
 */
function catalog_touch(PDO $db, DateTimeImmutable $now): void
{
    $version = catalog_export_version(catalog_export_data($db));
    if ((catalog_meta($db)['version'] ?? null) === $version) {
        return;
    }
    $set = $db->prepare('INSERT INTO meta (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $set->execute(['version', $version]);
    $set->execute(['changed_at', catalog_iso($now)]);
}
