<?php
/**
 * Копия базы раз в сутки: VACUUM INTO даёт целую копию, даже если в эту
 * секунду кто-то сохраняет букет. Копии старше 30 дней удаляются.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CATALOG_BACKUP_DAYS = 30;

/** Копия на сегодня (если её ещё нет) и уборка старых; возвращает путь сегодняшней. */
function catalog_backup(PDO $db, string $dir, DateTimeImmutable $now): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку копий: $dir");
    }
    $today = $now->setTimezone(new DateTimeZone(CATALOG_TZ));
    $file = $dir . '/catalog-' . $today->format('Y-m-d') . '.sqlite';
    if (!is_file($file)) {
        $db->prepare('VACUUM INTO ?')->execute([$file]);
    }
    $oldest = $today->modify('-' . CATALOG_BACKUP_DAYS . ' days')->format('Y-m-d');
    foreach (glob($dir . '/catalog-*.sqlite') ?: [] as $old) {
        if (preg_match('/catalog-(\d{4}-\d{2}-\d{2})\.sqlite$/', $old, $m) && $m[1] < $oldest) {
            unlink($old);
        }
    }
    return $file;
}
