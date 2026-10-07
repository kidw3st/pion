<?php
/**
 * Букеты: черновик, правка, публикация, снятие с продажи, удаление и
 * восстановление — правила данных из спецификации (раздел «База каталога»).
 *
 * Каждая функция catalog_* — одна транзакция: или всё, или ничего. Она
 * проверяет версию букета (её видел сотрудник, открывая карточку) и в конце
 * вызывает catalog_touch(), чтобы выгрузка узнала об изменении.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/export.php';

/** Раздел «Новинки»: первые три букета в продаже из него — на главной. */
const CATALOG_NOVINKI = 'novinki';
const CATALOG_TITLE_MAX = 120;
const CATALOG_DESCRIPTION_MAX = 1000;
const CATALOG_PRICE_MIN = 100;
const CATALOG_PRICE_MAX = 300000;
const CATALOG_IMAGES_MAX = 4;
/** Фото — только файлы каталога: их кладёт админка (план 2Б) или перенос каталога. */
const CATALOG_IMAGE_PATH = '~^/images/catalog/[a-z0-9_-]+/[A-Za-z0-9._-]+\.webp$~';

/**
 * Проверяет поля букета и приводит их к виду для базы. Ошибка — CatalogError
 * с текстом для сотрудника.
 *
 * @return array{title: string, description: string, price: int, images: list<string>, sections: list<string>, mainSection: string}
 */
function catalog_product_fields(PDO $db, array $fields): array
{
    $title = trim((string)preg_replace('/\s+/u', ' ', (string)($fields['title'] ?? '')));
    if ($title === '') {
        throw new CatalogError('Напишите название букета.');
    }
    if (mb_strlen($title) > CATALOG_TITLE_MAX) {
        throw new CatalogError('Название длиннее 120 знаков — сократите его.');
    }
    $description = trim((string)($fields['description'] ?? ''));
    if (mb_strlen($description) > CATALOG_DESCRIPTION_MAX) {
        throw new CatalogError('Состав длиннее 1000 знаков — сократите его.');
    }
    $price = $fields['price'] ?? null;
    if (!is_int($price) || $price < CATALOG_PRICE_MIN || $price > CATALOG_PRICE_MAX) {
        throw new CatalogError('Цена — целое число рублей, от 100 до 300 000.');
    }
    $images = array_values(array_map('strval', (array)($fields['images'] ?? [])));
    if (count($images) > CATALOG_IMAGES_MAX) {
        throw new CatalogError('Фото — не больше четырёх.');
    }
    foreach ($images as $image) {
        if (!preg_match(CATALOG_IMAGE_PATH, $image)) {
            throw new CatalogError('Фото не из каталога — загрузите его заново.');
        }
    }
    $sections = array_values(array_unique(array_map('strval', (array)($fields['sections'] ?? []))));
    if ($sections === []) {
        throw new CatalogError('Отметьте хотя бы один раздел.');
    }
    $known = $db->query('SELECT slug FROM sections')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sections as $section) {
        if (!in_array($section, $known, true)) {
            throw new CatalogError('Такого раздела нет — обновите страницу.');
        }
    }
    $main = (string)($fields['mainSection'] ?? '');
    if ($main === '') {
        $main = catalog_default_main_section($db, $sections);
    } elseif (!in_array($main, $sections, true)) {
        throw new CatalogError('Главный раздел должен быть среди отмеченных.');
    }
    return [
        'title' => $title,
        'description' => $description,
        'price' => $price,
        'images' => $images,
        'sections' => $sections,
        'mainSection' => $main,
    ];
}

/**
 * Главный раздел по умолчанию — первый отмеченный в порядке сетки, кроме
 * «Новинок»: новизна проходит, а адрес страницы должен остаться.
 */
function catalog_default_main_section(PDO $db, array $sections): string
{
    $order = $db->query('SELECT s.slug FROM sections s LEFT JOIN tiles t ON t.section = s.slug
        ORDER BY t.position IS NULL, t.position, s.slug')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($order as $slug) {
        if ($slug !== CATALOG_NOVINKI && in_array($slug, $sections, true)) {
            return $slug;
        }
    }
    return $sections[0];
}

/**
 * Uid нового букета — 12 случайных цифр, как у перенесённых из Tilda. Не
 * совпадает ни с одним uid в базе, включая удалённые: uid остаётся в истории
 * заказов.
 */
function catalog_new_uid(PDO $db): string
{
    $taken = $db->prepare('SELECT 1 FROM products WHERE uid = ?');
    do {
        $uid = (string)random_int(100000000000, 999999999999);
        $taken->execute([$uid]);
    } while ($taken->fetchColumn() !== false);
    return $uid;
}

function catalog_product_row(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT * FROM products WHERE uid = ?');
    $q->execute([$uid]);
    $row = $q->fetch();
    if ($row === false) {
        throw new CatalogError('Такого букета нет — обновите страницу.');
    }
    return $row;
}

