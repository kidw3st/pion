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
