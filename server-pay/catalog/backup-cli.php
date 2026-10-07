<?php
/**
 * Ежедневная копия базы каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/backup-cli.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/backup.php';

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — копировать нечего.', PHP_EOL;
    exit(0);
}
try {
    echo 'Копия: ', catalog_backup(catalog_db_open($file), catalog_home() . '/backups', new DateTimeImmutable()), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Копия не сделана: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
