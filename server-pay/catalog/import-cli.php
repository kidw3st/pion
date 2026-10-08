<?php
/**
 * Загрузка выгрузки каталога в пустую базу на сервере:
 *
 *   php import-cli.php /путь/к/выгрузке.json
 *
 * Все фото, на которые ссылается выгрузка, должны уже лежать в корне сайта —
 * иначе отказ «Фото не найдены на сервере: N (искали в …)». Корень сайта —
 * переменная PION_WEBROOT, по умолчанию папка, в которой лежит pay/.
 *
 * Базы ещё нет — выгрузка грузится во временный файл рядом (<база>.import), и
 * имя базы он получает, только когда загрузка прошла: неудачная загрузка не
 * оставляет пустую базу (админка тогда по-прежнему отвечает 503, а не
 * показывает вход). Повторить можно сразу, исправив причину. В непустую базу не
 * загружает. Пока скрипт не напечатал версию, user-cli.php не запускать: он
 * заводит базу сам, и загрузка тогда откажет (загруженное останется в
 * <база>.import).
 *
 * Печатает, сколько загружено, и версию — она должна совпасть с версией в
 * файле.
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
$target = catalog_db_path();
$fresh = !is_file($target);
$dbFile = $fresh ? $target . '.import' : $target;
$db = null;
$keep = false;
try {
    $export = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if ($fresh && is_file($dbFile) && !unlink($dbFile)) {
        throw new RuntimeException("не удалить прошлый временный файл $dbFile");
    }
    $db = catalog_db_open($dbFile);
    // pay/catalog → pay → корень сайта: фото из выгрузки должны лежать там.
    $webroot = rtrim(getenv('PION_WEBROOT') ?: dirname(__DIR__, 2), '/');
    $counts = catalog_import($db, $export, new DateTimeImmutable(), $webroot);
    $version = catalog_export($db)['version'];
    $db = null;
    if ($fresh) {
        // Пока шла загрузка, базу мог завести кто-то другой (второй импорт, user-cli.php add):
        // rename() молча заменил бы её. Загруженное тогда не удаляем — его разбирают вручную.
        clearstatcache();
        if (is_file($target)) {
            $keep = true;
            throw new RuntimeException("пока шла загрузка, появилась база $target — загруженное оставлено в $dbFile, разберитесь вручную");
        }
        error_clear_last();
        if (!@rename($dbFile, $target)) {
            // Причину отдаёт система (права, занятый файл); текст зависит от платформы.
            $os = (string)(error_get_last()['message'] ?? '');
            throw new RuntimeException("не переименовать $dbFile в $target" . ($os !== '' ? ': ' . $os : ''));
        }
    }
    printf(
        "Загружено: разделов %d, букетов %d, переадресаций %d.\nВерсия каталога: %s\n",
        $counts['sections'],
        $counts['products'],
        $counts['redirects'],
        $version,
    );
} catch (Throwable $e) {
    $reason = $e->getMessage();
    // В трассе исключения остаётся база (она передавалась аргументом) — пока исключение живо, файл не удалить.
    unset($e);
    $db = null;
    if ($fresh && !$keep) {
        foreach ([$dbFile, $dbFile . '-journal'] as $leftover) {
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }
    }
    fwrite(STDERR, 'Не загружено: ' . $reason . PHP_EOL);
    exit(1);
}
