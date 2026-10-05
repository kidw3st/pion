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
