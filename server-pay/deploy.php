<?php
/**
 * Выкладка сборки сайта с GitHub на этот сервер.
 *
 * Сборку делает GitHub Actions (.github/workflows/deploy.yml) на каждый
 * коммит в master и кладёт в ветку server-build три файла: pion-site.tar.gz,
 * pion-pay.tar.gz и build-info.json с их sha256. Расписание ISPmanager
 * запускает этот файл раз в 15 минут.
 *
 *   php deploy.php               есть новая сборка — проверить и выложить
 *   php deploy.php --dry-run     скачать и проверить, ничего не меняя
 *   php deploy.php --rollback    вернуть предыдущую сборку
 *   php deploy.php --status      что выложено и были ли сбои
 *   php deploy.php --find-chat   id чатов, писавших боту, — для DEPLOY_ALERT_CHAT_ID
 *   php deploy.php --test-alert  проверочное сообщение в служебный чат
 *
 * Всё, что не ходит в сеть, — в deploy-lib.php и проверено в tests/php/.
 * Подробности — docs/deploy.md в репозитории.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/deploy-lib.php';
// Конфиг нужен только ради Telegram: без него выкладка всё равно работает.
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

date_default_timezone_set('Asia/Yekaterinburg');

const DEPLOY_REPO = 'kidw3st/pion';
const DEPLOY_BRANCH = 'server-build';
const DEPLOY_ARCHIVES = ['site' => 'pion-site.tar.gz', 'pay' => 'pion-pay.tar.gz'];

/** Сервер ответил «нет такого файла» — в отличие от сетевого сбоя, сам не пройдёт. */
final class DeployNotFound extends RuntimeException
{
}

/**
 * GET по HTTPS. В тексте ошибки только хост: в адресе Telegram есть токен.
 * С $saveTo ответ пишется прямо в файл — архив сайта весит мегабайты.
 */
function deploy_http(string $url, ?string $saveTo = null, int $timeout = 60): string
{
    $options = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'pion-deploy',
    ];
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if ($fh === false) {
            throw new RuntimeException("не открыть $saveTo");
        }
        $options[CURLOPT_FILE] = $fh;
    } else {
        $options[CURLOPT_RETURNTRANSFER] = true;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($fh !== null) {
        fclose($fh);
    }
    if ($body === false || $code !== 200) {
        if ($saveTo !== null) {
            @unlink($saveTo);
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $why = $error !== '' ? $error : "код $code";
        if ($code === 404) {
            throw new DeployNotFound("$host: нет файла ($why)");
        }
        throw new RuntimeException("$host не ответил: $why");
    }
    return $saveTo === null ? (string)$body : '';
}

/** Строка в pion-deploy/deploy.log и на экран. Лог больше 512 КБ уходит в deploy.log.1. */
function deploy_log(string $home, string $message): void
{
    $log = $home . '/deploy.log';
    if (is_file($log) && filesize($log) > 512 * 1024) {
        @rename($log, $log . '.1');
    }
    $line = date('Y-m-d H:i:s') . ' | ' . $message;
    @file_put_contents($log, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $line, PHP_EOL;
}

/** Сообщение в служебный чат. Не в чат заказов: салону это ни к чему. */
function deploy_telegram(string $text): string
{
    $token = defined('TELEGRAM_TOKEN') ? (string)TELEGRAM_TOKEN : '';
    $chat = defined('DEPLOY_ALERT_CHAT_ID') ? (string)DEPLOY_ALERT_CHAT_ID : '';
    if ($token === '' || $chat === '') {
        return DEPLOY_TELEGRAM_OFF;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        // В тексте бывает вывод php -l с байтами не в UTF-8: сначала их
        // заменяем, потом обрезаем (mb_substr битые байты не трогает).
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chat,
            'text' => mb_substr(mb_scrub($text, 'UTF-8'), 0, 4000),
            'disable_web_page_preview' => 'true',
        ]),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    // Принято — только если Telegram ответил 200 и в разобранном JSON ok === true: подстрока
    // «"ok":true» нашлась бы и в чужом тексте (например, в описании ошибки).
    $reply = json_decode((string)$raw, true);
    if ($code === 200 && is_array($reply) && ($reply['ok'] ?? false) === true) {
        return DEPLOY_TELEGRAM_SENT;
    }
    // Причина от самого Telegram («Forbidden: bot was blocked by the user») — в журнал: по одному коду её не понять.
    $why = is_array($reply) && is_string($reply['description'] ?? null)
        ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $reply['description'])), 0, 300)
        : '';
    return "Telegram ответил $code" . ($err !== '' ? " ($err)" : '') . ($why !== '' ? " — $why" : '');
}

