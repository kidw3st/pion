<?php
/**
 * Копии базы: одна в день, хранятся 30 дней.
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

    $file = catalog_backup($db, $dir, t_now());
    t_equal($file, "$dir/catalog-2026-10-05.sqlite", 'копия на сегодня');
    t_equal(t_sections_in($file), 3, 'в копии те же данные');

    $db->exec('DELETE FROM tiles');
    $db->exec('DELETE FROM sections');
    catalog_backup($db, $dir, t_now('+3 hours'));
    t_equal(t_sections_in($file), 3, 'второй запуск в тот же день копию не перезаписывает');

    $left = array_map('basename', glob("$dir/*") ?: []);
    sort($left);
    t_equal($left, ['catalog-2026-09-05.sqlite', 'catalog-2026-10-05.sqlite', 'notes.txt'], 'копии старше 30 дней удалены, остальное не тронуто');
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
    $db = t_catalog_with_sections(t_catalog_db("$home/catalog.sqlite"));
    $dir = "$home/backups";
    mkdir($dir);
    $file = "$dir/catalog-2026-10-05.sqlite";
    $part = "$file.part";

    // Симулируем остаток от прерванного VACUUM INTO.
    file_put_contents($part, 'прерванная копия');
    t_true(is_file($part), 'создан файл .part');

    $result = catalog_backup($db, $dir, t_now());
    t_equal($result, $file, 'возвращено финальное имя');
    t_true(is_file($file), 'финальный файл создан');
    t_true(!is_file($part), 'файл .part удалён');
    t_equal(t_sections_in($file), 3, 'финальный файл — настоящая база с данными');
});
