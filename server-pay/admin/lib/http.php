<?php
/**
 * Запрос и ответ админки — обычные массивы. Страница — функция «запрос →
 * ответ»: её можно проверить без веб-сервера, а настоящие $_POST, куки и
 * заголовки трогают только admin_request_from_globals() и admin_emit().
 *
 * Запрос: method, path, query, post, cookies, files, ip.
 * Ответ: status, headers (имя => значение), cookies (строки Set-Cookie), body.
 */

declare(strict_types=1);

/** Адрес админки на сайте. */
const ADMIN_BASE = '/pay/admin/';

/**
 * Заголовки каждого ответа: админку не индексируют и не кэшируют, в чужой
 * сайт её не встроить, и в ней работают только свои стили и скрипты (blob:
 * и data: — превью фото до отправки).
 */
const ADMIN_SECURITY_HEADERS = [
    'X-Robots-Tag' => 'noindex, nofollow',
    'Cache-Control' => 'no-store',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'same-origin',
    'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
];

function admin_request_from_globals(): array
{
    return [
        'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'path' => (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        'query' => $_GET,
        'post' => $_POST,
        'cookies' => $_COOKIE,
        'files' => $_FILES,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
}

/** Запрос для проверок: всё, чего не передали, — пусто. */
function admin_request(
    string $method = 'GET',
    array $query = [],
    array $post = [],
    array $cookies = [],
    array $files = [],
    string $ip = '127.0.0.1',
): array {
    return [
        'method' => $method, 'path' => ADMIN_BASE, 'query' => $query, 'post' => $post,
        'cookies' => $cookies, 'files' => $files, 'ip' => $ip,
    ];
}

function admin_response(int $status, string $body, array $headers = [], array $cookies = []): array
{
    return ['status' => $status, 'headers' => $headers + ADMIN_SECURITY_HEADERS, 'cookies' => $cookies, 'body' => $body];
}

function admin_html(string $html, int $status = 200): array
{
    return admin_response($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
}

function admin_json(array $data, int $status = 200): array
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return admin_response($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
}

/** Переход на страницу админки после POST: 303 — браузер придёт GET-запросом, повторная отправка формы не случится. */
function admin_redirect(string $page, array $query = []): array
{
    $url = ADMIN_BASE . $page . ($query === [] ? '' : '?' . http_build_query($query));
    return admin_response(303, '', ['Location' => $url]);
}

function admin_emit(array $response): void
{
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header("$name: $value");
    }
    foreach ($response['cookies'] as $cookie) {
        header('Set-Cookie: ' . $cookie, false);
    }
    echo $response['body'];
}

/** Строковое поле формы. Не строка (массив из подделанного запроса) — пусто. */
function admin_str(array $from, string $key): string
{
    $value = $from[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Список строк из формы (галочки разделов, фото). Не список — пусто; не строки в нём пропускаются. */
function admin_list(array $from, string $key): array
{
    $value = $from[$key] ?? [];
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}
