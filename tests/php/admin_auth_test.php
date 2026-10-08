<?php
/**
 * Вход, подбор пароля, сессии, CSRF, первая смена пароля, выход и пропуск на страницы.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-auth.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

/** Страница-заглушка: показывает, что до неё дошли, и кто вошёл. */
function t_admin_probe(array $req, array $ctx): array
{
    return admin_html('страница: ' . ($ctx['user']['login'] ?? 'никто'));
}

t_case('вход', function (): void {
    $ctx = t_admin_ctx();
    $form = admin_handle(admin_request(), $ctx, 'login', 'admin_page_login');
    t_true($form['status'] === 200 && str_contains($form['body'], 'name="password"'), 'страница входа открывается без входа');
    t_equal($form['headers']['X-Robots-Tag'], 'noindex, nofollow', 'и закрыта от поиска');

    $wrong = admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true($wrong['status'] === 200 && str_contains($wrong['body'], 'Неверный логин или пароль.') && $wrong['cookies'] === [], 'неверный пароль — отказ без куки');
    $nobody = admin_handle(admin_request('POST', post: ['login' => 'olga', 'password' => 'не тот']), $ctx, 'login', 'admin_page_login');
    t_true(str_contains($nobody['body'], 'Неверный логин или пароль.'), 'несуществующий логин — тот же ответ: не узнать, кто есть');

    $ok = admin_handle(admin_request('POST', post: ['login' => ' Anna ', 'password' => 'секрет-анны']), $ctx, 'login', 'admin_page_login');
    t_equal([$ok['status'], $ok['headers']['Location']], [303, '/pay/admin/'], 'верный пароль — в админку (логин без учёта регистра и пробелов)');
    $cookie = $ok['cookies'][0] ?? '';
    t_true(str_starts_with($cookie, 'pion_admin=') && str_ends_with($cookie, '; Path=/pay/admin/; HttpOnly; Secure; SameSite=Strict'),
        'кука сессии: только для админки, только https, не видна скриптам, не уходит с чужих сайтов');
    $token = substr(explode(';', $cookie)[0], strlen('pion_admin='));
    t_equal($ctx['db']->query('SELECT token_hash FROM sessions')->fetchAll(PDO::FETCH_COLUMN), [hash('sha256', $token)], 'в базе — только хэш токена');
    $again = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $ctx['now']->getTimestamp());
    t_true($again['token'] !== $token, 'каждый вход — новый идентификатор сессии');
});

t_case('вход: форма с чужого сайта', function (): void {
    // У формы входа нет CSRF-токена (токен живёт в сессии, а её ещё нет). Вместо него — заголовок браузера
    // Sec-Fetch-Site: страница чужого сайта не должна ни войти за сотрудницу, ни тратить её попытки.
    $ctx = t_admin_ctx();
    $creds = ['login' => 'anna', 'password' => 'секрет-анны'];
    $login = fn (string $fetchSite) => admin_handle(admin_request('POST', post: $creds, fetchSite: $fetchSite), $ctx, 'login', 'admin_page_login');

    $foreign = $login('cross-site');
    t_true($foreign['status'] === 400 && str_contains($foreign['body'], 'Форма устарела') && $foreign['cookies'] === [], 'с чужого сайта — 400 без куки, даже с верным паролем');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 0, 'сессия не заведена');
    $wrong = admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'не тот'], fetchSite: 'cross-site'), $ctx, 'login', 'admin_page_login');
    t_equal([$wrong['status'], (int)$ctx['db']->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn()], [400, 0], 'и попытки сотрудницы не расходуются');

    foreach (['same-origin', 'same-site', 'none', ''] as $site) {
        t_equal($login($site)['status'], 303, 'заголовок «' . ($site === '' ? 'нет' : $site) . '» — вход разрешён (пустой: старые браузеры)');
    }
    t_equal(admin_handle(admin_request(fetchSite: 'cross-site'), $ctx, 'login', 'admin_page_login')['status'], 200, 'страницу входа (GET) с чужого сайта можно открыть');

    // Заголовок из настоящего запроса попадает в массив запроса (в нижнем регистре, как его сравнивают).
    $saved = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'Cross-Site';
    $fromGlobals = admin_request_from_globals()['fetchSite'];
    unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    $missing = admin_request_from_globals()['fetchSite'];
    if ($saved !== null) {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $saved;
    }
    t_equal([$fromGlobals, $missing], ['cross-site', ''], 'из окружения веб-сервера: есть — читается, нет — пусто');
});

