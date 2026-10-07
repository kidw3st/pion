<?php
/**
 * Копия базы раз в сутки: VACUUM INTO даёт целую копию, даже если в эту
 * секунду кто-то сохраняет букет. Копии старше 30 дней удаляются.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CATALOG_BACKUP_DAYS = 30;

/**
 * Копия на сегодня (если её ещё нет) и уборка старых; возвращает путь сегодняшней.
 * Копия появляется под финальным именем только когда завершена успешно —
 * VACUUM INTO пишет во временный файл .part, который переименовывается в финальное имя.
 */
function catalog_backup(PDO $db, string $dir, DateTimeImmutable $now): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку копий: $dir");
    }
    $today = $now->setTimezone(new DateTimeZone(CATALOG_TZ));
    $file = $dir . '/catalog-' . $today->format('Y-m-d') . '.sqlite';
    if (!is_file($file)) {
        $part = $file . '.part';
        // Удаляем остаток от прерванного запуска.
        if (is_file($part)) {
            unlink($part);
        }
        try {
            $db->prepare('VACUUM INTO ?')->execute([$part]);
            if (!rename($part, $file)) {
                throw new RuntimeException("Не переименовать копию базы: $part");
            }
        } catch (Throwable $e) {
            if (is_file($part)) {
                unlink($part);
            }
            throw $e;
        }
    }
    $oldest = $today->modify('-' . CATALOG_BACKUP_DAYS . ' days')->format('Y-m-d');
    foreach (glob($dir . '/catalog-*.sqlite') ?: [] as $old) {
        if (preg_match('/catalog-(\d{4}-\d{2}-\d{2})\.sqlite$/', $old, $m) && $m[1] < $oldest) {
            unlink($old);
        }
    }
    return $file;
}
