<?php
/**
 * Выгрузка каталога для сборки сайта на GitHub Actions: весь каталог одним
 * JSON (формат — спецификация админки, раздел 3). Только чтение.
 *
 * Ключа нет: в выгрузке только то, что и так окажется на страницах сайта.
 * Адрес под /pay/: правила от парсеров в .htaccess его не трогают, robots.txt
 * его уже закрывает.
 */

declare(strict_types=1);

require __DIR__ . '/catalog/db.php';
require __DIR__ . '/catalog/export.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Каталога ещё нет: сборка не должна принять пустоту за «все букеты удалены». */
function catalog_export_unavailable(): never
{
    http_response_code(503);
    echo json_encode(['error' => 'Каталог ещё не создан'], CATALOG_JSON);
    exit;
}

$file = catalog_db_path();
if (!is_file($file)) {
    catalog_export_unavailable();
}
$db = catalog_db_open($file);
if ((int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn() === 0) {
    catalog_export_unavailable();
}
echo json_encode(catalog_export($db), CATALOG_JSON);