/**
 * Что сторож каталога видит в папке каталога (см. deploy_catalog_watch).
 *
 * Сторож не должен ронять выкладку. База не открылась, не читается или новее
 * кода — строка в журнал; о копиях и обслуживании сторож пишет всё равно, молчит
 * только о каталоге (deploy_catalog_watch). Не получилось прочитать совсем —
 * строка в журнал и null, сторож молчит обо всём. Папка каталога — по
 * PION_CATALOG_HOME или рядом с pion-deploy (catalog_home()).
 */
function deploy_read_catalog_watch(string $home): ?array
{
    try {
        // Нет файла — require_once упал бы мимо catch и уронил бы выкладку. export.php сторож подключает сам.
        foreach (['db.php', 'export.php'] as $name) {
            if (!is_file(__DIR__ . '/catalog/' . $name)) {
                throw new RuntimeException('нет ' . __DIR__ . '/catalog/' . $name);
            }
        }
        require_once __DIR__ . '/catalog/db.php';
        $watch = deploy_catalog_watch(catalog_home(), catalog_db_path(), $dbError);
        if ($dbError !== null) {
            deploy_log($home, 'не прочитать базу каталога для сторожа: ' . $dbError);
        }
        return $watch;
    } catch (Throwable $e) {
        deploy_log($home, 'не прочитать базу каталога для сторожа: ' . $e->getMessage());
        return null;
    }
}

/**
 * Отправляет то, о чём пора написать, и сохраняет состояние. $watch — что
 * сторож каталога прочитал в этом запуске (deploy_read_catalog_watch); null —
 * файла базы нет (или нет catalog/db.php): о каталоге молчим совсем. Нечитаемая
 * база — не null: версии в таком $watch нет, о каталоге молчим, а о копиях и
 * обслуживании сторож пишет, как обычно.
 */
function deploy_finish(string $home, array $state, int $now, ?array $watch = null): void
{
    $state = deploy_send_alerts(
        $state,
        $now,
        $watch,
        static fn(string $text): string => deploy_telegram($text),
        static function (string $line) use ($home): void {
            deploy_log($home, $line);
        },
    );
    deploy_state_save($home, $state);
}

/**
 * Скачивает сборку в releases/<sha>/ и сверяет sha256 с build-info.json.
 * Уже скачанная и проверенная сборка берётся из папки.
 *
 * Запись о сборке составляет deploy_release_from_info (deploy-lib.php).
 *
 * @return array{sha:string,commit:string,paySha256:string,catalogVersion?:string,catalogChangedAt?:string,catalogProducts?:int}
 */