t_case('подбор пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, 'anna', 'не тот', '10.0.0.1', $now);
    }
    $blocked = admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 60);
    t_equal($blocked['ok'], false, 'пять неверных попыток — логин закрыт, даже с другого адреса и с верным паролем');
    t_true(str_contains($blocked['error'], '10 минут'), 'сотрудник видит, сколько ждать');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.2', $now + 601)['ok'], true, 'через 10 минут вход снова открыт');
    for ($i = 0; $i < 5; $i++) {
        admin_login($db, "x$i", 'не тот', '10.0.0.9', $now + 700);
    }
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.9', $now + 700)['ok'], false, 'пять неверных попыток с одного адреса — адрес закрыт для всех логинов');
    t_equal(admin_login($db, 'anna', 'секрет-анны', '10.0.0.3', $now + 700)['ok'], true, 'с другого адреса этот логин входит');
});

t_case('подбор пароля: одновременные запросы не получают лишних попыток', function (): void {
    // Предел «5 попыток» должен держаться и когда запросы идут одновременно: проверка предела и запись
    // попытки — одно неделимое действие. Одновременности внутри одного PHP не воспроизвести, поэтому
    // здесь настоящие процессы: все двенадцать открывают базу, ждут общей команды и разом пробуют войти
    // с неверным паролем. У хэша стоимость 10, как на сайте: проверка идёт ~50 мс, и именно в это окно
    // раньше успевали пройти лишние запросы.
    $root = t_tmpdir();
    $file = "$root/catalog.sqlite";
    $ctx = t_admin_ctx(t_catalog_db($file));
    $db = $ctx['db'];
    $db->prepare("UPDATE users SET password_hash = ? WHERE login = 'anna'")
        ->execute([password_hash('секрет-анны', PASSWORD_BCRYPT, ['cost' => 10])]);

    // Копия кода во временной папке: путь без кириллицы, как в t_catalog_scripts() — так отдельные
    // процессы надёжно запускаются и на Windows.
    $src = dirname(__DIR__, 2) . '/server-pay';
    foreach (['admin/lib/auth.php', 'admin/lib/http.php', 'catalog/db.php'] as $rel) {
        if (!is_dir(dirname("$root/pay/$rel"))) {
            mkdir(dirname("$root/pay/$rel"), 0777, true);
        }
        copy("$src/$rel", "$root/pay/$rel");
    }
    file_put_contents("$root/racer.php", <<<'PHP'
        <?php
        declare(strict_types=1);

        [, $file, $dir, $n, $now] = $argv;
        require __DIR__ . '/pay/admin/lib/auth.php';
        $db = catalog_db_open($file);
        touch("$dir/ready-$n");
        $until = microtime(true) + 60;
        while (!is_file("$dir/go") && microtime(true) < $until) {
            usleep(500);
        }
        $result = admin_login($db, 'anna', 'не тот', '10.0.0.1', (int)$now);
        echo $result['ok'] ? 'вошёл' : $result['error'];
        PHP);

    $count = 12;
    $children = [];
    $pipes = [];
    for ($i = 0; $i < $count; $i++) {
        $process = proc_open(
            [PHP_BINARY, "$root/racer.php", $file, $root, (string)$i, (string)$ctx['now']->getTimestamp()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes[$i],
        );
        if ($process !== false) {
            fclose($pipes[$i][0]);
            $children[$i] = $process;
        }
    }
    t_equal(count($children), $count, 'все процессы запущены');
    $deadline = microtime(true) + 60;
    do {
        $ready = count(array_filter(array_keys($children), fn (int $i): bool => is_file("$root/ready-$i")));
        usleep(5000);
    } while ($ready < count($children) && microtime(true) < $deadline);
    touch("$root/go");
    $answers = [];
    foreach ($children as $i => $process) {
        $answers[] = trim((string)stream_get_contents($pipes[$i][1]));
        fclose($pipes[$i][1]);
        proc_close($process);
    }

    $counts = array_count_values($answers);
    ksort($counts);
    $want = ['Неверный логин или пароль.' => 5, 'Слишком много неудачных попыток. Подождите 10 минут и попробуйте снова.' => 7];
    ksort($want);
    t_equal($counts, $want, 'из двенадцати одновременных попыток пароль проверяется пять раз, остальные закрыты сразу');
    t_equal((int)$db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn(), 5, 'в таблице — ровно пять попыток');
});

t_case('длинный логин из запроса не раздувает базу', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    t_equal(admin_login($db, str_repeat('я', 200000), 'не тот', '10.0.0.1', $now)['ok'], false, 'логин в 200 000 знаков — просто отказ');
    t_equal((int)$db->query('SELECT MAX(LENGTH(login)) FROM login_attempts')->fetchColumn(), ADMIN_LOGIN_KEY_MAX, 'в таблицу попыток пишется не больше ' . ADMIN_LOGIN_KEY_MAX . ' знаков');
    t_equal(admin_login_key('  ' . str_repeat('Ab', 100)), str_repeat('ab', 32), 'ключ — обрезанный, без регистра и пробелов');
    t_equal(admin_login($db, 'anna' . str_repeat(' ', 100) . 'x', 'секрет-анны', '10.0.0.1', $now)['ok'], false, 'логин, похожий на настоящий только началом, не входит');
    t_equal(admin_login($db, 'ANNA', 'секрет-анны', '10.0.0.1', $now)['ok'], true, 'обычный логин работает как раньше');
});

