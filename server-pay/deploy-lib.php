<?php
/**
 * Выкладка сборки сайта на сервер — всё, что не ходит в сеть.
 *
 * deploy.php (запуск по расписанию) спрашивает у GitHub, есть ли новая
 * сборка, скачивает её и отдаёт сюда: проверить, разложить, удалить
 * устаревшее. Разделено ради проверок: эти функции гоняются в tests/php/
 * на временных папках, без сервера и без GitHub.
 */

declare(strict_types=1);

/**
 * Пути веб-корня, которые сборке не принадлежат. Их наполняют сервер
 * (витрина из CRM, фото каталога из админки), WordPress или платёжный
 * архив. Выкладка сайта их не перезаписывает и не удаляет, даже если они
 * по ошибке попали в список файлов прошлой сборки.
 */
const DEPLOY_PROTECTED = [
    'pay/',
    'blog/',
    'images/catalog/',
    'images/showcase/',
    'api/showcase.json',
    '.well-known/',
];

/** Внутри защищённых путей сборке принадлежит только тема блога. */
const DEPLOY_OWNED_IN_PROTECTED = ['blog/wp-content/themes/pion/'];

/** Без этих файлов сайт не работает — такую сборку не выкладываем. */
const DEPLOY_REQUIRED = ['index.html', '404.html', '.htaccess', 'sitemap.xml', 'robots.txt'];

/**
 * Сборка испорчена: повторять бесполезно, пока не придёт новая. Сетевые
 * сбои — обычный RuntimeException: после них выкладку просто пробуют снова.
 */
final class DeployFatal extends RuntimeException
{
}

