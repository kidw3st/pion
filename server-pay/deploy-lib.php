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

/**
 * Внутри защищённых путей сборке принадлежат только тема блога и файлы для
 * ИИ-ассистентов (.well-known/agent-skills/, их пишет каждая сборка). Остальное
 * в .well-known/ остаётся защищённым — например, проверка Let's Encrypt
 * в .well-known/acme-challenge/.
 */
const DEPLOY_OWNED_IN_PROTECTED = ['blog/wp-content/themes/pion/', '.well-known/agent-skills/'];

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
 * Код выхода php -l: 0 — файл в порядке; 255 — PHP разобрал файл и нашёл
 * ошибку, это вина сборки, и сообщение попадает в результат. Любой другой
 * ненулевой код (1 — файл не открылся, -1 — процесс не запустился,
 * 126/127 — нет PHP, 137 — убит лимитами хостинга) значит, что проверка
 * не состоялась. Это сбой окружения, а не доказательство, что сборка плоха:
 * из-за него хорошую сборку нельзя навсегда помечать испорченной
 * (DeployFatal). Поэтому бросается обычный RuntimeException, и выкладку
 * повторят при следующем запуске.
 *
 * @param list<string> $files пути относительно $root
 * @return list<string> ошибки вида «pay/init.php: PHP Parse error …»
 * @throws RuntimeException php -l не запустился (код выхода не 0 и не 255)
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
        if ($code === 255) {
            $first = trim((string)($out[0] ?? 'ошибка синтаксиса'));
            $errors[] = $label . $rel . ': ' . str_replace($root . '/', '', $first);
        } elseif ($code !== 0) {
            throw new RuntimeException("проверка синтаксиса не запустилась для $label$rel: код $code");
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

// --- Состояние выкладки ------------------------------------------------------

/** Сколько прошлых сборок хранится для отката (плюс текущая). */
const DEPLOY_KEEP_PREVIOUS = 2;
/**
 * Временный сбой (сеть, GitHub, не запустился tar или php -l) — пишем, если
 * он не прошёл за час: такое обычно проходит само.
 */
const DEPLOY_TRANSIENT_ALERT_AFTER = 3600;
/** Коммит в master 90 минут не стал сборкой — видимо, сборка падает. */
const DEPLOY_LAG_ALERT_AFTER = 5400;
/**
 * Одно и то же сообщение об одном и том же сбое — не чаще раза в 3 часа.
 * Другой сбой (новая испорченная сборка, новый коммит master) этим сроком не
 * связан: см. deploy_state_after_failure и deploy_note_master.
 */
const DEPLOY_ALERT_COOLDOWN = 10800;
/**
 * Копия базы делается раз в сутки — двое суток без неё значит, что
 * обслуживание не идёт.
 */
const DEPLOY_BACKUP_STALE_AFTER = 172800;
/**
 * Копии базы нет — за три часа это не проходит, а в служебный чат приходят и
 * заказы: напоминаем раз в сутки.
 */
const DEPLOY_BACKUP_ALERT_COOLDOWN = 86400;

/**
 * Состояние лежит в pion-deploy/state.json:
 *   current — выложенная сборка: sha (коммит ветки server-build), commit
 *             (коммит кода в master), paySha256, deployedAt и, если сборка
 *             собрана с каталогом, catalogVersion, catalogChangedAt ('' —
 *             каталог ещё не менялся) и catalogProducts (число товаров); по
 *             версии каталога админка показывает «на сайте» / «ждёт выкладки»
 *             (см. deploy_release_from_info);
 *   history — прошлые сборки для отката, новая первой;
 *   bad     — сборки, которые выкладывать нельзя: sha => причина;
 *   badPairs — пары «код + каталог» откатанных сборок (deploy_pair_key =>
 *             причина, последние 20): пересборка того же коммита с тем же
 *             каталогом получает новую sha, но пара у неё прежняя, и выкладка
 *             её не принимает;
 *   failure — текущий сбой: kind (transient|fatal), message, sha, since;
 *   alerts  — когда последний раз писали о сбое каждого рода (fatal,
 *             transient, lag и сторож каталога: catalog, backup,
 *             maintenance). Запись снимается, когда сбой кончился или
 *             начался другой: новая поломка не ждёт расписания прошлой.
 *             Сторожу каталога снимать нечего, у каждого рода свой срок:
 *             catalog — не чаще раза в DEPLOY_ALERT_COOLDOWN, backup — раза в
 *             DEPLOY_BACKUP_ALERT_COOLDOWN, maintenance — один раз на каждый
 *             неудавшийся ночной запуск (см. deploy_maintenance_alert_due);
 *   master  — последний увиденный коммит master и с какого времени: sha, since;
 *   head    — последняя увиденная сборка в ветке server-build и с какого
 *             времени: sha, since. По sha отличают поломку, которая ещё в
 *             силе, от устаревшей; по since — коммит, после которого сборки
 *             так и нет, от сборки, появившейся позже коммита.
 */
function deploy_empty_state(): array
{
    return [
        'current' => null, 'history' => [], 'bad' => [], 'badPairs' => [], 'failure' => null,
        'alerts' => [], 'master' => null, 'head' => null,
    ];
}

function deploy_state_load(string $home): array
{
    $raw = @file_get_contents($home . '/state.json');
    $state = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($state) ? $state + deploy_empty_state() : deploy_empty_state();
}

/**
 * Пишет состояние целиком: во временный файл, потом подменяет. В тексте сбоя
 * бывают байты не в UTF-8 (php -l цитирует файл как есть, хоть в CP1251): их
 * json_encode заменяет, а не отказывается писать — иначе из-за одной строки
 * состояние не сохранилось бы совсем.
 */
function deploy_state_save(string $home, array $state): void
{
    $json = json_encode(
        $state,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    $tmp = $home . '/state.json.part';
    if ($json === false || file_put_contents($tmp, $json . PHP_EOL) === false || !rename($tmp, $home . '/state.json')) {
        throw new RuntimeException('не записать state.json');
    }
}

/**
 * Сборка выложена: она становится текущей, прежняя уходит в историю, сбой
 * снимается вместе с расписанием сообщений о нём — следующий сбой сообщит о
 * себе сразу, а не по сроку прошлого.
 *
 * @param array{sha:string,commit:string,paySha256:string,catalogVersion?:string,catalogChangedAt?:string,catalogProducts?:int} $release
 */
function deploy_state_after_success(array $state, array $release, int $now): array
{
    if ($state['current'] !== null) {
        array_unshift($state['history'], $state['current']);
        $state['history'] = array_slice($state['history'], 0, DEPLOY_KEEP_PREVIOUS);
    }
    $release['deployedAt'] = $now;
    $state['current'] = $release;
    $state['failure'] = null;
    unset($state['alerts']['fatal'], $state['alerts']['transient']);
    return $state;
}

/** Помечает сборку плохой. Помним 20 последних — этого хватает с запасом. */
function deploy_mark_bad(array $state, string $sha, string $why): array
{
    unset($state['bad'][$sha]);
    $state['bad'][$sha] = $why;
    $state['bad'] = array_slice($state['bad'], -20, null, true);
    return $state;
}

/**
 * Записывает сбой. Время начала (since) и расписание сообщений относятся к
 * «эпизоду» сбоя:
 *   - сеть (transient): эпизод общий для любых сбоев подряд — опрос GitHub и
 *     скачивание сборки могут чередоваться, а час отсчитывается с первого;
 *   - испорченная сборка (fatal): эпизод — сама сборка, другая испорченная
 *     сборка начинает новый.
 * Новый эпизод сообщается по своему расписанию, а не по расписанию прошлого:
 * о новой испорченной сборке пишем сразу, даже если о прошлой писали недавно.
 *
 * Сбой сети не затирает поломку головы ветки: сборка всё ещё испорчена, и
 * сторож должен напоминать о ней дальше, а не забыть при первом же сбое сети.
 *
 * @param string $kind 'transient' | 'fatal'
 */
function deploy_state_after_failure(array $state, string $kind, string $message, ?string $sha, int $now): array
{
    $prev = $state['failure'];
    $headSha = $state['head']['sha'] ?? null;
    if ($kind === 'transient' && is_array($prev) && $prev['kind'] === 'fatal' && $prev['sha'] === $headSha) {
        return $state;
    }
    $same = is_array($prev) && $prev['kind'] === $kind && ($kind === 'transient' || $prev['sha'] === $sha);
    if (!$same) {
        unset($state['alerts'][$kind]);
    }
    $state['failure'] = [
        'kind' => $kind,
        'message' => $message,
        'sha' => $sha,
        'since' => $same ? $prev['since'] : $now,
    ];
    if ($kind === 'fatal' && $sha !== null) {
        $state = deploy_mark_bad($state, $sha, $message);
    }
    return $state;
}

/**
 * Пара «код + каталог» сборки. После отката той же пары быть не должно: иначе
 * пересборка того же коммита с тем же каталогом (новая sha в server-build)
 * снова выложила бы то, что откатили.
 */
function deploy_pair_key(array $release): string
{
    return (string)($release['commit'] ?? '') . '|' . (string)($release['catalogVersion'] ?? '');
}

function deploy_state_after_rollback(array $state, int $now): array
{
    $previous = $state['history'][0] ?? null;
    if ($state['current'] === null || $previous === null) {
        throw new RuntimeException('откатываться не на что: прошлой сборки нет');
    }
    $state = deploy_mark_bad($state, $state['current']['sha'], 'откат вручную');
    $state['badPairs'][deploy_pair_key($state['current'])] = 'откат вручную';
    $state['badPairs'] = array_slice($state['badPairs'], -20, null, true);
    array_shift($state['history']);
    $previous['deployedAt'] = $now;
    $state['current'] = $previous;
    $state['failure'] = null;
    return $state;
}

/**
 * Новой сборки нет. Сбой сети на этом прошёл. Поломка остаётся, пока
 * испорченная сборка всё ещё голова ветки: сторож напоминает о ней до тех
 * пор, пока не придёт новая. Если голова уже другая (ветку вернули или
 * пересобрали), поломка устарела и снимается.
 */
function deploy_state_idle(array $state): array
{
    $failure = $state['failure'];
    $headSha = $state['head']['sha'] ?? null;
    if (is_array($failure) && ($failure['kind'] === 'transient' || $failure['sha'] !== $headSha)) {
        $state['failure'] = null;
    }
    return $state;
}

/**
 * Запоминает последнюю сборку в ветке server-build и когда её увидели. Время
 * не меняется, пока сборка та же: по нему отличают коммит master, после
 * которого сборки так и нет, от сборки, появившейся позже коммита.
 */
function deploy_note_head(array $state, string $sha, int $now): array
{
    if (($state['head']['sha'] ?? null) !== $sha) {
        $state['head'] = ['sha' => $sha, 'since' => $now];
    }
    return $state;
}

/** Новый коммит master — новый отсчёт отставания и новое напоминание о нём. */
function deploy_note_master(array $state, string $sha, int $now): array
{
    if (($state['master']['sha'] ?? null) !== $sha) {
        $state['master'] = ['sha' => $sha, 'since' => $now];
        unset($state['alerts']['lag']);
    }
    return $state;
}

function deploy_alert_due(array $state, string $kind, int $now, int $cooldown = DEPLOY_ALERT_COOLDOWN): bool
{
    $last = $state['alerts'][$kind] ?? null;
    return !is_int($last) || $now - $last >= $cooldown;
}

/**
 * Пора ли писать о неудавшемся ночном обслуживании. Итог обслуживания меняется
 * раз в ночь, поэтому о каждом неудавшемся запуске пишем один раз: пора, если
 * запуск (maintenance.at) позже, чем время прошлого сообщения. Иначе один сбой
 * давал бы восемь сообщений в сутки по расписанию в 3 часа.
 *
 * Время запуска неизвестно (0 или нет) или оно в будущем (часы разошлись) —
 * по нему не понять, новый ли это запуск, тогда обычный срок в 3 часа.
 *
 * @param array{ok:bool,at:int,message:string} $maintenance
 */
function deploy_maintenance_alert_due(array $state, array $maintenance, int $now): bool
{
    $at = (int)($maintenance['at'] ?? 0);
    if ($at <= 0 || $at > $now) {
        return deploy_alert_due($state, 'maintenance', $now);
    }
    $last = $state['alerts']['maintenance'] ?? null;
    return !is_int($last) || $at > $last;
}

/**
 * О чём пора написать в служебный чат про выкладку сайта (о каталоге — в
 * deploy_pending_catalog_alerts).
 *
 * Пока выкладка сбоит, об отставании от master молчим: причина та же.
 *
 * Отставание — коммит master, по которому сборки нет уже 90 минут (не
 * получилась или не запустилась), в двух случаях: (А) коммит замечен ПОЗЖЕ
 * последней сборки; (Б) последняя сборка уже выложена, а коммита master в ней
 * нет — так ловится коммит, увиденный в одном запуске с предыдущей сборкой, у
 * которого время равно времени сборки. После отката или отклонения сборки
 * тревоги нет: она остаётся последней в ветке, но не выложена, и новых
 * коммитов после неё нет.
 *
 * @return list<string> 'fatal' | 'transient' | 'lag'
 */
function deploy_pending_build_alerts(array $state, int $now): array
{
    $failure = $state['failure'];
    if (is_array($failure)) {
        $ripe = $failure['kind'] === 'fatal' || $now - $failure['since'] >= DEPLOY_TRANSIENT_ALERT_AFTER;
        // Пока выкладка сбоит, об отставании от master не пишем: причина та же.
        return $ripe && deploy_alert_due($state, $failure['kind'], $now) ? [$failure['kind']] : [];
    }
    $master = $state['master'];
    $head = $state['head'];
    $current = $state['current'];
    if (!is_array($master) || !is_array($head)) {
        return [];
    }
    $laterThanBuild = $master['since'] > $head['since'];
    $buildLacksMaster = is_array($current) && ($head['sha'] ?? null) === $current['sha']
        && $master['sha'] !== $current['commit'];
    if (($laterThanBuild || $buildLacksMaster)
        && $now - $master['since'] >= DEPLOY_LAG_ALERT_AFTER && deploy_alert_due($state, 'lag', $now)) {
        return ['lag'];
    }
    return [];
}

/**
 * О чём пора написать про каталог. $watch — то, что прочитала
 * deploy_catalog_watch (базы нет — сюда не попадаем).
 *
 *   catalog     — версия каталога в базе не та, что на сайте, и последняя
 *                 правка и выкладка текущей сборки (current.deployedAt) обе
 *                 старше 90 минут; время выкладки из будущего (часы на
 *                 сервере перевели назад) не берётся, отсчёт идёт от правки.
 *                 Если выкладка сбоит, не пишем: о ней
 *                 и так пишут. Не пишем и когда сравнивать не с чем — как и
 *                 админка («Сайт пока собирается из прежнего каталога»): на
 *                 сайте нет версии каталога (ещё ничего не выкладывалось или
 *                 сборка без каталога) либо в базе не было ни одной правки;
 *   backup      — копии базы нет совсем или последняя старше двух суток;
 *   maintenance — ночное обслуживание записало, что не удалось.
 *
 * Копии и обслуживание от выкладки не зависят и пишутся и при её сбое. Не чаще:
 * catalog — раза в 3 часа (DEPLOY_ALERT_COOLDOWN), backup — раза в сутки
 * (DEPLOY_BACKUP_ALERT_COOLDOWN), maintenance — раза на каждый неудавшийся запуск
 * (deploy_maintenance_alert_due).
 *
 * @param array{version:string,changedAt:int,backupAt:?int,maintenance:?array{ok:bool,at:int,message:string}} $watch
 * @return list<string> 'catalog' | 'backup' | 'maintenance'
 */
function deploy_pending_catalog_alerts(array $state, int $now, array $watch): array
{
    $kinds = [];
    $deployed = $state['current']['catalogVersion'] ?? null;
    $version = (string)($watch['version'] ?? '');
    // 90 минут отсчитываются от последней правки, но не раньше выкладки текущей
    // сборки. Расхождение бывает и без правки: новый код выгрузки едет в /pay/
    // той же сборкой, что собрана по выгрузке старого кода, и с её выкладки
    // версия базы уже другая. Ближайшая сборка по расписанию (до 15 минут)
    // возьмёт выгрузку новым кодом, следующий запуск выкладки её привезёт —
    // меньше 90 минут, а от старой правки тревога ушла бы сразу. Настоящее
    // отставание так не прячется: при включённом переключателе каждая сборка
    // берёт живую выгрузку, и выкладка после правки сдвигает отсчёт самое
    // большее на одну сборку; пока сборки не выкладываются, он не сдвигается.
    // Времени выкладки в состоянии нет — отсчёт только от правки.
    // Время выкладки из будущего не берём: оно бывает, если на сервере перевели
    // часы назад (deploy.php пишет его через time()), и сообщение не пришло бы
    // до этого времени. Тогда отсчёт идёт от правки.
    $deployedAt = $state['current']['deployedAt'] ?? null;
    $since = max((int)($watch['changedAt'] ?? 0), is_int($deployedAt) && $deployedAt <= $now ? $deployedAt : 0);
    if (!is_array($state['failure'] ?? null)
        && is_string($deployed) && $deployed !== ''
        && $version !== '' && $version !== $deployed
        && $now - $since >= DEPLOY_LAG_ALERT_AFTER
        && deploy_alert_due($state, 'catalog', $now)) {
        $kinds[] = 'catalog';
    }
    $backupAt = $watch['backupAt'] ?? null;
    if (($backupAt === null || $now - $backupAt > DEPLOY_BACKUP_STALE_AFTER)
        && deploy_alert_due($state, 'backup', $now, DEPLOY_BACKUP_ALERT_COOLDOWN)) {
        $kinds[] = 'backup';
    }
    $maintenance = $watch['maintenance'] ?? null;
    if (is_array($maintenance) && ($maintenance['ok'] ?? true) === false
        && deploy_maintenance_alert_due($state, $maintenance, $now)) {
        $kinds[] = 'maintenance';
    }
    return $kinds;
}

/**
 * Всё, о чём пора написать в служебный чат: про выкладку сайта и, если база
 * каталога есть ($watch не null), про каталог.
 *
 * @param array|null $watch результат deploy_catalog_watch
 * @return list<string> 'fatal' | 'transient' | 'lag' | 'catalog' | 'backup' | 'maintenance'
 */
function deploy_pending_alerts(array $state, int $now, ?array $watch = null): array
{
    $kinds = deploy_pending_build_alerts($state, $now);
    return $watch === null ? $kinds : [...$kinds, ...deploy_pending_catalog_alerts($state, $now, $watch)];
}

function deploy_mark_alerted(array $state, string $kind, int $now): array
{
    $state['alerts'][$kind] = $now;
    return $state;
}

/** deploy_telegram: сообщение ушло. */
const DEPLOY_TELEGRAM_SENT = 'отправлено';
/** deploy_telegram: служебный чат не настроен — повторять бесполезно. */
const DEPLOY_TELEGRAM_OFF = 'служебный чат не настроен (DEPLOY_ALERT_CHAT_ID в config.php)';

/**
 * Отправляет всё, о чём пора написать (deploy_pending_alerts), и отмечает
 * отправленное. Telegram не принял сообщение (сеть, ответ не 200) — отметки
 * нет: следующий запуск выкладки, через 15 минут, попробует снова. Иначе
 * сообщение терялось бы на весь перерыв между сообщениями: о копии — на сутки,
 * об обслуживании — до следующего неудачного запуска, об остальном — на 3 часа.
 * Чат не настроен — отмечаем: повтор ничего не изменит, а журнал заполнился бы
 * одной и той же строкой.
 *
 * @param Closure(string): string $send текст → ответ deploy_telegram
 * @param Closure(string): void   $log  строка в deploy.log
 */
function deploy_send_alerts(array $state, int $now, ?array $watch, Closure $send, Closure $log): array
{
    foreach (deploy_pending_alerts($state, $now, $watch) as $kind) {
        $result = $send(deploy_alert_text($kind, $state, $watch));
        $log("сообщение о сбое ($kind): $result");
        if ($result === DEPLOY_TELEGRAM_SENT || $result === DEPLOY_TELEGRAM_OFF) {
            $state = deploy_mark_alerted($state, $kind, $now);
        }
    }
    return $state;
}

/**
 * Текст сообщения. $watch нужен сообщению об обслуживании (его причина лежит
 * в maintenance.json); остальным он не нужен.
 *
 * @param array|null $watch результат deploy_catalog_watch
 */
function deploy_alert_text(string $kind, array $state, ?array $watch = null): string
{
    $failure = $state['failure'] ?? [];
    $build = substr((string)($failure['sha'] ?? ''), 0, 7);
    $message = (string)($failure['message'] ?? '');
    $master = substr((string)($state['master']['sha'] ?? ''), 0, 7);
    // Причина обслуживания — из maintenance.json: первая строка, не длиннее 300
    // знаков (обрезанная кончается «…»). Точку в конце ставит сам текст; после «…» её нет — и
    // когда обрезал сторож, и когда причину в 300 знаков с «…» уже обрезало само обслуживание.
    $why = trim((string)($watch['maintenance']['message'] ?? ''));
    // Строки делим по \r\n, \r и \n явно: без флага /u «\R» совпал бы и с байтом 0x85, а это
    // вторая половина «х» (D1 85) — причина обрывалась бы на первой «х». С флагом /u на битом
    // UTF-8 preg_split вернул бы false, и причина пропала бы вовсе.
    $why = trim(rtrim(trim(preg_split('/\r\n|\r|\n/', $why, 2)[0] ?? ''), '.'));
    $cut = mb_strlen($why) > 300;
    if ($cut) {
        $why = rtrim(mb_substr($why, 0, 299)) . '…';
    }
    return match ($kind) {
        'fatal' => "Выкладка pionperm.ru остановлена: сборка $build не прошла проверку — $message. Сайт работает на прежней сборке.",
        'transient' => "Выкладка pionperm.ru: больше часа не получается выложить новую сборку — $message.",
        'lag' => "Выкладка pionperm.ru: коммит $master в master больше 90 минут не превращается в сборку. Проверьте GitHub Actions.",
        'catalog' => 'Каталог pionperm.ru: изменения из админки больше 90 минут не на сайте. Проверьте GitHub Actions («Сборка и выкладка»).',
        'backup' => 'Каталог pionperm.ru: свежей копии базы нет больше двух суток — проверьте задание обслуживания.',
        'maintenance' => 'Каталог pionperm.ru: ночное обслуживание не удалось — '
            . ($why !== '' ? $why : 'причина не записана') . (str_ends_with($why, '…') ? '' : '.'),
        default => "Выкладка pionperm.ru: $kind",
    };
}

// --- Сторож каталога -------------------------------------------------------

/**
 * Что сторож каталога видит в папке каталога (pion-catalog):
 *   version, changedAt — версия, которую выгрузка отдала бы сейчас
 *                        (catalog_current_version), и время последней правки
 *                        из meta базы (секунды); правок не было (в meta нет
 *                        версии) — '' и 0. Оба — из одного снимка базы;
 *   backupAt           — время самой свежей копии backups/catalog-*.sqlite;
 *                        null — копий нет;
 *   maintenance        — итог ночного обслуживания из maintenance.json
 *                        ({ok, at, message}; по контракту его пишет после
 *                        каждого запуска catalog/maintenance-cli.php);
 *                        null — файла нет или он не по контракту.
 *
 * Нет файла базы (каталог ещё не переносили) — null: ни базы, ни папки не
 * создаётся, и ни о чём в каталоге не пишем.
 *
 * Копии и итог обслуживания читаются независимо от базы. База не открылась, не
 * читается или новее кода — версия '' и время 0 (молчит только catalog, а о
 * копиях и обслуживании сторож пишет, как обычно), текст ошибки — в $dbError;
 * журнал пишет вызывающий. Иначе нечитаемая база заглушила бы и тревогу о том,
 * что копии не делаются.
 *
 * Выгрузка не считается (например, испорченный JSON в строке базы — сайт её
 * тогда тоже не получит) — версия из meta, то есть последнего сохранения, а
 * текст ошибки — в $dbError: так сторож о каталоге не замолкает.
 *
 * Базу открывает общая catalog_db_open, поэтому «только читает» неточно: она
 * переводит старую схему на текущую и создаёт недостающие таблицы — и в пустом
 * файле тоже. Данные базы сторож не меняет.
 *
 * Код базы подключается здесь, а не вверху файла: deploy-lib.php подключает и
 * scripts/check-server-build.php, которому каталог не нужен. Код выгрузки — только
 * когда файл базы есть.
 *
 * @param string|null $dbError сюда кладётся текст ошибки базы; null — база прочиталась
 * @return array{version:string,changedAt:int,backupAt:?int,maintenance:?array{ok:bool,at:int,message:string}}|null
 */
function deploy_catalog_watch(string $catalogHome, string $dbFile, ?string &$dbError = null): ?array
{
    require_once __DIR__ . '/catalog/db.php';
    $dbError = null;
    if (!is_file($dbFile)) {
        return null;
    }
    require_once __DIR__ . '/catalog/export.php';
    $text = static fn(mixed $value): string => is_scalar($value) ? (string)$value : '';
    $version = '';
    $changedAt = 0;
    try {
        $db = catalog_db_open($dbFile);
        [$version, $changedAt, $exportError] = catalog_read($db, static function () use ($db, $text): array {
            $meta = catalog_meta($db);
            $saved = $text($meta['version'] ?? '');
            $changedAt = (int)strtotime($text($meta['changed_at'] ?? ''));
            if ($saved === '') {
                return ['', $changedAt, null];
            }
            try {
                return [catalog_current_version($db), $changedAt, null];
            } catch (Throwable $e) {
                return [$saved, $changedAt, 'выгрузка каталога не считается: ' . $e->getMessage()];
            }
        });
        $db = null;
        $dbError = $exportError;
    } catch (Throwable $e) {
        $version = '';
        $changedAt = 0;
        $dbError = $e->getMessage();
    }

    $backupAt = null;
    foreach (@scandir($catalogHome . '/backups') ?: [] as $name) {
        $file = $catalogHome . '/backups/' . $name;
        if (str_starts_with($name, 'catalog-') && str_ends_with($name, '.sqlite') && is_file($file)) {
            $at = @filemtime($file);
            if ($at !== false && ($backupAt === null || $at > $backupAt)) {
                $backupAt = $at;
            }
        }
    }

    $raw = @file_get_contents($catalogHome . '/maintenance.json');
    $done = is_string($raw) ? json_decode($raw, true) : null;
    $maintenance = is_array($done) && is_bool($done['ok'] ?? null)
        ? ['ok' => $done['ok'], 'at' => (int)strtotime($text($done['at'] ?? '')), 'message' => $text($done['message'] ?? '')]
        : null;

    return [
        'version' => $version,
        'changedAt' => $changedAt,
        'backupAt' => $backupAt,
        'maintenance' => $maintenance,
    ];
}

// --- Скачанные сборки --------------------------------------------------------

function deploy_release_dir(string $home, string $sha): string
{
    return $home . '/releases/' . $sha;
}

/**
 * Запись о сборке для state.json — из её build-info.json (его пишет
 * scripts/pack-build.mjs).
 *
 * Ключи catalog* есть, только если в build-info есть catalog (массив со
 * строковой version): сборка без каталога о нём ничего не говорит, и админка
 * считает выложенное неизвестным. catalogChangedAt — '' для сборки, где каталог
 * ещё ни разу не менялся (в build-info там null). Выкладка не проверяет вид
 * времени: это делает сборка (pack-build.mjs), а админка при чтении.
 *
 * @param mixed $info build-info.json после json_decode(.., true)
 * @return array{sha:string,commit:string,paySha256:string,catalogVersion?:string,catalogChangedAt?:string,catalogProducts?:int}
 */
function deploy_release_from_info(string $sha, mixed $info): array
{
    if (!is_array($info)) {
        $info = [];
    }
    $release = [
        'sha' => $sha,
        'commit' => (string)($info['commit'] ?? ''),
        'paySha256' => (string)($info['pay']['sha256'] ?? ''),
    ];
    // Версия выложенного каталога — по ней админка показывает «на сайте» / «ждёт выкладки».
    if (is_array($info['catalog'] ?? null) && is_string($info['catalog']['version'] ?? null)) {
        $release['catalogVersion'] = $info['catalog']['version'];
        $release['catalogChangedAt'] = is_string($info['catalog']['changedAt'] ?? null) ? $info['catalog']['changedAt'] : '';
        $release['catalogProducts'] = (int)($info['catalog']['products'] ?? 0);
    }
    return $release;
}

/**
 * Файлы, которые разложила сборка. null — неизвестно (ещё не выкладывалась
 * или выложена до новой схемы): тогда удалять нечего.
 *
 * @return list<string>|null
 */
function deploy_release_files(string $home, ?string $sha): ?array
{
    if ($sha === null) {
        return null;
    }
    $raw = @file_get_contents(deploy_release_dir($home, $sha) . '/files.txt');
    if (!is_string($raw)) {
        return null;
    }
    return array_values(array_filter(
        explode("\n", str_replace("\r", '', $raw)),
        static fn(string $line): bool => $line !== '',
    ));
}

/** @param list<string> $files */
function deploy_save_release_files(string $home, string $sha, array $files): void
{
    if (file_put_contents(deploy_release_dir($home, $sha) . '/files.txt', implode("\n", $files) . "\n") === false) {
        throw new RuntimeException('не записать список файлов сборки');
    }
}

/**
 * Удаляет скачанные сборки, кроме текущей и прошлых для отката, и
 * недокачанные (.part).
 *
 * @return list<string> имена удалённых папок
 */
function deploy_prune_releases(string $home, array $state): array
{
    $keep = array_column(array_filter([$state['current'], ...$state['history']]), 'sha');
    $removed = [];
    foreach (glob($home . '/releases/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!in_array(basename($dir), $keep, true)) {
            deploy_rmtree($dir);
            $removed[] = basename($dir);
        }
    }
    sort($removed, SORT_STRING);
    return $removed;
}