function deploy_fetch_release(string $home, string $sha): array
{
    $dir = deploy_release_dir($home, $sha);
    if (!is_file($dir . '/build-info.json')) {
        $part = $dir . '.part';
        deploy_rmtree($part);
        if (!mkdir($part, 0755, true)) {
            throw new RuntimeException("не создать $part");
        }
        $base = 'https://raw.githubusercontent.com/' . DEPLOY_REPO . '/' . $sha . '/';
        try {
            foreach (['build-info.json', ...array_values(DEPLOY_ARCHIVES)] as $name) {
                deploy_http($base . $name, $part . '/' . $name, 600);
            }
        } catch (DeployNotFound $e) {
            throw new DeployFatal('в ветке ' . DEPLOY_BRANCH . ' не хватает файлов — ' . $e->getMessage());
        }
        $info = json_decode((string)file_get_contents($part . '/build-info.json'), true);
        if (!is_array($info)) {
            throw new DeployFatal('build-info.json не читается');
        }
        $errors = deploy_check_archives($info, [
            'site' => $part . '/' . DEPLOY_ARCHIVES['site'],
            'pay' => $part . '/' . DEPLOY_ARCHIVES['pay'],
        ]);
        if ($errors !== []) {
            // Скачано не то, что собрал GitHub, — скорее всего, оборвалась
            // загрузка. Через 15 минут сервер скачает заново.
            throw new RuntimeException(implode('; ', $errors));
        }
        if (!rename($part, $dir)) {
            throw new RuntimeException("не переименовать $part");
        }
    }
    $info = json_decode((string)file_get_contents($dir . '/build-info.json'), true);
    return deploy_release_from_info($sha, $info);
}

/** @return array{site:string,pay:string} */
function deploy_release_archives(string $home, string $sha): array
{
    $dir = deploy_release_dir($home, $sha);
    return ['site' => $dir . '/' . DEPLOY_ARCHIVES['site'], 'pay' => $dir . '/' . DEPLOY_ARCHIVES['pay']];
}

/** @param array|null $watch что сторож каталога прочитал в этом запуске; передаётся в каждый deploy_finish */
function deploy_run(string $home, string $webroot, bool $dryRun, ?array $watch = null): int
{
    $now = time();
    $state = deploy_state_load($home);

    try {
        $refs = deploy_parse_refs(deploy_http('https://github.com/' . DEPLOY_REPO . '.git/info/refs?service=git-upload-pack'));
        if (!isset($refs[DEPLOY_BRANCH])) {
            throw new RuntimeException('на GitHub нет ветки ' . DEPLOY_BRANCH);
        }
    } catch (RuntimeException $e) {
        deploy_log($home, 'не узнать, есть ли новая сборка: ' . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'transient', $e->getMessage(), null, $now), $now, $watch);
        }
        return 1;
    }
    $sha = $refs[DEPLOY_BRANCH];
    $state = deploy_note_head($state, $sha, $now);
    if (isset($refs['master'])) {
        $state = deploy_note_master($state, $refs['master'], $now);
    }

    if (!$dryRun && ($sha === ($state['current']['sha'] ?? null) || isset($state['bad'][$sha]))) {
        deploy_finish($home, deploy_state_idle($state), $now, $watch);
        return 0;
    }

    $short = substr($sha, 0, 7);
    try {
        $release = deploy_fetch_release($home, $sha);
        $pair = deploy_pair_key($release);
        if (isset($state['badPairs'][$pair])) {
            $why = 'повторяет откатанную сборку (код ' . substr($release['commit'], 0, 7) . ', каталог '
                . substr((string)($release['catalogVersion'] ?? '—'), 0, 7) . ')';
            deploy_log($home, "сборка $short не выложена: $why");
            if (!$dryRun) {
                deploy_finish($home, deploy_state_idle(deploy_mark_bad($state, $sha, $why)), $now, $watch);
            }
            return 0;
        }
        $updatePay = $release['paySha256'] !== ($state['current']['paySha256'] ?? null);
        $report = deploy_apply(
            deploy_release_archives($home, $sha),
            deploy_release_files($home, $state['current']['sha'] ?? null),
            $webroot,
            $updatePay,
            $home . '/work',
            $dryRun,
        );
    } catch (DeployFatal $e) {
        deploy_log($home, "сборка $short отклонена: " . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'fatal', $e->getMessage(), $sha, $now), $now, $watch);
        }
        return 1;
    } catch (RuntimeException $e) {
        deploy_log($home, "сборка $short не выложена: " . $e->getMessage());
        if (!$dryRun) {
            deploy_finish($home, deploy_state_after_failure($state, 'transient', $e->getMessage(), $sha, $now), $now, $watch);
        }
        return 1;
    }

    $summary = sprintf(
        'сборка %s (коммит %s): файлов %d, удалено %d, /pay/ %s',
        $short,
        substr($release['commit'], 0, 7),
        count($report['files']),
        count($dryRun ? $report['stale'] : $report['deleted']),
        $updatePay ? 'обновлён' : 'без изменений',
    );
    if ($dryRun) {
        echo 'Пробный прогон, ничего не изменено. Выкладка дала бы: ', $summary, PHP_EOL;
        foreach (array_slice($report['stale'], 0, 50) as $path) {
            echo '  удалить: ', $path, PHP_EOL;
        }
        return 0;
    }

    deploy_save_release_files($home, $sha, $report['files']);
    $state = deploy_state_after_success($state, $release, $now);
    // Сначала состояние: сайт уже на новой сборке, и это должно быть записано,
    // что бы ни случилось с уборкой старых сборок.
    deploy_state_save($home, $state);
    try {
        foreach (deploy_prune_releases($home, $state) as $old) {
            deploy_log($home, 'удалена старая сборка ' . substr($old, 0, 7));
        }
    } catch (RuntimeException $e) {
        deploy_log($home, 'не удалось удалить старые сборки: ' . $e->getMessage());
    }
    deploy_log($home, 'выложена ' . $summary);
    deploy_finish($home, $state, $now, $watch);
    return 0;
}

