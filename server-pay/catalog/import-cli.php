<?php
/**
 * Загрузка выгрузки каталога в пустую базу на сервере:
 *
 *   php import-cli.php /путь/к/выгрузке.json
 *
 * Печатает, сколько загружено, и версию — она должна совпасть с версией в
 * файле. В непустую базу не загружает.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/import.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, 'Использование: php import-cli.php <выгрузка.json>' . PHP_EOL);
    exit(2);
}
try {
    $export = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $db = catalog_db_open(catalog_db_path());
    // pay/catalog → pay → корень сайта: фото из выгрузки должны лежать там.
    $webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
    $counts = catalog_import($db, $export, new DateTimeImmutable(), $webroot);
    $version = catalog_export($db)['version'];
    printf(
        "Загружено: разделов %d, букетов %d, переадресаций %d.\nВерсия каталога: %s\n",
        $counts['sections'],
        $counts['products'],
        $counts['redirects'],
        $version,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Не загружено: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
