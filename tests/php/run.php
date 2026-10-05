<?php
/**
 * PHP-проверки проекта: по очереди подключает tests/php/*_test.php.
 *
 *   php tests/php/run.php
 *
 * PHPUnit сюда не тянем: в проекте нет composer, а проверкам хватает
 * нескольких функций ниже. Код выхода 1, если хоть одна проверка не
 * прошла, — по нему CI понимает, что сборку выкладывать нельзя.
 */

declare(strict_types=1);

$GLOBALS['t_passed'] = 0;
$GLOBALS['t_failed'] = 0;
$GLOBALS['t_dirs'] = [];

/** Строгое сравнение: тип тоже важен. */
function t_equal(mixed $got, mixed $want, string $what): void
{
    if ($got === $want) {
        $GLOBALS['t_passed']++;
        return;
    }
    $GLOBALS['t_failed']++;
    fwrite(STDERR, '  ПЛОХО: ' . $what . PHP_EOL
        . '    получили: ' . var_export($got, true) . PHP_EOL
        . '    ждали:    ' . var_export($want, true) . PHP_EOL);
}

function t_true(bool $cond, string $what): void
{
    t_equal($cond, true, $what);
}

/** Функция должна бросить исключение класса $class; возвращает его. */
function t_throws(callable $fn, string $class, string $what): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        t_true($e instanceof $class, $what . ' (бросило ' . get_class($e) . ': ' . $e->getMessage() . ')');
        return $e;
    }
    t_true(false, $what . ' (исключения не было)');
    return null;
}

/** Новая пустая папка; удаляется в конце прогона. */
function t_tmpdir(): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pion-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    $GLOBALS['t_dirs'][] = $dir;
    return $dir;
}

/** Кладёт файлы в папку: относительный путь => содержимое. */
function t_put_files(string $dir, array $files): void
{
    foreach ($files as $rel => $body) {
        $path = $dir . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $body);
    }
}

/** Снимок папки: относительный путь => md5 содержимого. */
function t_snapshot(string $dir): array
{
    $snap = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
            $snap[$rel] = md5_file($file->getPathname());
        }
    }
    ksort($snap, SORT_STRING);
    return $snap;
}

function t_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$tests = glob(__DIR__ . '/*_test.php') ?: [];
sort($tests, SORT_STRING);
foreach ($tests as $test) {
    echo basename($test), PHP_EOL;
    require $test;
}

foreach ($GLOBALS['t_dirs'] as $dir) {
    t_rmtree($dir);
}

printf('Проверок: %d, не прошло: %d%s', $GLOBALS['t_passed'] + $GLOBALS['t_failed'], $GLOBALS['t_failed'], PHP_EOL);
exit($GLOBALS['t_failed'] > 0 ? 1 : 0);
