<?php
/**
 * Копия базы каталога на сегодня — для запуска вручную.
 *
 * На расписание сервера ставят не этот файл, а maintenance-cli.php: он делает
 * ту же копию (catalog_backup) и ещё убирает фото и пишет итог для сторожа
 * выкладки, а этот итога не пишет. Порядок включения расписания — в
 * docs/catalog.md, «Для этапа 3В — порядок переключения».
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
    echo 'Копия: ', catalog_backup($file, catalog_home() . '/backups', new DateTimeImmutable()), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Копия не сделана: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
