<?php
/**
 * scripts/check-server-build.php: вердикт сервера до публикации сборки.
 *
 * Скрипт запускается отдельным процессом, как его запускает GitHub Actions:
 * принята сборка или нет, видно по коду выхода и по тексту.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

/** tar.gz от корня папки, как его собирает scripts/pack-build.mjs. */
function t_tar_gz(string $path, array $files): void
{
    $src = t_tmpdir();
    t_put_files($src, $files);
    exec(deploy_tar() . ' -czf ' . escapeshellarg($path) . ' -C ' . escapeshellarg($src) . ' . 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('tar: ' . implode(' ', $out));
    }
}

/**
 * Запускает проверку. Возвращает код выхода и весь вывод (stdout и stderr).
 *
 * @param list<string> $args
 * @return array{0:int,1:string}
 */
function t_run_check_build(array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/check-server-build.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

$required = [
    'index.html' => 'home',
    '404.html' => 'not found',
    '.htaccess' => 'rules',
    'sitemap.xml' => '<urlset/>',
    'robots.txt' => 'User-agent: *',
];
$payFiles = ['init.php' => '<?php echo 1;', '.htaccess' => 'Require all denied'];
$leftoversBefore = glob(str_replace('\\', '/', sys_get_temp_dir()) . '/pion-check-build-*') ?: [];

$dir = t_tmpdir();
t_tar_gz("$dir/pay.tgz", $payFiles);

// --- Нормальная сборка: сервер примет -------------------------------------
t_tar_gz("$dir/site.tgz", $required + ['bukety/index.html' => 'section']);
[$code, $out] = t_run_check_build(["$dir/site.tgz", "$dir/pay.tgz"]);
t_equal($code, 0, 'нормальная сборка: код выхода');
t_true(
    str_contains($out, '[check-server-build] сервер примет сборку: файлов 6, в /pay/ 2'),
    "нормальная сборка: сколько файлов ($out)",
);

// --- Фото каталога в архиве сайта: сервер отклонит -------------------------
t_tar_gz("$dir/catalog.tgz", $required + ['images/catalog/x.webp' => 'photo']);
[$code, $out] = t_run_check_build(["$dir/catalog.tgz", "$dir/pay.tgz"]);
t_equal($code, 1, 'фото каталога: код выхода');
t_true(
    str_contains($out, 'сервер отклонит сборку') && str_contains($out, 'images/catalog/x.webp'),
    "фото каталога: названа причина ($out)",
);

// --- Файлы для ИИ-ассистентов принадлежат сборке: сервер примет ------------
t_tar_gz("$dir/skills.tgz", $required + [
    '.well-known/agent-skills/index.json' => '{}',
    '.well-known/agent-skills/pion-catalog/SKILL.md' => '# skill',
]);
[$code, $out] = t_run_check_build(["$dir/skills.tgz", "$dir/pay.tgz"]);
t_equal($code, 0, 'agent-skills: код выхода');
t_true(str_contains($out, 'сервер примет сборку: файлов 7'), "agent-skills: приняты ($out)");

// --- Остальное в .well-known/ (проверка Let's Encrypt) по-прежнему чужое ---
t_tar_gz("$dir/acme.tgz", $required + ['.well-known/acme-challenge/x' => 'token']);
[$code, $out] = t_run_check_build(["$dir/acme.tgz", "$dir/pay.tgz"]);
t_equal($code, 1, 'acme-challenge: код выхода');
t_true(str_contains($out, '.well-known/acme-challenge/x'), "acme-challenge: назван файл ($out)");

// --- Платёжный архив: config.php и ошибка синтаксиса -----------------------
t_tar_gz("$dir/pay-config.tgz", $payFiles + ['config.php' => '<?php // secret']);
[$code, $out] = t_run_check_build(["$dir/site.tgz", "$dir/pay-config.tgz"]);
t_equal($code, 1, 'config.php в /pay/: код выхода');
t_true(str_contains($out, 'config.php'), "config.php в /pay/: назван ($out)");

t_tar_gz("$dir/pay-broken.tgz", ['init.php' => '<?php echo (;']);
[$code, $out] = t_run_check_build(["$dir/site.tgz", "$dir/pay-broken.tgz"]);
t_equal($code, 1, 'ошибка синтаксиса в /pay/: код выхода');
t_true(str_contains($out, 'pay/init.php'), "ошибка синтаксиса в /pay/: назван файл ($out)");

// --- Нет обязательного файла ---------------------------------------------
$noRobots = $required;
unset($noRobots['robots.txt']);
t_tar_gz("$dir/no-robots.tgz", $noRobots);
[$code, $out] = t_run_check_build(["$dir/no-robots.tgz", "$dir/pay.tgz"]);
t_equal($code, 1, 'без robots.txt: код выхода');
t_true(str_contains($out, 'robots.txt'), "без robots.txt: назван файл ($out)");

// --- Не хватает аргументов или файла: подсказка и код 2 -------------------
[$code, $out] = t_run_check_build([]);
t_equal($code, 2, 'без аргументов: код выхода');
t_true(str_contains($out, 'Использование: php scripts/check-server-build.php'), "без аргументов: подсказка ($out)");
[$code, $out] = t_run_check_build(["$dir/site.tgz", "$dir/absent.tgz"]);
t_equal($code, 2, 'нет файла: код выхода');
t_true(str_contains($out, 'absent.tgz'), "нет файла: назван ($out)");

// --- Распакованное скрипт за собой убирает: и при успехе, и при отказе ----
t_equal(
    glob(str_replace('\\', '/', sys_get_temp_dir()) . '/pion-check-build-*') ?: [],
    $leftoversBefore,
    'временные папки скрипта убраны',
);
