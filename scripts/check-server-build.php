<?php
/**
 * Проверяет собранные архивы по тем же правилам, по которым их примет сервер.
 *
 * Правила живут в server-pay/deploy-lib.php, и здесь вызывается та же
 * deploy_apply(), что и на сервере, — в режиме «проверить, ничего не
 * выкладывая»: распаковка, обязательные файлы, файлы в чужих путях, config.php
 * в платёжном архиве, php -l для каждого PHP-файла. Отдельные списки у сборки
 * и у сервера разошлись бы незаметно: тесты и CI были бы зелёными, а сервер
 * отвергал бы каждую сборку. Теперь такое расхождение останавливает
 * публикацию в GitHub Actions, а не отказом сервера через четверть часа.
 *
 *   php scripts/check-server-build.php build/pion-site.tar.gz build/pion-pay.tar.gz
 *
 * Код выхода: 0 — сервер примет сборку; 1 — отклонит (причина в stderr);
 * 2 — не указаны архивы или их нет.
 */

declare(strict_types=1);

require __DIR__ . '/../server-pay/deploy-lib.php';

$usage = 'Использование: php scripts/check-server-build.php build/pion-site.tar.gz build/pion-pay.tar.gz';
$site = (string)($argv[1] ?? '');
$pay = (string)($argv[2] ?? '');
if ($site === '' || $pay === '') {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(2);
}
foreach ([$site, $pay] as $archive) {
    if (!is_file($archive)) {
        fwrite(STDERR, "Нет файла $archive" . PHP_EOL . $usage . PHP_EOL);
        exit(2);
    }
}

// Распаковка идёт во временную папку; убирается при любом исходе.
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/pion-check-build-' . bin2hex(random_bytes(6));
$code = 1;
try {
    $report = deploy_apply(['site' => $site, 'pay' => $pay], null, $tmp . '/www', false, $tmp . '/work', true);
    echo sprintf(
        '[check-server-build] сервер примет сборку: файлов %d, в /pay/ %d',
        count($report['files']),
        count($report['payFiles']),
    ), PHP_EOL;
    $code = 0;
} catch (Throwable $e) {
    fwrite(STDERR, '[check-server-build] сервер отклонит сборку: ' . $e->getMessage() . PHP_EOL);
} finally {
    deploy_rmtree($tmp);
}

// exit внутри try не выполнил бы finally, поэтому код выхода считается заранее.
exit($code);
