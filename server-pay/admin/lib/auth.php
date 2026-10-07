<?php
/**
 * Вход в админку. Пароли — только хэш; учётные записи заводит разработчик
 * (catalog/user-cli.php). Сессия — случайный токен в куке, в базе — его
 * sha256. Подбор пароля: 5 неверных попыток за 10 минут — вход закрыт на 10
 * минут, отдельно для адреса и для логина.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../catalog/db.php';
require_once __DIR__ . '/http.php';

const ADMIN_COOKIE = 'pion_admin';
/** Выход после 12 часов без действий. */
const ADMIN_IDLE_SECONDS = 43200;
const ADMIN_LOGIN_LIMIT = 5;
const ADMIN_LOGIN_WINDOW = 600;
const ADMIN_PASSWORD_MIN = 8;
const ADMIN_LOCKED_ERROR = 'Слишком много неудачных попыток. Подождите 10 минут и попробуйте снова.';
/** Хэш, с которым сверяется пароль несуществующего логина: по времени ответа не узнать, есть ли такой сотрудник. */
const ADMIN_DUMMY_HASH = '$2y$10$ef.4cizNGuT6y4ghb/RglOGG0W4blm0loaBE.4b5hvYtCjRM88MZi';

/**
 * Дольше любого настоящего логина (до 32 знаков). Страница входа открыта всем, а
 * каждая неверная попытка пишется в таблицу: без предела на длину один адрес мог
 * бы набить базу (и её ежедневную копию) мегабайтами логина.
 */
const ADMIN_LOGIN_KEY_MAX = 64;

/** Логин без учёта регистра и пробелов: «Anna» и «anna» — одна запись и один счётчик попыток. */
function admin_login_key(string $login): string
{
    return mb_substr(mb_strtolower(trim($login)), 0, ADMIN_LOGIN_KEY_MAX);
}