function deploy_rollback(string $home, string $webroot): int
{
    $state = deploy_state_load($home);
    $previous = $state['history'][0] ?? null;
    if ($state['current'] === null || $previous === null) {
        echo 'Откатываться не на что: прошлой сборки нет.', PHP_EOL;
        return 1;
    }
    try {
        $report = deploy_apply(
            deploy_release_archives($home, $previous['sha']),
            deploy_release_files($home, $state['current']['sha']),
            $webroot,
            $previous['paySha256'] !== $state['current']['paySha256'],
            $home . '/work',
            false,
        );
    } catch (RuntimeException $e) {
        // Отказать может и на распаковке (сайт цел), и посреди копирования
        // (часть файлов уже новая), поэтому «сайт не менялся» не обещаем.
        deploy_log($home, 'Откат не удался: ' . $e->getMessage()
            . '. Если копирование уже началось, сайт мог измениться частично — запустите откат ещё раз.');
        return 1;
    }
    deploy_save_release_files($home, $previous['sha'], $report['files']);
    $bad = substr($state['current']['sha'], 0, 7);
    deploy_state_save($home, deploy_state_after_rollback($state, time()));
    deploy_log($home, sprintf(
        'откат: сборка %s помечена плохой, выложена %s (коммит %s), удалено файлов %d',
        $bad,
        substr($previous['sha'], 0, 7),
        substr($previous['commit'], 0, 7),
        count($report['deleted']),
    ));
    return 0;
}

function deploy_status(string $home): int
{
    $state = deploy_state_load($home);
    $current = $state['current'];
    if ($current === null) {
        echo 'Ещё ничего не выкладывалось.', PHP_EOL;
    } else {
        printf(
            'Выложена сборка %s (коммит %s) %s.%s',
            substr($current['sha'], 0, 7),
            substr($current['commit'], 0, 7),
            date('d.m.Y H:i', $current['deployedAt']),
            PHP_EOL,
        );
    }
    if ($state['history'] !== []) {
        echo 'Для отката хранятся: ',
            implode(', ', array_map(static fn(array $r): string => substr($r['sha'], 0, 7), $state['history'])),
            PHP_EOL;
    }
    foreach ($state['bad'] as $sha => $why) {
        printf('Не выкладывать %s: %s%s', substr((string)$sha, 0, 7), $why, PHP_EOL);
    }
    $failure = $state['failure'];
    if (is_array($failure)) {
        printf(
            'Сбой (%s) с %s: %s%s',
            $failure['kind'] === 'fatal' ? 'сборка испорчена' : 'временный',
            date('d.m.Y H:i', $failure['since']),
            $failure['message'],
            PHP_EOL,
        );
    }
    return 0;
}

