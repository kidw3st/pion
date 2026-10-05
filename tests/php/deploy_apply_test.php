<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

/** Архив, как его собирает GitHub: tar.gz от корня папки. */
function t_make_archive(string $path, array $files): void
{
    $src = t_tmpdir();
    t_put_files($src, $files);
    exec(deploy_tar() . ' -czf ' . escapeshellarg($path) . ' -C ' . escapeshellarg($src) . ' . 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('tar: ' . implode(' ', $out));
    }
}

/** Сборка с обязательными файлами и тем, что добавлено сверху. */
function t_site(array $extra = []): array
{
    return [
        'index.html' => 'new home',
        '404.html' => 'not found',
        '.htaccess' => 'rules',
        'sitemap.xml' => '<urlset/>',
        'robots.txt' => 'User-agent: *',
    ] + $extra;
}

/** Веб-корень, где уже лежат прошлая сборка, WordPress, /pay/ и чужие файлы. */
function t_webroot(): string
{
    $web = t_tmpdir();
    t_put_files($web, [
        'index.html' => 'old home',
        'old-page/index.html' => 'stale',
        'images/catalog/bukety/a.webp' => 'photo from admin',
        'api/showcase.json' => '{"showcase":1}',
        'pay/config.php' => '<?php // secrets',
        'pay/init.php' => '<?php echo 0;',
        'blog/index.php' => '<?php // wordpress',
        'manual.txt' => 'not from any build',
    ]);
    return $web;
}

/** Список прошлой сборки; защищённые пути попали в него «по ошибке». */
$prevFiles = ['index.html', 'old-page/index.html', 'images/catalog/bukety/a.webp', 'api/showcase.json', 'blog/index.php'];

// --- Обычная выкладка поверх прошлой --------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site([
    'catalog/index.html' => 'catalog',
    'blog/wp-content/themes/pion/style.css' => 'theme',
]));
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;', '.htaccess' => 'Require all denied']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false);

t_equal(file_get_contents("$web/index.html"), 'new home', 'главная заменена');
t_equal(file_get_contents("$web/catalog/index.html"), 'catalog', 'новая страница появилась');
t_true(!file_exists("$web/old-page/index.html"), 'устаревшая страница удалена');
t_true(!is_dir("$web/old-page"), 'опустевшая папка удалена');
t_equal(file_get_contents("$web/images/catalog/bukety/a.webp"), 'photo from admin', 'фото каталога не тронуто, хоть и было в прошлом списке');
t_equal(file_get_contents("$web/api/showcase.json"), '{"showcase":1}', 'витрина не тронута');
t_equal(file_get_contents("$web/blog/index.php"), '<?php // wordpress', 'WordPress не тронут');
t_equal(file_get_contents("$web/blog/wp-content/themes/pion/style.css"), 'theme', 'тема блога выложена');
t_equal(file_get_contents("$web/manual.txt"), 'not from any build', 'файл не из сборки остался');
t_equal(file_get_contents("$web/pay/config.php"), '<?php // secrets', 'config.php не тронут');
t_equal(file_get_contents("$web/pay/init.php"), '<?php echo 1;', '/pay/ обновлён');
t_equal(file_get_contents("$web/pay/.htaccess"), 'Require all denied', 'скрытые файлы /pay/ тоже выложены');
t_equal($report['deleted'], ['old-page/index.html'], 'отчёт: удалено');
t_equal(count($report['files']), 7, 'отчёт: файлы сборки');
t_true($report['payUpdated'], 'отчёт: /pay/ обновлён');
t_true(!is_dir("$work/x"), 'рабочая папка убрана');
t_equal(
    array_values(array_filter(array_keys(t_snapshot($web)), static fn(string $p): bool => str_ends_with($p, '.deploy-part'))),
    [],
    'временных файлов не осталось',
);

// --- Первая выкладка: удалять нечего ------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site());
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], null, $web, true, "$work/x", false);
t_equal($report['deleted'], [], 'первая выкладка ничего не удаляет');
t_true(is_file("$web/old-page/index.html"), 'старая страница на месте');