t_case('сессия: 12 часов без действий', function (): void {
    $ctx = t_admin_ctx();
    $now = $ctx['now']->getTimestamp();
    $token = admin_login($ctx['db'], 'anna', 'секрет-анны', '127.0.0.1', $now)['token'];
    t_equal(admin_session($ctx['db'], $token, $now + 3600)['login'] ?? null, 'anna', 'через час — сессия жива');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + ADMIN_IDLE_SECONDS - 1)['login'] ?? null, 'anna', 'считается от последнего действия, а не от входа');
    t_equal(admin_session($ctx['db'], $token, $now + 3600 + 2 * ADMIN_IDLE_SECONDS), null, 'больше 12 часов без действий — выход');
    t_equal((int)$ctx['db']->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 0, 'просроченная сессия удалена');
    t_equal(admin_session($ctx['db'], 'не токен', $now), null, 'мусор вместо токена — не вошли');
});

/**
 * Одно значение из базы (первая колонка первой строки); false — строки нет. Чтение закрывается сразу,
 * чтобы проверка сама не оставляла открытых запросов.
 */
function t_db_value(PDO $db, string $sql, array $args = []): mixed
{
    $q = $db->prepare($sql);
    $q->execute($args);
    $found = $q->fetchColumn();
    $q->closeCursor();
    return $found;
}

/**
 * Вызов при чужой записи: должен подождать busy_timeout и только потом отказать «database is locked».
 * Мгновенный отказ (ждали ~0 с) значит, что в соединении осталось открытое чтение.
 *
 * @return float сколько секунд вызов ждал
 */