function admin_login_blocked(PDO $db, string $ip, string $login, int $now): bool
{
    $since = $now - ADMIN_LOGIN_WINDOW;
    $q = $db->prepare('SELECT
        (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?),
        (SELECT COUNT(*) FROM login_attempts WHERE login = ? AND at > ?)');
    $q->execute([$ip, $since, $login, $since]);
    [$byIp, $byLogin] = $q->fetch(PDO::FETCH_NUM);
    return (int)$byIp >= ADMIN_LOGIN_LIMIT || (int)$byLogin >= ADMIN_LOGIN_LIMIT;
}

/**
 * Занять попытку: под блокировкой записи, одним действием, проверить пределы и
 * записать попытку — ещё до проверки пароля. Если проверять сначала, а записывать
 * только неудачу, одновременные запросы успевали бы все пройти проверку раньше,
 * чем первый из них запишет свою попытку, и получили бы не пять попыток, а столько,
 * сколько процессов работает сразу. Блокировка записи выстраивает их в очередь.
 *
 * @return int|null null — вход закрыт, ничего не записано; иначе номер записи о
 *                  попытке: удача стирает все попытки этого логина, а ошибку не про
 *                  пароль вызывающий снимает сам
 */
function admin_attempt_reserve(PDO $db, string $ip, string $login, int $now): ?int
{
    return catalog_tx($db, function () use ($db, $ip, $login, $now): ?int {
        $db->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([$now - 86400]);
        if (admin_login_blocked($db, $ip, $login, $now)) {
            return null;
        }
        $db->prepare('INSERT INTO login_attempts (ip, login, at) VALUES (?, ?, ?)')->execute([$ip, $login, $now]);
        return (int)$db->lastInsertId();
    });
}

/**
 * Попытка входа. Удача — новая сессия: при каждом входе новый токен.
 *
 * @return array{ok: bool, error?: string, token?: string}
 */
function admin_login(PDO $db, string $login, string $password, string $ip, int $now): array
{
    $login = admin_login_key($login);
    if (admin_attempt_reserve($db, $ip, $login, $now) === null) {
        return ['ok' => false, 'error' => ADMIN_LOCKED_ERROR];
    }
    // Пароль проверяем вне транзакции: полсотни миллисекунд вычислений не должны держать запись для
    // остальных. Попытка уже записана, поэтому неверный пароль больше ничего не пишет — и у существующего
    // логина, и у несуществующего работа с базой одна и та же.
    $q = $db->prepare('SELECT password_hash FROM users WHERE login = ?');
    $q->execute([$login]);
    $hash = $q->fetchColumn();
    // Чтение закрываем до записи ниже: пока оно открыто, при занятой записи SQLite отвечает
    // «database is locked» сразу, не дожидаясь busy_timeout.
    $q->closeCursor();
    $ok = password_verify($password, is_string($hash) ? $hash : ADMIN_DUMMY_HASH) && is_string($hash);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Неверный логин или пароль.'];
    }
    $token = bin2hex(random_bytes(32));
    // Удача стирает попытки этого логина (в том числе только что занятую) и заводит сессию одним действием.
    catalog_tx($db, function () use ($db, $login, $token, $now): void {
        $db->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
        $db->prepare('INSERT INTO sessions (token_hash, login, csrf, created_at, seen_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $login, bin2hex(random_bytes(16)), $now, $now]);
    });
    return ['ok' => true, 'token' => $token];
}

/**
 * Сессия по токену из куки: жива — продлевается, 12 часов без действий —
 * удаляется. null — не вошли.
 *
 * @return array{login: string, name: string, must_change: int, csrf: string}|null
 */
function admin_session(PDO $db, ?string $token, int $now): ?array
{
    if ($token === null || $token === '' || !ctype_xdigit($token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $q = $db->prepare('SELECT s.login, s.csrf, s.seen_at, u.name, u.must_change
        FROM sessions s JOIN users u ON u.login = s.login WHERE s.token_hash = ?');
    $q->execute([$hash]);
    $row = $q->fetch();
    // Закрываем чтение до записи ниже (удаление и продление): открытое, оно отменяет ожидание записи.
    $q->closeCursor();
    if ($row === false) {
        return null;
    }
    if ((int)$row['seen_at'] < $now - ADMIN_IDLE_SECONDS) {
        $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$hash]);
        return null;
    }
    // Продлеваем не чаще раза в минуту — не писать в базу на каждый клик.
    if ((int)$row['seen_at'] < $now - 60) {
        $db->prepare('UPDATE sessions SET seen_at = ? WHERE token_hash = ?')->execute([$now, $hash]);
    }
    return ['login' => $row['login'], 'name' => $row['name'], 'must_change' => (int)$row['must_change'], 'csrf' => $row['csrf']];
}

function admin_logout(PDO $db, ?string $token): void
{
    if (is_string($token) && $token !== '') {
        $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
}

/** Кука сессии: только для админки, только по https, не видна скриптам, не уходит с чужих сайтов. */
function admin_session_cookie(string $token): string
{
    return ADMIN_COOKIE . '=' . $token . '; Path=' . ADMIN_BASE . '; HttpOnly; Secure; SameSite=Strict';
}

function admin_clear_cookie(): string
{
    return ADMIN_COOKIE . '=; Path=' . ADMIN_BASE . '; Max-Age=0; HttpOnly; Secure; SameSite=Strict';
}

function admin_csrf_ok(array $session, array $req): bool
{
    return hash_equals($session['csrf'], admin_str($req['post'], 'csrf'));
}

/**
 * Смена пароля: null — сменён, иначе текст ошибки. Другие сессии этого
 * сотрудника закрываются: пароль могли сменить из-за утечки.
 */
function admin_change_password(
    PDO $db,
    array $session,
    string $current,
    string $new,
    string $repeat,
    ?string $keepToken,
    DateTimeImmutable $now,
): ?string {
    $q = $db->prepare('SELECT password_hash FROM users WHERE login = ?');
    $q->execute([$session['login']]);
    $hash = (string)$q->fetchColumn();
    // Закрываем чтение до транзакции ниже: открытое, оно отменяет ожидание записи.
    $q->closeCursor();
    if (!password_verify($current, $hash)) {
        return 'Текущий пароль введён неверно.';
    }
    if (mb_strlen($new) < ADMIN_PASSWORD_MIN) {
        return 'Новый пароль — не короче 8 знаков.';
    }
    if ($new !== $repeat) {
        return 'Новый пароль и повтор не совпадают.';
    }
    if ($new === $current) {
        return 'Новый пароль совпадает с текущим — придумайте другой.';
    }
    catalog_tx($db, function () use ($db, $session, $new, $keepToken, $now): void {
        $db->prepare('UPDATE users SET password_hash = ?, must_change = 0, updated_at = ? WHERE login = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), catalog_iso($now), $session['login']]);
        $db->prepare('DELETE FROM sessions WHERE login = ? AND token_hash <> ?')
            ->execute([$session['login'], hash('sha256', (string)$keepToken)]);
        catalog_audit($db, $session['login'], $now, 'user', $session['login'], 'password', null, 'сменён');
    });
    return null;
}
