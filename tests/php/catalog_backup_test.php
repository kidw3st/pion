<?php
/**
 * Копии базы: одна в день, хранятся 30 дней. Делаются через SQLite3::backup —
 * на сервере SQLite 3.26, а копирование командой VACUUM в файл там ещё нет.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/backup.php';

/** Сколько разделов в файле базы. */
function t_sections_in(string $file): int
{
    $copy = new PDO('sqlite:' . $file);
    return (int)$copy->query('SELECT COUNT(*) FROM sections')->fetchColumn();
}

t_case('копия базы', function (): void {
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $dir = "$home/backups";
    mkdir($dir);
    file_put_contents("$dir/catalog-2026-09-04.sqlite", 'старше 30 дней');
    file_put_contents("$dir/catalog-2026-09-05.sqlite", 'ровно 30 дней');
    file_put_contents("$dir/notes.txt", 'чужой файл');

    $file = catalog_backup("$home/catalog.sqlite", $dir, t_now());
    t_equal($file, "$dir/catalog-2026-10-05.sqlite", 'копия на сегодня');
    t_equal(t_sections_in($file), 3, 'в копии те же данные');

    $db->exec('DELETE FROM tiles');
    $db->exec('DELETE FROM sections');
    catalog_backup("$home/catalog.sqlite", $dir, t_now('+3 hours'));
    t_equal(t_sections_in($file), 3, 'второй запуск в тот же день копию не перезаписывает');

    $left = array_map('basename', glob("$dir/*") ?: []);
    sort($left);
    t_equal($left, ['catalog-2026-09-05.sqlite', 'catalog-2026-10-05.sqlite', 'notes.txt'], 'копии старше 30 дней удалены, остальное не тронуто');
});

t_case('копия во время записи', function (): void {
    $home = t_tmpdir();
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    // Кто-то в эту секунду сохраняет букет: копия всё равно целая — с тем, что уже сохранено.
    $db->exec('BEGIN IMMEDIATE');
    $db->exec("INSERT INTO meta (key, value) VALUES ('uncommitted', '1')");
    $file = catalog_backup("$home/catalog.sqlite", "$home/backups", t_now());
    $db->exec('ROLLBACK');
    t_equal(t_sections_in($file), 3, 'копия открывается и содержит сохранённое');
    $copy = new PDO('sqlite:' . $file);
    t_equal((int)$copy->query("SELECT COUNT(*) FROM meta WHERE key = 'uncommitted'")->fetchColumn(), 0, 'несохранённого в копии нет');
});

t_case('backup-cli.php', function (): void {
    $scripts = t_catalog_scripts();
    $home = t_tmpdir();
    [$code, $out] = t_catalog_cli("$scripts/catalog/backup-cli.php", $home);
    t_equal([$code, str_contains($out, 'ещё нет')], [0, true], 'базы нет — спокойно выходит');
    t_catalog_db("$home/catalog.sqlite");
    [$code] = t_catalog_cli("$scripts/catalog/backup-cli.php", $home);
    t_equal([$code, count(glob("$home/backups/catalog-*.sqlite") ?: [])], [0, 1], 'база есть — копия сделана');
});

t_case('копия после прерывания', function (): void {
    $home = t_tmpdir();
    t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $dir = "$home/backups";
    mkdir($dir);
    $file = "$dir/catalog-2026-10-05.sqlite";
    $part = "$file.part";
    // Остаток прерванного копирования: на сегодняшнюю копию он не похож и не мешает.
    file_put_contents($part, 'прерванная копия');

    t_equal(catalog_backup("$home/catalog.sqlite", $dir, t_now()), $file, 'возвращено финальное имя');
    t_true(is_file($file) && !is_file($part), 'финальный файл создан, остаток убран');
    t_equal(t_sections_in($file), 3, 'финальный файл — настоящая база с данными');
});
