<?php
/**
 * Ежедневное обслуживание каталога — для расписания сервера (добавить на
 * этапе 3, когда в базе появится настоящий каталог):
 *
 *   15 3 * * * /usr/bin/php /var/www/u3620798/data/www/pionperm.ru/pay/catalog/maintenance-cli.php
 *
 * Делает копию базы и убирает фото, на которые больше никто не ссылается.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/maintenance.php';

$file = catalog_db_path();
if (!is_file($file)) {
    echo 'Базы каталога ещё нет — обслуживать нечего.', PHP_EOL;
    exit(0);
}
// pay/catalog → pay → корень сайта
$webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
$now = new DateTimeImmutable();
try {
    echo 'Копия: ', catalog_backup($file, catalog_home() . '/backups', $now), PHP_EOL;
    $r = catalog_photos_sweep(catalog_db_open($file), $webroot, catalog_home() . '/photo-candidates.json', $now);
    printf("Фото: ждут уборки %d, убрано в корзину %d, стёрто из корзины %d.\n", $r['candidates'], $r['moved'], $r['purged']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Обслуживание не удалось: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