/** id чатов, которые писали боту за последние сутки: так узнаётся DEPLOY_ALERT_CHAT_ID. */
function deploy_find_chat(): int
{
    $token = defined('TELEGRAM_TOKEN') ? (string)TELEGRAM_TOKEN : '';
    if ($token === '') {
        echo 'В config.php нет TELEGRAM_TOKEN.', PHP_EOL;
        return 1;
    }
    try {
        $updates = json_decode(deploy_http('https://api.telegram.org/bot' . $token . '/getUpdates', null, 20), true);
    } catch (RuntimeException $e) {
        echo 'Telegram: ', $e->getMessage(), PHP_EOL;
        return 1;
    }
    $chats = [];
    foreach (($updates['result'] ?? []) as $update) {
        $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;
        if (is_array($chat)) {
            $chats[(string)$chat['id']] = $chat['title']
                ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
        }
    }
    if ($chats === []) {
        echo 'Боту никто не писал за последние сутки. Напишите ему что-нибудь и запустите снова.', PHP_EOL;
        return 1;
    }
    foreach ($chats as $id => $name) {
        echo $id, ' — ', $name, PHP_EOL;
    }
    return 0;
}

function deploy_usage(): int
{
    echo 'Режимы: без аргумента, --dry-run, --rollback, --status, --find-chat, --test-alert.', PHP_EOL;
    return 2;
}

$webroot = rtrim(getenv('PION_DEPLOY_WEBROOT') ?: dirname(__DIR__), '/');
$home = rtrim(getenv('PION_DEPLOY_HOME') ?: dirname($webroot, 2) . '/pion-deploy', '/');
if (!is_dir($home) && !mkdir($home, 0700, true) && !is_dir($home)) {
    fwrite(STDERR, "Не создать $home" . PHP_EOL);
    exit(2);
}

$mode = $argv[1] ?? '';
if ($mode === '--status') {
    exit(deploy_status($home));
}
if ($mode === '--find-chat') {
    exit(deploy_find_chat());
}
if ($mode === '--test-alert') {
    echo deploy_telegram('Проверка: сообщения о выкладке pionperm.ru приходят сюда.'), PHP_EOL;
    exit(0);
}

// Выкладка и откат — по одному: расписание не должно запустить вторую
// выкладку, пока первая ещё копирует файлы.
$lockFile = $home . '/deploy.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    // Это не «занято»: замок не открывается, и каждый запуск кончится так же.
    fwrite(STDERR, "Не открыть $lockFile — проверьте права на папку pion-deploy." . PHP_EOL);
    exit(2);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Другая выкладка ещё идёт.', PHP_EOL;
    exit(0);
}

try {
    exit(match ($mode) {
        // База каталога читается один раз, уже под замком и до любого deploy_finish.
        // Пробный прогон, откат и статус сообщений не шлют, базу не читают.
        '' => deploy_run($home, $webroot, false, deploy_read_catalog_watch($home)),
        '--dry-run' => deploy_run($home, $webroot, true),
        '--rollback' => deploy_rollback($home, $webroot),
        default => deploy_usage(),
    });
} catch (Throwable $e) {
    // Под расписанием вывод никто не читает, поэтому о падении — в лог и в
    // служебный чат.
    $why = get_class($e) . ': ' . $e->getMessage();
    deploy_log($home, 'deploy.php упал: ' . $why);
    // Служебный чат — личный чат владельца, куда приходят и заказы: если
    // deploy.php падает при каждом запуске, он не должен писать туда каждые
    // 15 минут. Расписание — :00, :15, :30, :45, так что «только в первую
    // четверть часа» значит «не чаще раза в час».
    if ((int)date('i') < 15) {
        deploy_log($home, 'сообщение о падении: ' . deploy_telegram('Выкладка pionperm.ru: deploy.php упал — ' . $why));
    } else {
        deploy_log($home, 'сообщение о падении пропущено до начала часа');
    }
    exit(1);
}