function deploy_is_protected(string $path): bool
{
    foreach (DEPLOY_OWNED_IN_PROTECTED as $owned) {
        if (str_starts_with($path, $owned)) {
            return false;
        }
    }
    foreach (DEPLOY_PROTECTED as $prefix) {
        if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * SHA веток из ответа git-сервера на /info/refs?service=git-upload-pack —
 * с этого запроса начинает git clone. У REST API GitHub без ключа лимит
 * 60 запросов в час на IP, а IP у хостинга общий с чужими сайтами.
 *
 * @return array<string,string> ветка => SHA
 */
function deploy_parse_refs(string $body): array
{
    preg_match_all('~([0-9a-f]{40}) refs/heads/([^\s\x00]+)~', $body, $matches, PREG_SET_ORDER);
    $refs = [];
    foreach ($matches as [, $sha, $branch]) {
        $refs[$branch] = $sha;
    }
    return $refs;
}

/**
 * Файлы папки: пути относительно неё через «/», по алфавиту. Папки в список
 * не входят — сравниваются и удаляются только файлы.
 *
 * @return list<string>
 */
function deploy_list_files(string $dir): array
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Что удалить после выкладки: файлы прошлой сборки, которых нет в новой.
 * Защищённые пути не удаляются никогда.
 *
 * @param list<string> $old
 * @param list<string> $new
 * @return list<string>
 */
function deploy_stale_files(array $old, array $new): array
{
    $keep = array_flip($new);
    $stale = [];
    foreach ($old as $path) {
        if (!isset($keep[$path]) && !deploy_is_protected($path)) {
            $stale[] = $path;
        }
    }
    return $stale;
}

/**
 * Файлы сборки в чужих путях. Их быть не должно: значит, сломалась
 * упаковка, и сборка затёрла бы фото каталога или платёжную часть.
 *
 * @param list<string> $files
 * @return list<string>
 */
function deploy_forbidden_files(array $files): array
{
    return array_values(array_filter($files, 'deploy_is_protected'));
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function deploy_missing_required(array $files): array
{
    return array_values(array_diff(DEPLOY_REQUIRED, $files));
}

/**
 * Сверяет скачанные архивы с build-info.json. Пустой ответ — всё сошлось.
 *
 * @param array<string,mixed> $info разобранный build-info.json
 * @param array<string,string> $paths 'site' / 'pay' => путь к архиву
 * @return list<string>
 */
function deploy_check_archives(array $info, array $paths): array
{
    $errors = [];
    foreach ($paths as $key => $path) {
        $want = $info[$key]['sha256'] ?? null;
        if (!is_string($want) || preg_match('/^[0-9a-f]{64}$/', $want) !== 1) {
            $errors[] = "в build-info.json нет sha256 для $key";
            continue;
        }
        if (!is_file($path) || hash_file('sha256', $path) !== $want) {
            $errors[] = "$key: sha256 не совпадает";
        }
    }
    return $errors;
}

// --- Раскладка сборки ------------------------------------------------------

/**
 * tar для exec. На хостинге — системный GNU tar. На Windows (локальные
 * проверки) — встроенный bsdtar по полному пути: tar из Git Bash принял бы
 * «C:» в пути к архиву за имя удалённого сервера.
 */
function deploy_tar(): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'tar';
    }
    return escapeshellarg((getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe');
}

/** Удаляет папку со всем содержимым; нет папки — ничего не делает. */
function deploy_rmtree(string $dir): void
{
    if (is_file($dir) || is_link($dir)) {
        unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

/** Распаковывает tar.gz в папку, создавая её. Ошибка tar — исключение. */
function deploy_extract(string $archive, string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("не создать папку $dir");
    }
    $out = [];
    exec(deploy_tar() . ' -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('не распаковался ' . basename($archive) . ': ' . implode(' ', array_slice($out, 0, 2)));
    }
}

/**
 * php -l для каждого PHP-файла. Ошибка синтаксиса в /pay/ — сломанная
 * оплата, в теме — белый экран блога, поэтому такую сборку не выкладываем.
 *
 * @param list<string> $files пути относительно $root
 * @return list<string> ошибки вида «pay/init.php: PHP Parse error …»
 */
function deploy_lint_php(string $root, array $files, string $label = ''): array
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $errors = [];
    foreach ($files as $rel) {
        if (!str_ends_with($rel, '.php')) {
            continue;
        }
        $out = [];
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $first = trim((string)($out[0] ?? 'ошибка синтаксиса'));
            $errors[] = $label . $rel . ': ' . str_replace($root . '/', '', $first);
        }
    }
    return $errors;
}

/**
 * Копирует файлы на место. Каждый пишется рядом под временным именем и
 * переименовывается: посетитель не получит наполовину записанную страницу.
 *
 * @param list<string> $files пути относительно $from
 */
function deploy_copy_files(string $from, array $files, string $to): void
{
    foreach ($files as $rel) {
        $dst = $to . '/' . $rel;
        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("не создать папку $dir");
        }
        $tmp = $dst . '.deploy-part';
        if (!copy($from . '/' . $rel, $tmp) || !rename($tmp, $dst)) {
            @unlink($tmp);
            throw new RuntimeException("не скопировать $rel");
        }
    }
}

/**
 * Удаляет устаревшие файлы сборки и опустевшие после этого папки.
 *
 * @param list<string> $files пути относительно $root
 * @return list<string> что действительно удалено
 */
function deploy_delete_files(string $root, array $files): array
{
    $deleted = [];
    foreach ($files as $rel) {
        if (deploy_is_protected($rel) || !is_file($root . '/' . $rel)) {
            continue;
        }
        if (unlink($root . '/' . $rel)) {
            $deleted[] = $rel;
            deploy_prune_dirs($root, dirname($rel));
        }
    }
    return $deleted;
}

/** Поднимается от папки к веб-корню и удаляет пустые; защищённые не трогает. */
function deploy_prune_dirs(string $root, string $relDir): void
{
    while ($relDir !== '.' && $relDir !== '' && !deploy_is_protected($relDir . '/')) {
        $abs = $root . '/' . $relDir;
        if (!is_dir($abs) || (new FilesystemIterator($abs))->valid() || !@rmdir($abs)) {
            return;
        }
        $relDir = dirname($relDir);
    }
}

/**
 * Раскладывает сборку на сайт.
 *
 * Испорченная сборка не трогает ни одного файла: распаковка во временную
 * папку и все проверки идут до копирования. Проверки: обязательные файлы,
 * ничего в защищённых путях, нет config.php в платёжном архиве, php -l.
 * Провал — DeployFatal, сайт остаётся прежним.
 *
 * @param array{site:string,pay:string} $archives
 * @param list<string>|null $previousFiles файлы прошлой сборки; null — первая выкладка, удалять нечего
 * @param bool $updatePay раскладывать ли платёжный архив (false — он не менялся)
 * @param string $work временная папка; очищается до и после
 * @param bool $dryRun только проверить и посчитать
 * @return array{files:list<string>,stale:list<string>,deleted:list<string>,payFiles:list<string>,payUpdated:bool}
 */
function deploy_apply(
    array $archives,
    ?array $previousFiles,
    string $webroot,
    bool $updatePay,
    string $work,
    bool $dryRun,
): array {
    deploy_rmtree($work);
    try {
        deploy_extract($archives['site'], $work . '/site');
        deploy_extract($archives['pay'], $work . '/pay');
        $files = deploy_list_files($work . '/site');
        $payFiles = deploy_list_files($work . '/pay');

        $problems = [];
        $missing = deploy_missing_required($files);
        if ($missing !== []) {
            $problems[] = 'нет обязательных файлов: ' . implode(', ', $missing);
        }
        $forbidden = deploy_forbidden_files($files);
        if ($forbidden !== []) {
            $problems[] = 'файлы в чужих путях: ' . implode(', ', array_slice($forbidden, 0, 5));
        }
        if (in_array('config.php', $payFiles, true)) {
            $problems[] = 'в платёжном архиве лежит config.php';
        }
        array_push(
            $problems,
            ...deploy_lint_php($work . '/site', $files),
            ...deploy_lint_php($work . '/pay', $payFiles, 'pay/'),
        );
        if ($problems !== []) {
            throw new DeployFatal(implode('; ', $problems));
        }

        $report = [
            'files' => $files,
            'stale' => $previousFiles === null ? [] : deploy_stale_files($previousFiles, $files),
            'deleted' => [],
            'payFiles' => $payFiles,
            'payUpdated' => false,
        ];
        if ($dryRun) {
            return $report;
        }

        deploy_copy_files($work . '/site', $files, $webroot);
        $report['deleted'] = deploy_delete_files($webroot, $report['stale']);
        if ($updatePay) {
            deploy_copy_files($work . '/pay', $payFiles, $webroot . '/pay');
            $report['payUpdated'] = true;
        }
        return $report;
    } finally {
        deploy_rmtree($work);
    }
}
