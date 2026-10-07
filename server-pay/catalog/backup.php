<?php
/**
 * Копия базы раз в сутки. На сервере SQLite 3.26, где копирования командой
 * VACUUM в файл ещё нет, поэтому копия делается штатным онлайн-копированием
 * SQLite (SQLite3::backup): она целая, даже если в эту секунду кто-то
 * сохраняет букет. Копия пишется во временный файл и получает своё имя только
 * целой. Копии старше 30 дней удаляются.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CATALOG_BACKUP_DAYS = 30;

/** Копия на сегодня (если её ещё нет) и уборка старых; возвращает путь сегодняшней. */
function catalog_backup(string $dbFile, string $dir, DateTimeImmutable $now): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Не создать папку копий: $dir");
    }
    $today = $now->setTimezone(new DateTimeZone(CATALOG_TZ));
    $file = $dir . '/catalog-' . $today->format('Y-m-d') . '.sqlite';
    if (!is_file($file)) {
        $part = $file . '.part';
        if (is_file($part)) {
            unlink($part);
        }
        $source = null;
        $target = null;
        try {
            $source = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            $source->busyTimeout(5000);
            $target = new SQLite3($part);
            if (!$source->backup($target)) {
                throw new RuntimeException('Копирование базы не удалось: ' . $source->lastErrorMsg());
            }
        } catch (Throwable $e) {
            $target?->close();
            $source?->close();
            if (is_file($part)) {
                unlink($part);
            }
            throw $e;
        }
        $target->close();
        $source->close();
        if (!rename($part, $file)) {
            if (is_file($part)) {
                unlink($part);
            }
            throw new RuntimeException("Не переименовать копию базы: $part");
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
