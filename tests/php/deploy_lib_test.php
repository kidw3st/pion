<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

// --- Защищённые пути -------------------------------------------------------
foreach ([
    'pay/config.php' => true,
    'pay' => true,
    'blog/index.php' => true,
    'blog/wp-content/uploads/a.jpg' => true,
    'blog/wp-content/themes/pion/style.css' => false,
    'images/catalog/bukety/a.webp' => true,
    'images/showcase/b.webp' => true,
    'api/showcase.json' => true,
    '.well-known/acme-challenge/x' => true,
    '.well-known/agent-skills/index.json' => false,
    '.well-known/agent-skills/pion-catalog/SKILL.md' => false,
    'index.html' => false,
    'images/site/logo.webp' => false,
    'api/catalog/bukety.json' => false,
    'payment/index.html' => false,
] as $path => $want) {
    t_equal(deploy_is_protected($path), $want, "защищён ли $path");
}

// --- Ветки из ответа git-сервера ------------------------------------------
// Формат pkt-line: 4 hex-цифры длины строки (вместе с ними), затем строка.
$pkt = static fn(string $line): string => sprintf('%04x', strlen($line) + 4) . $line;
$body = $pkt("# service=git-upload-pack\n") . '0000'
    . $pkt(str_repeat('a', 40) . " HEAD\0multi_ack symref=HEAD:refs/heads/master\n")
    . $pkt(str_repeat('a', 40) . " refs/heads/master\n")
    . $pkt(str_repeat('b', 40) . " refs/heads/server-build\n")
    . $pkt(str_repeat('c', 40) . " refs/heads/feature/x\n")
    . $pkt(str_repeat('d', 40) . " refs/tags/v1.0.0\n")
    . '0000';
t_equal(deploy_parse_refs($body), [
    'master' => str_repeat('a', 40),
    'server-build' => str_repeat('b', 40),
    'feature/x' => str_repeat('c', 40),
], 'ветки из ответа info/refs');
t_equal(deploy_parse_refs('<html>rate limit</html>'), [], 'не тот ответ — веток нет');

// --- Список файлов распакованной сборки -----------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['b/x.html' => '1', 'a.txt' => '2', '.htaccess' => '3', 'b/c/d.css' => '4']);
mkdir($dir . '/empty');
t_equal(
    deploy_list_files($dir),
    ['.htaccess', 'a.txt', 'b/c/d.css', 'b/x.html'],
    'только файлы, через «/», по алфавиту',
);

// --- Что удалить после выкладки -------------------------------------------
t_equal(
    deploy_stale_files(
        ['index.html', 'old/index.html', 'images/catalog/a.webp', 'pay/init.php', 'blog/wp-content/themes/pion/old.php'],
        ['index.html', 'new/index.html'],
    ),
    ['old/index.html', 'blog/wp-content/themes/pion/old.php'],
    'удаляется только своё и пропавшее',
);
t_equal(deploy_stale_files(['a', 'b'], ['a', 'b']), [], 'одинаковые сборки — удалять нечего');

// --- Состав архива ----------------------------------------------------------
t_equal(
    deploy_forbidden_files(['index.html', 'images/catalog/x.webp', 'pay/init.php']),
    ['images/catalog/x.webp', 'pay/init.php'],
    'чужие пути в архиве сайта',
);
t_equal(deploy_missing_required(['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt']), [], 'всё обязательное на месте');
t_equal(deploy_missing_required(['index.html', '.htaccess']), ['404.html', 'sitemap.xml', 'robots.txt'], 'чего не хватает');

// --- Сверка архивов с build-info.json -------------------------------------
$dir = t_tmpdir();
t_put_files($dir, ['site.tgz' => 'site-bytes', 'pay.tgz' => 'pay-bytes']);
$info = [
    'site' => ['sha256' => hash('sha256', 'site-bytes')],
    'pay' => ['sha256' => hash('sha256', 'pay-bytes')],
];
$paths = ['site' => "$dir/site.tgz", 'pay' => "$dir/pay.tgz"];
t_equal(deploy_check_archives($info, $paths), [], 'sha256 сошлись');
$info['pay']['sha256'] = str_repeat('0', 64);
t_equal(deploy_check_archives($info, $paths), ['pay: sha256 не совпадает'], 'подменённый архив');
unset($info['site']);
t_equal(
    deploy_check_archives($info, $paths),
    ['в build-info.json нет sha256 для site', 'pay: sha256 не совпадает'],
    'нет записи в build-info.json',
);