function t_lock_waits(string $what, callable $call): float
{
    $start = microtime(true);
    $error = '';
    try {
        $call();
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
    $took = microtime(true) - $start;
    t_true($took >= 0.25 && str_contains($error, 'locked'), "$what: ждёт, пока запись освободится, и только потом отказывает (ждали " . round($took, 3) . ' с)');
    return $took;
}

/**
 * Соединение для проверки «вторая запись действия тоже ждёт». Чужую запись держат весь вызов, поэтому
 * действие, которое пишет дважды, отказывает на первой записи и до второй не доходит. Здесь запись
 * занимается другим соединением в тот момент, когда у этого прошёл первый COMMIT, — вторая запись
 * встречает занятый замок.
 */
final class T_LockAfterCommit extends PDO
{
    private ?PDO $holder = null;
    /** Занята ли запись: сработал ли первый COMMIT после включения. */
    public bool $held = false;

    /** Включить: после ближайшего COMMIT запись займёт $holder. */
    public function lockAfterCommit(PDO $holder): void
    {
        $this->holder = $holder;
        $this->held = false;
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($statement === 'COMMIT' && $this->holder !== null && !$this->held) {
            $this->held = true;
            $this->holder->exec('BEGIN IMMEDIATE');
        }
        return $result;
    }

    /** Отпустить запись, если она была занята, и выключить. */
    public function release(): void
    {
        if ($this->held) {
            $this->holder?->exec('ROLLBACK');
        }
        $this->holder = null;
        $this->held = false;
    }
}

t_case('занята запись: ожидание, а не мгновенный отказ', function (): void {
    // Пока другое соединение держит запись, наше обязано подождать busy_timeout. Но если в нём самом
    // ещё открыто чтение (запрос вернул строку и не закрыт), SQLite отвечает «database is locked» сразу,
    // не дожидаясь, — иначе два таких соединения зашли бы в тупик. Поэтому чтение закрывается до любой
    // записи. Ожидание сокращено до 0,3 с, чтобы проверка не тянулась.
    //
    // Здесь проверена ПЕРВАЯ запись каждого действия админки, которое пишет в базу: вход и выход, смена пароля,
    // все действия с букетом (создать, сохранить, опубликовать, снять, вернуть, удалить, восстановить), новый
    // раздел, порядок плиток и карточка раздела. Чужая запись держится весь вызов, поэтому действие, которое
    // пишет дважды, отказывает на первой записи, а вторая здесь не проверяется: вторые записи входа, смены
    // пароля и публикации из карточки проверяет следующий случай. Остальные страницы (списки, журнал, приём
    // фото) в базу не пишут.
    //
    // Откуда известно, что вызов дошёл именно до первой записи страницы, а не отказал раньше или по другой причине:
    //  1. «database is locked» может дать только запись: читать при чужой транзакции записи можно свободно;
    //  2. сам пропуск (admin_handle) не пишет: вызовы идут с тем же $now, что и вход, сессия свежая и не
    //     продлевается (проверено ниже), а просроченной сессии нет;
    //  3. в конце те же вызовы повторяются при свободной записи, и каждый действительно меняет базу (ответ 303
    //     и другое состояние). Значит, до первой записи дошёл бы любой из них: снятие и удаление без confirm=1
    //     только переспросили бы и ничего не записали бы, а здесь подтверждение есть.
    $file = t_tmpdir() . '/catalog.sqlite';
    $ctx = t_admin_ctx(t_catalog_with_sections(t_catalog_db($file)));
    $a = $ctx['db'];
    $a->exec('PRAGMA busy_timeout = 300');
    $now = $ctx['now']->getTimestamp();
    $cookies = [ADMIN_COOKIE => admin_login($a, 'anna', 'секрет-анны', '127.0.0.1', $now)['token']];
    $csrf = admin_session($a, $cookies[ADMIN_COOKIE], $now)['csrf'];

    $value = static fn (string $sql, array $args = []): mixed => t_db_value($a, $sql, $args);
    t_equal($value('SELECT seen_at FROM sessions WHERE token_hash = ?', [hash('sha256', $cookies[ADMIN_COOKIE])]), $now,
        'сессия свежая: пропуск на страницы её не продлевает, то есть сам ничего не пишет');

    // Букеты для действий готовим сейчас, пока запись свободна (готовыми функциями 2А): по одному на действие,
    // чтобы вызовы не зависели друг от друга и от порядка. Названия разные: «создать» не должно найти тёзку.
    $make = static function (string $title, string $status) use ($a, $ctx): string {
        $at = $ctx['now'];
        $uid = catalog_create_product($a, 'anna', t_fields(['title' => $title]), $at);
        if ($status !== 'draft') {
            catalog_publish($a, 'anna', $uid, t_row($a, $uid)['version'], $at);
        }
        if ($status === 'hidden') {
            catalog_hide($a, 'anna', $uid, t_row($a, $uid)['version'], $at);
        }
        if ($status === 'deleted') {
            catalog_delete($a, 'anna', $uid, t_row($a, $uid)['version'], $at);
        }
        return $uid;
    };
    $toSave = $make('Черновик для сохранения', 'draft');
    $toPublish = $make('Черновик для публикации', 'draft');
    $toRemove = $make('Черновик для удаления', 'draft');
    $toHide = $make('Букет для снятия', 'active');
    $toDelete = $make('Букет для удаления', 'active');
    $toUnhide = $make('Снятый букет', 'hidden');
    $toRestore = $make('Удалённый букет', 'deleted');
    $roses = (int)$value("SELECT id FROM tiles WHERE section = 'roses'");
    t_true($roses > 0, 'в сетке есть плитка раздела «Розы»: без неё проверка переноса плитки ничего не докажет');

    $other = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('BEGIN IMMEDIATE');
    $change = ['csrf' => $csrf, 'current' => 'секрет-анны', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'];
    $calls = [
        'продление сессии' => fn () => admin_session($a, $cookies[ADMIN_COOKIE], $now + 120),
        'вход существующего сотрудника' => fn () => admin_login($a, 'anna', 'не тот', '10.0.0.1', $now),
        // Оба входа обязаны вести себя одинаково: иначе по отказам «database is locked» видно, какие логины есть.
        'вход несуществующего' => fn () => admin_login($a, 'olga', 'не тот', '10.0.0.1', $now),
        'смена пароля' => fn () => admin_handle(admin_request('POST', post: $change, cookies: $cookies), $ctx, 'password', 'admin_page_password'),
        // Карточка раздела сначала читает раздел, потом пишет: прочитанное нужно закрыть до записи, иначе тут
        // тоже «database is locked» наступал сразу (в форме нет исходных значений — поля считаются изменёнными).
        'сохранение карточки раздела' => fn () => admin_handle(
            admin_request('POST', post: ['csrf' => $csrf, 'slug' => 'roses', 'label' => 'Розы поштучно'], cookies: $cookies),
            $ctx, 'section', 'admin_page_section'),
    ];

    // Действия со страниц: [вызов, что в базе при свободной записи меняется]. Версию букета вызов берёт из базы
    // в тот момент, когда его запускают. Выход — последним: он закрывает сессию, а ею пользуются остальные.
    $product = static fn (array $post): array => admin_handle(
        admin_request('POST', post: $post + ['csrf' => $csrf], cookies: $cookies), $ctx, 'product', 'admin_page_product');
    $tiles = static fn (array $post): array => admin_handle(
        admin_request('POST', post: $post + ['csrf' => $csrf], cookies: $cookies), $ctx, 'sections', 'admin_page_sections');
    $version = static fn (string $uid): string => (string)t_row($a, $uid)['version'];
    $state = static fn (string $uid): Closure => fn () => $value("SELECT status || ':' || version FROM products WHERE uid = ?", [$uid]);
    $card = ['price' => '4400', 'description' => 'Розы', 'sections' => ['bukety']];
    $writes = [
        'вход через страницу входа' => [
            fn () => admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'секрет-анны']), $ctx, 'login', 'admin_page_login'),
            fn () => $value('SELECT COUNT(*) FROM sessions'),
        ],
        'новый букет' => [
            fn () => $product(['action' => 'create', 'title' => 'Новый букет', 'price' => '4400', 'sections' => ['bukety']]),
            fn () => $value('SELECT COUNT(*) FROM products'),
        ],
        'сохранение черновика' => [
            fn () => $product(['action' => 'save', 'uid' => $toSave, 'version' => $version($toSave), 'title' => 'Черновик для сохранения'] + $card),
            $state($toSave),
        ],
        // Публикация из карточки: сначала запись правок, потом публикация; фото — с путём, который принимает каталог.
        'публикация черновика из карточки' => [
            fn () => $product(['action' => 'publish', 'uid' => $toPublish, 'version' => $version($toPublish), 'title' => 'Черновик для публикации',
                'images' => ['/images/catalog/bukety/buket-publikatsiya.webp']] + $card),
            $state($toPublish),
        ],
        'снятие с продажи' => [
            fn () => $product(['action' => 'hide', 'uid' => $toHide, 'version' => $version($toHide), 'confirm' => '1']),
            $state($toHide),
        ],
        'возврат в продажу' => [
            fn () => $product(['action' => 'unhide', 'uid' => $toUnhide, 'version' => $version($toUnhide)]),
            $state($toUnhide),
        ],
        'удаление опубликованного букета' => [
            fn () => $product(['action' => 'delete', 'uid' => $toDelete, 'version' => $version($toDelete), 'confirm' => '1']),
            $state($toDelete),
        ],
        // У черновика удаление другое: строка стирается совсем, а не получает статус «удалён».
        'удаление черновика' => [
            fn () => $product(['action' => 'delete', 'uid' => $toRemove, 'version' => $version($toRemove), 'confirm' => '1']),
            $state($toRemove),
        ],
        'восстановление букета' => [
            fn () => $product(['action' => 'restore', 'uid' => $toRestore, 'version' => $version($toRestore)]),
            $state($toRestore),
        ],
        'новый раздел' => [
            fn () => $tiles(['action' => 'create', 'label' => 'Осень']),
            fn () => $value('SELECT COUNT(*) FROM sections'),
        ],
        'порядок плиток' => [
            fn () => $tiles(['action' => 'move', 'tile' => (string)$roses, 'dir' => 'up']),
            fn () => $value('SELECT GROUP_CONCAT(id) FROM (SELECT id FROM tiles ORDER BY position, id)'),
        ],
        'выход' => [
            fn () => admin_handle(admin_request('POST', post: ['csrf' => $csrf], cookies: $cookies), $ctx, 'logout', 'admin_page_logout'),
            fn () => $value('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', [hash('sha256', $cookies[ADMIN_COOKIE])]),
        ],
    ];
    foreach ($writes as $what => [$call]) {
        $calls[$what] = $call;
    }

    foreach ($calls as $what => $call) {
        t_lock_waits($what, $call);
    }
    $other->exec('ROLLBACK');

    // Тот же набор вызовов при свободной записи: каждый меняет базу — значит, при занятой он упирался именно в запись.
    foreach ($writes as $what => [$call, $changed]) {
        $was = $changed();
        $response = $call();
        t_true($response['status'] === 303 && $changed() !== $was,
            "$what: при свободной записи доходит до записи и меняет базу (ответ " . $response['status'] . ')');
    }
});