// --- Платёжный архив не менялся ------------------------------------------
$work = t_tmpdir();
$web = t_webroot();
t_make_archive("$work/site.tgz", t_site());
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, false, "$work/x", false);
t_equal(file_get_contents("$web/pay/init.php"), '<?php echo 0;', '/pay/ не тронут, если архив тот же');
t_true(!$report['payUpdated'], 'отчёт: /pay/ не обновлялся');

// --- Пробный прогон ничего не меняет -------------------------------------
$work = t_tmpdir();
$web = t_webroot();
$before = t_snapshot($web);
t_make_archive("$work/site.tgz", t_site(['catalog/index.html' => 'catalog']));
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$report = deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", true);
t_equal(t_snapshot($web), $before, 'пробный прогон не трогает сайт');
t_equal($report['stale'], ['old-page/index.html'], 'пробный прогон знает, что удалил бы');

// --- Испорченные сборки: сайт не меняется -------------------------------
$broken = [
    'нет robots.txt' => [array_diff_key(t_site(), ['robots.txt' => 1]), ['init.php' => '<?php echo 1;']],
    'фото каталога в архиве сайта' => [t_site(['images/catalog/x.webp' => 'x']), ['init.php' => '<?php echo 1;']],
    'config.php в платёжном архиве' => [t_site(), ['init.php' => '<?php echo 1;', 'config.php' => '<?php // leak']],
    'синтаксис в /pay/' => [t_site(), ['init.php' => '<?php echo (;']],
    'синтаксис в теме блога' => [t_site(['blog/wp-content/themes/pion/functions.php' => '<?php function (']), ['init.php' => '<?php echo 1;']],
];
foreach ($broken as $what => [$siteFiles, $payFiles]) {
    $work = t_tmpdir();
    $web = t_webroot();
    $before = t_snapshot($web);
    t_make_archive("$work/site.tgz", $siteFiles);
    t_make_archive("$work/pay.tgz", $payFiles);
    t_throws(
        fn() => deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false),
        DeployFatal::class,
        "$what — сборка отклонена",
    );
    t_equal(t_snapshot($web), $before, "$what — сайт не тронут");
}

// --- Битый архив — сбой, но не приговор сборке -------------------------
$work = t_tmpdir();
$web = t_webroot();
file_put_contents("$work/site.tgz", 'not a tar');
t_make_archive("$work/pay.tgz", ['init.php' => '<?php echo 1;']);
$e = t_throws(
    fn() => deploy_apply(['site' => "$work/site.tgz", 'pay' => "$work/pay.tgz"], $prevFiles, $web, true, "$work/x", false),
    RuntimeException::class,
    'битый архив — сбой',
);
t_true(!($e instanceof DeployFatal), 'битый архив сервер скачает заново, а не пометит сборку плохой');

// --- php -l не запустился — сбой, но не приговор сборке ----------------
// Код 255 — PHP нашёл ошибку в файле; любой другой ненулевой — проверка не
// состоялась (нет файла, нет PHP, процесс убит), и хорошую сборку из-за этого
// нельзя навсегда помечать испорченной.
$lintDir = t_tmpdir();
$lintE = t_throws(
    fn() => deploy_lint_php($lintDir, ['missing.php'], 'pay/'),
    RuntimeException::class,
    'php -l не смог проверить файл — сбой',
);
t_true(!($lintE instanceof DeployFatal), 'сбой запуска php -l не помечает сборку плохой, выкладку повторят');
t_true(
    $lintE !== null
        && str_contains($lintE->getMessage(), 'pay/missing.php')
        && preg_match('~код \d+~u', $lintE->getMessage()) === 1,
    'в сообщении о сбое файл с меткой и код выхода',
);

// --- php -l: ошибку синтаксиса по-прежнему находит ---------------------
$lintDir = t_tmpdir();
t_put_files($lintDir, ['bad.php' => '<?php echo (;', 'good.php' => '<?php echo 1;']);
$lintErrors = deploy_lint_php($lintDir, ['bad.php', 'good.php'], 'pay/');
t_equal(count($lintErrors), 1, 'php -l: из двух файлов испорчен один — одна ошибка');
t_true(str_starts_with($lintErrors[0] ?? '', 'pay/bad.php: '), 'php -l: ошибка начинается с метки и пути файла');
