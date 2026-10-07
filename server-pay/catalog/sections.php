<?php
/**
 * Разделы каталога и порядок плиток в сетке. Раздел нельзя удалить — только
 * скрыть: у страницы раздела копятся позиции в поиске, и она остаётся.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/export.php';

const CATALOG_SECTION_LABEL_MAX = 60;
const CATALOG_SECTION_TEXT_MAX = 300;
const CATALOG_COVERS_MAX = 3;
/** Картинки разделов — файлы сайта: плитки и обложки лежат в /images/site/, фото каталога — в /images/catalog/. */
const CATALOG_SITE_IMAGE = '~^/images/[A-Za-z0-9/._-]+\.webp$~';

/** Поля карточки раздела: имя в выгрузке => колонка в базе. */
const CATALOG_SECTION_COLUMNS = [
    'label' => 'label',
    'tileImage' => 'tile_image',
    'visible' => 'visible',
    'coverTitle' => 'cover_title',
    'coverSub' => 'cover_sub',
    'covers' => 'covers',
    'heading' => 'heading',
    'headingSub' => 'heading_sub',
    'hasNotFound' => 'has_not_found',
    'seoTitle' => 'seo_title',
    'seoDescription' => 'seo_description',
];

/** Проверяет значение поля раздела и приводит к виду для базы. */
function catalog_section_value(string $field, mixed $value): string|int|null
{
    if (!isset(CATALOG_SECTION_COLUMNS[$field])) {
        throw new InvalidArgumentException("Нет поля раздела: $field");
    }
    switch ($field) {
        case 'label':
            $label = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
            if ($label === '' || mb_strlen($label) > CATALOG_SECTION_LABEL_MAX) {
                throw new CatalogError('Название раздела — от 1 до 60 знаков.');
            }
            return $label;
        case 'visible':
        case 'hasNotFound':
            return $value ? 1 : 0;
        case 'tileImage':
            $image = trim((string)$value);
            if ($image !== '' && !preg_match(CATALOG_SITE_IMAGE, $image)) {
                throw new CatalogError('Фото плитки не с сайта — загрузите его заново.');
            }
            return $image;
        case 'covers':
            $covers = array_values(array_map('strval', (array)$value));
            if (count($covers) > CATALOG_COVERS_MAX) {
                throw new CatalogError('Фото обложки — не больше трёх.');
            }
            foreach ($covers as $cover) {
                if (!preg_match(CATALOG_SITE_IMAGE, $cover)) {
                    throw new CatalogError('Фото обложки не с сайта — загрузите его заново.');
                }
            }
            return json_encode($covers, CATALOG_JSON);
        case 'seoTitle':
        case 'seoDescription':
            // Пусто — значит, по шаблону: сайт подставит заголовок и описание сам.
            $seo = trim((string)$value);
            if (mb_strlen($seo) > CATALOG_SECTION_TEXT_MAX) {
                throw new CatalogError('Текст длиннее 300 знаков — сократите его.');
            }
            return $seo === '' ? null : $seo;
        default:
            $text = trim((string)$value);
            if (mb_strlen($text) > CATALOG_SECTION_TEXT_MAX) {
                throw new CatalogError('Текст длиннее 300 знаков — сократите его.');
            }
            return $text;
    }
}

/**
 * Новый раздел: slug из названия, уникальный и не совпадающий с адресами
 * сайта (иначе -2, -3); плитка встаёт в конец сетки.
 */
function catalog_create_section(PDO $db, string $login, string $label, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $label, $now): string {
        $label = (string)catalog_section_value('label', $label);
        $taken = $db->query('SELECT slug FROM sections')->fetchAll(PDO::FETCH_COLUMN);
        $base = catalog_slugify($label);
        $slug = $base;
        for ($n = 2; in_array($slug, $taken, true) || in_array($slug, CATALOG_RESERVED_SLUGS, true); $n++) {
            $slug = "$base-$n";
        }
        $db->prepare('INSERT INTO sections (slug, label, cover_title, heading, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$slug, $label, $label, mb_strtoupper($label), catalog_iso($now)]);
        $db->prepare("INSERT INTO tiles (position, type, section)
            VALUES ((SELECT COALESCE(MAX(position), 0) + 1 FROM tiles), 'section', ?)")
            ->execute([$slug]);
        catalog_audit($db, $login, $now, 'section', $slug, 'created', null, $label);
        catalog_touch($db, $now);
        return $slug;
    });
}

/** Карточка раздела: меняются только переданные поля, каждое изменённое — в журнал. */
function catalog_update_section(PDO $db, string $login, string $slug, array $fields, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $slug, $fields, $now): void {
        $q = $db->prepare('SELECT * FROM sections WHERE slug = ?');
        $q->execute([$slug]);
        $row = $q->fetch();
        if ($row === false) {
            throw new CatalogError('Такого раздела нет — обновите страницу.');
        }
        $sets = [];
        $args = [];
        foreach ($fields as $field => $value) {
            $new = catalog_section_value((string)$field, $value);
            $column = CATALOG_SECTION_COLUMNS[$field];
            $old = $row[$column];
            if ($old === $new) {
                continue;
            }
            $sets[] = "$column = ?";
            $args[] = $new;
            catalog_audit($db, $login, $now, 'section', $slug, (string)$field,
                $old === null ? null : (string)$old, $new === null ? null : (string)$new);
        }
        if ($sets === []) {
            return;
        }
        $args[] = catalog_iso($now);
        $args[] = $slug;
        $db->prepare('UPDATE sections SET ' . implode(', ', $sets) . ', updated_at = ? WHERE slug = ?')->execute($args);
        catalog_touch($db, $now);
    });
}

/** Новый порядок сетки: все плитки, и разделов, и постоянные. */
function catalog_reorder_tiles(PDO $db, string $login, array $tileIds, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $tileIds, $now): void {
        $current = array_map('intval', $db->query('SELECT id FROM tiles ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN));
        $wanted = array_map('intval', array_values($tileIds));
        $a = $current;
        $b = $wanted;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new CatalogError('Плитки за это время поменялись — откройте страницу заново.');
        }
        if ($current === $wanted) {
            return;
        }
        $set = $db->prepare('UPDATE tiles SET position = ? WHERE id = ?');
        foreach ($wanted as $i => $id) {
            $set->execute([$i + 1, $id]);
        }
        catalog_audit($db, $login, $now, 'tiles', '', 'order', implode(',', $current), implode(',', $wanted));
        catalog_touch($db, $now);
    });
}