/**
 * Строка букета для изменения. Версия не та, что видел сотрудник, — букет
 * успел сохранить кто-то другой, и чужую правку затирать нельзя.
 */
function catalog_product_for_change(PDO $db, string $uid, int $version): array
{
    $p = catalog_product_row($db, $uid);
    if ((int)$p['version'] !== $version) {
        $q = $db->prepare('SELECT name FROM users WHERE login = ?');
        $q->execute([$p['updated_by']]);
        $who = $q->fetchColumn() ?: ($p['updated_by'] !== '' ? $p['updated_by'] : 'другой сотрудник');
        $at = substr((string)$p['updated_at'], 11, 5);
        throw new CatalogConflict("Этот букет уже изменён ($who, $at) — откройте его заново.");
    }
    return $p;
}

/** Разделы букета — по алфавиту. */
function catalog_product_sections(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT section FROM product_sections WHERE uid = ? ORDER BY section');
    $q->execute([$uid]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** Ставит букет первым в разделе (добавляет в раздел, если его там не было). */
function catalog_put_first(PDO $db, string $uid, string $section): void
{
    $min = $db->prepare('SELECT MIN(position) FROM product_sections WHERE section = ?');
    $min->execute([$section]);
    $first = (int)($min->fetchColumn() ?? 1) - 1;
    $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)
        ON CONFLICT(uid, section) DO UPDATE SET position = excluded.position')
        ->execute([$uid, $section, $first]);
}

/**
 * Новый букет — черновик: на сайте его нет совсем, slug следует за
 * названием, пока букет не опубликуют.
 */
function catalog_create_product(PDO $db, string $login, array $fields, DateTimeImmutable $now): string
{
    return catalog_tx($db, function () use ($db, $login, $fields, $now): string {
        $f = catalog_product_fields($db, $fields);
        $uid = catalog_new_uid($db);
        $at = catalog_iso($now);
        $db->prepare("INSERT INTO products (uid, slug, title, description, price, images, main_section, status,
                status_changed_at, created_at, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)")
            ->execute([
                $uid, catalog_slugify($f['title']), $f['title'], $f['description'], $f['price'],
                json_encode($f['images'], CATALOG_JSON), $f['mainSection'], $at, $at, $at, $login,
            ]);
        foreach ($f['sections'] as $section) {
            catalog_put_first($db, $uid, $section);
        }
        catalog_audit($db, $login, $now, 'product', $uid, 'created', null, $f['title']);
        catalog_touch($db, $now);
        return $uid;
    });
}

/**
 * Правка букета: название, состав, цена, фото, разделы. $version — версия,
 * которую видел сотрудник.
 */
function catalog_update_product(PDO $db, string $login, string $uid, int $version, array $fields, DateTimeImmutable $now): void
{
    catalog_tx($db, function () use ($db, $login, $uid, $version, $fields, $now): void {
        $p = catalog_product_for_change($db, $uid, $version);
        if ($p['status'] === 'deleted') {
            throw new CatalogError('Букет удалён — сначала восстановите его.');
        }
        $f = catalog_product_fields($db, $fields);
        // Пока букет не опубликован, адреса никто не знает — slug следует за названием.
        $slug = $p['slug_pinned'] ? $p['slug'] : catalog_slugify($f['title']);

        $before = catalog_product_sections($db, $uid);
        $leave = $db->prepare('DELETE FROM product_sections WHERE uid = ? AND section = ?');
        foreach (array_diff($before, $f['sections']) as $section) {
            $leave->execute([$uid, $section]);
        }
        foreach (array_diff($f['sections'], $before) as $section) {
            catalog_put_first($db, $uid, $section);
        }
        $after = $f['sections'];
        sort($after);

        $images = json_encode($f['images'], CATALOG_JSON);
        $changes = [
            'title' => [$p['title'], $f['title']],
            'description' => [$p['description'], $f['description']],
            'price' => [(string)$p['price'], (string)$f['price']],
            'images' => [$p['images'], $images],
            'sections' => [implode(', ', $before), implode(', ', $after)],
            'mainSection' => [$p['main_section'], $f['mainSection']],
            'slug' => [$p['slug'], $slug],
        ];
        foreach ($changes as $field => [$old, $new]) {
            if ($old !== $new) {
                catalog_audit($db, $login, $now, 'product', $uid, $field, $old, $new);
            }
        }
        $db->prepare('UPDATE products SET slug = ?, title = ?, description = ?, price = ?, images = ?,
                main_section = ?, updated_at = ?, updated_by = ?, version = version + 1
            WHERE uid = ?')
            ->execute([$slug, $f['title'], $f['description'], $f['price'], $images, $f['mainSection'], catalog_iso($now), $login, $uid]);
        catalog_touch($db, $now);
    });
}
