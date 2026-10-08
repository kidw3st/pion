<?php
/**
 * Общее для проверок каталога: время, временная база, разделы.
 *
 * Не *_test.php — run.php его сам не запускает, его подключают проверки.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/catalog/db.php';

/**
 * Отдельный случай проверки. Переменные случая живут только в нём: после
 * него база закрывается — иначе Windows не даст удалить временную папку.
 */
function t_case(string $name, callable $fn): void
{
    $fn();
}

/** 5 октября 2026, 14:00 по Перми; $shift — как в DateTimeImmutable::modify ('+91 days'). */
function t_now(string $shift = ''): DateTimeImmutable
{
    $now = new DateTimeImmutable('2026-10-05 14:00:00', new DateTimeZone('Asia/Yekaterinburg'));
    return $shift === '' ? $now : $now->modify($shift);
}

/** Пустая база: новая во временной папке или по пути $file. */
function t_catalog_db(?string $file = null): PDO
{
    return catalog_db_open($file ?? t_tmpdir() . '/catalog.sqlite');
}

/**
 * Разделы «Букеты» и «Розы» с плитками и «Новинки» без плитки в каталоге
 * (скрыт); первая плитка сетки — постоянная «Цветы».
 */
function t_catalog_with_sections(?PDO $db = null): PDO
{
    $db ??= t_catalog_db();
    $add = $db->prepare('INSERT INTO sections (slug, label, visible, cover_title, heading, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['bukety', 'Букеты', 1], ['roses', 'Розы', 1], ['novinki', 'Новинки', 0]] as [$slug, $label, $visible]) {
        $add->execute([$slug, $label, $visible, $label, mb_strtoupper($label), '2026-10-01T10:00:00+05:00']);
    }
    $db->exec("INSERT INTO tiles (position, type, label, href, image)
        VALUES (1, 'link', 'Цветы', '/flowers', '/images/site/catalog-tiles/tile-0.webp')");
    $db->exec("INSERT INTO tiles (position, type, section)
        VALUES (2, 'section', 'bukety'), (3, 'section', 'roses'), (4, 'section', 'novinki')");
    return $db;
}

/** Букет прямо в базу, мимо правил админки; $positions — раздел => место в нём. */
function t_put_product(PDO $db, string $uid, string $status, string $main, array $positions, ?string $slug = null): void
{
    $slug ??= "buket-$uid";
    $at = '2026-10-01T10:00:00+05:00';
    $db->prepare("INSERT INTO products (uid, slug, slug_pinned, title, description, price, images, main_section,
            status, status_changed_at, created_at, updated_at)
        VALUES (?, ?, 1, ?, 'Розы, эвкалипт', 4400, ?, ?, ?, ?, ?, ?)")
        ->execute([$uid, $slug, "Букет $uid", json_encode(["/images/catalog/$main/$slug.webp"]), $main, $status, $at, $at, $at]);
    $member = $db->prepare('INSERT INTO product_sections (uid, section, position) VALUES (?, ?, ?)');
    foreach ($positions as $section => $position) {
        $member->execute([$uid, $section, $position]);
    }
}

/**
 * Копия server-pay/catalog-export.php и server-pay/catalog/*.php во временной
 * папке: путь без кириллицы — так скрипты надёжно запускаются отдельным
 * процессом и на Windows.
 */
function t_catalog_scripts(): string
{
    $root = t_tmpdir();
    mkdir("$root/catalog");
    $src = dirname(__DIR__, 2) . '/server-pay';
    copy("$src/catalog-export.php", "$root/catalog-export.php");
    foreach (glob("$src/catalog/*.php") ?: [] as $file) {
        copy($file, "$root/catalog/" . basename($file));
    }
    return $root;
}

/**
 * Запускает PHP-скрипт отдельным процессом, как cron или веб-сервер; папку
 * базы получает через PION_CATALOG_HOME.
 *
 * @return array{0: int, 1: string} код выхода и вывод (stdout и stderr вместе)
 */
function t_catalog_cli(string $script, string $home, array $args = []): array
{
    putenv('PION_CATALOG_HOME=' . $home);
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $out, $code);
    } finally {
        putenv('PION_CATALOG_HOME');
    }
    return [$code, implode("\n", $out)];
}

/**
 * Фото плитки раздела прямо в базе, мимо журнала. Раздел в каталоге без него
 * не сохранить, а разделы из t_catalog_with_sections() стоят в каталоге без фото.
 */
function t_put_tile(PDO $db, string $slug, string $path = '/images/site/catalog-tiles/tile-0.webp'): void
{
    $db->prepare('UPDATE sections SET tile_image = ? WHERE slug = ?')->execute([$path, $slug]);
}

/** Поля букета по умолчанию; $over — что поменять. */
function t_fields(array $over = []): array
{
    return $over + [
        'title' => 'Букет «Нежность»',
        'description' => 'Розы, эвкалипт',
        'price' => 4400,
        'images' => [],
        'sections' => ['bukety'],
        'mainSection' => null,
    ];
}

function t_row(PDO $db, string $uid): array
{
    $q = $db->prepare('SELECT * FROM products WHERE uid = ?');
    $q->execute([$uid]);
    return $q->fetch();
}

/** Букеты раздела по местам — все, независимо от статуса. */
function t_section_order(PDO $db, string $section): array
{
    $q = $db->prepare('SELECT uid FROM product_sections WHERE section = ? ORDER BY position, uid');
    $q->execute([$section]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** Переадресации: откуда => куда. */
function t_redirects(PDO $db): array
{
    return $db->query('SELECT from_path, to_path FROM redirects ORDER BY from_path')->fetchAll(PDO::FETCH_KEY_PAIR);
}