t_case('занята запись после первой: вторая запись действия тоже ждёт', function (): void {
    // Предыдущий случай держит чужую запись весь вызов, поэтому действие, которое пишет дважды, отказывает
    // на первой записи, а до второй не доходит. Здесь запись занимается только после первого COMMIT
    // действия (T_LockAfterCommit), так что вторая запись встречает занятый замок и обязана подождать.
    // Между двумя записями эти действия читают базу (пароль, букет) — это чтение нужно закрыть.
    $file = t_tmpdir() . '/catalog.sqlite';
    $ctx = t_admin_ctx(t_catalog_with_sections(t_catalog_db($file)));
    $a = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $cookies = [ADMIN_COOKIE => admin_login($a, 'anna', 'секрет-анны', '127.0.0.1', $now)['token']];
    $csrf = admin_session($a, $cookies[ADMIN_COOKIE], $now)['csrf'];
    $draft = catalog_create_product($a, 'anna', t_fields(['title' => 'Черновик']), $ctx['now']);

    // Страницы работают через $b: тот же файл и то же ожидание (0,3 с), что у $a в предыдущем случае.
    $b = new T_LockAfterCommit('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $b->exec('PRAGMA foreign_keys = ON');
    $b->exec('PRAGMA busy_timeout = 300');
    $other = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $at = ['db' => $b] + $ctx;
    $value = static fn (string $sql, array $args = []): mixed => t_db_value($a, $sql, $args);
    $attempts = static fn (): mixed => $value('SELECT COUNT(*) FROM login_attempts');

    /** Включает замок после первой записи, проверяет ожидание второй и отпускает замок. */
    $second = static function (string $what, callable $call) use ($b, $other): void {
        $b->lockAfterCommit($other);
        t_lock_waits($what, $call);
        t_true($b->held, "$what: запись заняли после первой записи действия — ждала именно вторая");
        $b->release();
    };

    // Вход: первая запись — занять попытку, вторая — завести сессию (верный пароль).
    $login = static fn (): array => admin_handle(admin_request('POST', post: ['login' => 'anna', 'password' => 'секрет-анны']), $at, 'login', 'admin_page_login');
    $sessions = $value('SELECT COUNT(*) FROM sessions');
    $second('вход: сессия после занятой попытки', $login);
    t_equal([$attempts(), $value('SELECT COUNT(*) FROM sessions')], [1, $sessions], 'попытка записана, сессии нет: отказала вторая запись');
    $done = $login();
    t_true($done['status'] === 303 && $value('SELECT COUNT(*) FROM sessions') === $sessions + 1, 'при свободной записи тот же вход доходит до сессии');

    // Смена пароля: первая запись — занять попытку, вторая — сам пароль.
    $change = ['csrf' => $csrf, 'current' => 'секрет-анны', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'];
    $password = static fn (array $over = []): array => admin_handle(
        admin_request('POST', post: $over + $change, cookies: $cookies), $at, 'password', 'admin_page_password');
    $hash = static fn (): string => (string)$value("SELECT password_hash FROM users WHERE login = 'anna'");
    t_equal($attempts(), 0, 'после удачного входа попыток нет');
    $second('смена пароля: пароль после занятой попытки', $password);
    t_true($attempts() === 1 && password_verify('секрет-анны', $hash()), 'попытка записана, пароль прежний: отказала вторая запись');

    // Ошибка в новом пароле: вторая запись — снять занятую попытку (текущий пароль верен, это не подбор).
    $short = ['new' => 'коротко', 'repeat' => 'коротко'];
    $second('смена пароля: снятие попытки при ошибке в новом пароле', fn () => $password($short));
    t_equal($attempts(), 2, 'снять попытку не удалось — осталась и она');
    $again = $password($short);
    t_true($again['status'] === 200 && str_contains($again['body'], 'не короче 8') && $attempts() === 2,
        'при свободной записи ошибка показана, а снимается своя попытка (две прошлые остаются)');
    $done = $password();
    t_true($done['status'] === 303 && password_verify('новый-пароль', $hash()) && $attempts() === 0, 'при свободной записи пароль меняется, попытки стёрты');

    // Публикация из карточки: первая запись — правки, вторая — сама публикация.
    $publish = static fn (): array => admin_handle(admin_request('POST', post: [
        'csrf' => $csrf, 'action' => 'publish', 'uid' => $draft, 'version' => (string)t_row($a, $draft)['version'],
        'title' => 'Черновик', 'price' => '4400', 'description' => 'Розы', 'sections' => ['bukety'],
        'images' => ['/images/catalog/bukety/buket-publikatsiya.webp'],
    ], cookies: $cookies), $at, 'product', 'admin_page_product');
    $state = static fn (): mixed => $value("SELECT status || ':' || version FROM products WHERE uid = ?", [$draft]);
    t_equal($state(), 'draft:1', 'букет — черновик');
    $second('публикация из карточки: публикация после правок', $publish);
    t_equal($state(), 'draft:2', 'правки сохранены, публикации нет: отказала вторая запись');
    $done = $publish();
    t_true($done['status'] === 303 && $state() === 'active:4', 'при свободной записи тот же вызов доходит до публикации');
});

t_case('пропуск на страницы', function (): void {
    $ctx = t_admin_ctx();
    $anon = admin_handle(admin_request(), $ctx, 'products', 't_admin_probe');
    t_equal([$anon['status'], $anon['headers']['Location']], [303, '/pay/admin/login.php'], 'без входа — на страницу входа');
    $cookies = t_admin_login($ctx);
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: anna', 'после входа страница открывается');
    $bad = admin_handle(admin_request('POST', post: ['csrf' => 'чужой'], cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_true($bad['status'] === 400 && !str_contains($bad['body'], 'страница:'), 'POST без верного CSRF-токена до страницы не доходит');
    $session = admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp());
    t_equal(admin_handle(admin_request('POST', post: ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'],
        'страница: anna', 'с верным токеном — доходит');
    $noDb = $ctx;
    $noDb['db'] = null;
    t_equal(admin_handle(admin_request(), $noDb, 'login', 'admin_page_login')['status'], 503, 'базы нет — админка честно говорит, что не подключена');
});

t_case('первый вход и смена пароля', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $initial = catalog_user_add($db, 'olga', 'Ольга', t_now());
    $cookies = [ADMIN_COOKIE => admin_login($db, 'olga', $initial, '127.0.0.1', $now)['token']];
    $page = admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe');
    t_equal([$page['status'], $page['headers']['Location']], [303, '/pay/admin/password.php'], 'с выданным паролем — сначала смена пароля');
    $form = admin_handle(admin_request(cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($form['body'], 'первый вход'), 'страница смены пароля объясняет, зачем');

    $phone = admin_login($db, 'olga', $initial, '127.0.0.2', $now)['token'];
    $session = admin_session($db, $cookies[ADMIN_COOKIE], $now);
    $try = fn (array $fields): array => admin_handle(
        admin_request('POST', post: $fields + ['csrf' => $session['csrf']], cookies: $cookies), $ctx, 'password', 'admin_page_password');
    t_true(str_contains($try(['current' => 'не тот', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'])['body'], 'Текущий пароль введён неверно'), 'неверный текущий пароль');
    t_true(str_contains($try(['current' => $initial, 'new' => 'коротко', 'repeat' => 'коротко'])['body'], 'не короче 8'), 'слишком короткий');
    t_true(str_contains($try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'другой-пароль'])['body'], 'не совпадают'), 'повтор не совпал');
    $done = $try(['current' => $initial, 'new' => 'новый-пароль', 'repeat' => 'новый-пароль']);
    t_equal([$done['status'], $done['headers']['Location']], [303, '/pay/admin/?notice=password'], 'пароль сменён');
    t_equal(admin_handle(admin_request(cookies: $cookies), $ctx, 'products', 't_admin_probe')['body'], 'страница: olga', 'дальше — обычная работа');
    t_equal(admin_session($db, $phone, $now), null, 'вход с другого устройства закрыт: пароль могли сменить из-за утечки');
    t_true(admin_login($db, 'olga', 'новый-пароль', '127.0.0.3', $now)['ok'], 'новый пароль подходит');
    $log = implode(' ', $db->query("SELECT COALESCE(new_value, '') FROM audit WHERE object_type = 'user'")->fetchAll(PDO::FETCH_COLUMN));
    t_true(!str_contains($log, 'новый-пароль'), 'пароль в журнал не попадает');
});

t_case('смена пароля: текущий пароль нельзя подбирать', function (): void {
    // Чужая или украденная сессия не должна превращаться в подбор пароля (и в способ запереть хозяина):
    // неверный текущий пароль считается теми же пятью попытками за десять минут, что и неверный пароль при входе.
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $cookies = t_admin_login($ctx);
    $csrf = admin_session($db, $cookies[ADMIN_COOKIE], $now)['csrf'];
    $change = fn (array $at, string $current, string $ip = '127.0.0.1'): array => admin_handle(
        admin_request('POST', post: ['csrf' => $csrf, 'current' => $current, 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'], cookies: $cookies, ip: $ip),
        $at, 'password', 'admin_page_password');
    for ($i = 1; $i <= 5; $i++) {
        t_true(str_contains($change($ctx, "не тот $i")['body'], 'Текущий пароль введён неверно'), "неверный текущий пароль, попытка $i");
    }
    $sixth = $change($ctx, 'секрет-анны');
    t_true($sixth['status'] === 200 && str_contains($sixth['body'], 'Слишком много неудачных попыток'), 'шестая попытка закрыта, даже с верным текущим паролем');
    t_true(password_verify('секрет-анны', (string)$db->query("SELECT password_hash FROM users WHERE login = 'anna'")->fetchColumn()), 'пароль остался прежним');
    t_true(str_contains($change($ctx, 'секрет-анны', '10.9.9.9')['body'], 'Слишком много неудачных попыток'), 'с другого адреса тоже закрыто: предел — на логин');
    $login = admin_login($db, 'anna', 'секрет-анны', '10.0.0.7', $now);
    t_true($login['ok'] === false && str_contains($login['error'], 'Слишком много неудачных попыток'), 'вход этого логина тоже закрыт: счётчики общие');
    $later = ['now' => $ctx['now']->modify('+601 seconds')] + $ctx;
    $done = $change($later, 'секрет-анны');
    t_equal([$done['status'], $done['headers']['Location'] ?? null], [303, '/pay/admin/?notice=password'], 'через десять минут смена снова открыта');
});

t_case('смена пароля: что считается попыткой', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $cookies = t_admin_login($ctx);
    $csrf = admin_session($db, $cookies[ADMIN_COOKIE], $now)['csrf'];
    $post = fn (array $fields): array => admin_handle(
        admin_request('POST', post: $fields + ['csrf' => $csrf], cookies: $cookies), $ctx, 'password', 'admin_page_password');
    $attempts = fn (): int => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE login = 'anna'")->fetchColumn();

    // Верный текущий пароль — не подбор: ошибки в новом пароле попыток не тратят, сколько ни повторяй.
    for ($i = 0; $i < 7; $i++) {
        $short = $post(['current' => 'секрет-анны', 'new' => 'коротко', 'repeat' => 'коротко']);
    }
    t_true(str_contains($short['body'], 'не короче 8'), 'слишком короткий новый пароль — ошибка, а не блокировка');
    t_true(str_contains($post(['current' => 'секрет-анны', 'new' => 'новый-пароль', 'repeat' => 'другой-пароль'])['body'], 'не совпадают'), 'повтор не совпал');
    t_true(str_contains($post(['current' => 'секрет-анны', 'new' => 'секрет-анны', 'repeat' => 'секрет-анны'])['body'], 'совпадает с текущим'), 'новый пароль равен текущему');
    t_equal($attempts(), 0, 'ни одна из этих ошибок не записана как попытка');

    // Неверный текущий пароль считается, удачная смена стирает счётчик.
    for ($i = 1; $i <= 4; $i++) {
        $post(['current' => "не тот $i", 'new' => 'новый-пароль', 'repeat' => 'новый-пароль']);
    }
    t_equal($attempts(), 4, 'каждый неверный текущий пароль — одна попытка');
    $done = $post(['current' => 'секрет-анны', 'new' => 'новый-пароль', 'repeat' => 'новый-пароль']);
    t_equal([$done['status'], $attempts()], [303, 0], 'смена проходит и после четырёх неверных; счётчик стёрт');
});

t_case('смена пароля: счётчики общие с входом в обе стороны', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $now = $ctx['now']->getTimestamp();
    $cookies = t_admin_login($ctx);
    $csrf = admin_session($db, $cookies[ADMIN_COOKIE], $now)['csrf'];
    for ($i = 0; $i < 3; $i++) {
        admin_login($db, 'anna', 'не тот', '10.0.0.5', $now);
    }
    $post = fn (string $current): array => admin_handle(
        admin_request('POST', post: ['csrf' => $csrf, 'current' => $current, 'new' => 'новый-пароль', 'repeat' => 'новый-пароль'], cookies: $cookies),
        $ctx, 'password', 'admin_page_password');
    $post('не тот 1');
    $post('не тот 2');
    t_true(str_contains($post('секрет-анны')['body'], 'Слишком много неудачных попыток'), 'три неверных пароля при входе и два в форме — это уже пять');
});

t_case('выход', function (): void {
    $ctx = t_admin_ctx();
    $cookies = t_admin_login($ctx);
    $out = t_admin_call($ctx, 'logout', 'admin_page_logout', 'POST', cookies: $cookies);
    t_equal([$out['status'], $out['headers']['Location']], [303, '/pay/admin/login.php'], 'выход — на страницу входа');
    t_true(str_contains($out['cookies'][0] ?? '', 'Max-Age=0'), 'кука стирается');
    t_equal(admin_session($ctx['db'], $cookies[ADMIN_COOKIE], $ctx['now']->getTimestamp()), null, 'сессия удалена');
});
