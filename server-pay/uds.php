<?php
/**
 * Клиент UDS — программы лояльности салона.
 *
 * Два сценария:
 *   1) начисление — после заказа покупателю капает кешбэк. Хватает номера
 *      телефона, который он и так оставляет: в настройках компании включён
 *      purchaseByPhone, приложение покупателю не нужно.
 *   2) списание — покупатель вводит одноразовый код из приложения UDS и
 *      гасит баллами часть суммы. Без кода никак: usePointsByPhone выключен,
 *      и это защита самого UDS от списания чужих баллов.
 *
 * Как и Posiflora, UDS никогда не роняет заказ: нет ключей, лежит сервис,
 * пришёл неожиданный ответ — заказ проходит как обычно, а в лог падает
 * пометка. Деньги важнее баллов.
 *
 * Ничего не удаляет и не меняет: только читает настройки, считает операцию
 * (POST /operations/calc ничего не создаёт) и добавляет новую покупку.
 */

declare(strict_types=1);

/** Ключи выданы? Пусто — весь модуль молча выключен. */
function uds_enabled(): bool
{
    return defined('UDS_API_KEY') && UDS_API_KEY !== ''
        && defined('UDS_COMPANY_ID') && UDS_COMPANY_ID !== '';
}

/** UUID v4 — нужен и для заголовка запроса, и для nonce операции. */
function uds_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Телефон в том виде, в каком его ждёт UDS: +7 и десять цифр.
 * Возвращает null, если из введённого нормальный номер не собирается —
 * тогда начисление просто пропускается.
 */
function uds_phone(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
        return '+7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '+7' . $digits;
    }

    return null;
}

/**
 * Запрос к Partner API. Бросает исключение на любой неуспех — вызывающий
 * код ловит его сам и решает, что делать.
 */
function uds_request(string $method, string $path, ?array $body = null): array
{
    if (!uds_enabled()) {
        throw new RuntimeException('UDS: ключи не выданы');
    }

    $ch = curl_init(UDS_API . ltrim($path, '/'));
    $headers = [
        'Accept: application/json',
        'Accept-Charset: utf-8',
        'Authorization: Basic ' . base64_encode(UDS_COMPANY_ID . ':' . UDS_API_KEY),
        'X-Origin-Request-Id: ' . uds_uuid(),
        'X-Timestamp: ' . (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        // Покупатель ждёт ответа на странице оформления: лучше быстро
        // отказать, чем заставить его смотреть на крутилку.
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) {
        throw new RuntimeException('UDS: нет связи (' . $error . ')');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('UDS: непонятный ответ (' . mb_substr($raw, 0, 200) . ')');
    }
    if ($code >= 400) {
        $message = (string)($data['message'] ?? $data['errorCode'] ?? ('HTTP ' . $code));
        throw new RuntimeException('UDS: ' . $message);
    }

    return $data;
}

/**
 * Кем представился покупатель. Ровно одно из трёх: одноразовый код из
 * приложения, постоянный UID или телефон — так требует UDS.
 */
function uds_identity(array $who): array
{
    foreach (['code', 'uid', 'phone'] as $key) {
        $value = trim((string)($who[$key] ?? ''));
        if ($value !== '') {
            return $key === 'code' ? ['code' => $value] : ['participant' => [$key => $value]];
        }
    }

    throw new InvalidArgumentException('UDS: нужен код, UID или телефон');
}

/**
 * Кто прячется за одноразовым кодом. Нужен, чтобы запомнить постоянный UID:
 * код живёт считаные минуты и до возврата с оплаты картой не доживёт, а
 * списать баллы надо будет уже после того, как деньги придут.
 */
function uds_find(string $code): array
{
    return uds_request('GET', 'customers/find?code=' . rawurlencode($code));
}

/**
 * Что будет с этим чеком: сколько у покупателя баллов, сколько из них можно
 * списать и сколько он доплатит деньгами. Ничего не создаёт — этим методом
 * можно пользоваться при каждом нажатии в корзине.
 */
function uds_calc(array $who, int $total, int $points = 0): array
{
    return uds_request('POST', 'operations/calc', uds_identity($who) + [
        'receipt' => ['total' => $total, 'points' => $points],
    ]);
}

/**
 * Записывает покупку: списывает баллы (если есть что списывать) и начисляет
 * кешбэк. Сумму к оплате деньгами берём из calc, а не считаем сами — так
 * требует UDS, и это же страхует от расхождения.
 *
 * $nonce делает операцию повторяемой без последствий: если ответ потерялся
 * по дороге и запрос ушёл второй раз, UDS не спишет баллы дважды.
 */
function uds_purchase(array $who, int $total, int $points, int $cash, string $nonce, string $number): array
{
    return uds_request('POST', 'operations', uds_identity($who) + [
        'nonce' => $nonce,
        'receipt' => [
            'total' => $total,
            'cash' => $cash,
            'points' => $points,
            'number' => mb_substr($number, 0, 64),
        ],
    ]);
}
/**
 * Достаёт из ответа calc то, что нужно показать покупателю.
 * Всё в целых рублях: копеек в баллах не бывает, а округление вниз всегда
 * в пользу салона — списать больше, чем есть, нельзя.
 */
function uds_summary(array $calc): array
{
    $user = $calc['user'] ?? [];
    $purchase = $calc['purchase'] ?? [];
    $participant = $user['participant'] ?? [];

    return [
        'name' => (string)($user['displayName'] ?? ''),
        'points' => (int)floor((float)($participant['points'] ?? 0)),
        'maxPoints' => (int)floor((float)($purchase['maxPoints'] ?? 0)),
        'cash' => (int)round((float)($purchase['cash'] ?? 0)),
        'cashBack' => (int)floor((float)($purchase['cashBack'] ?? 0)),
        'tier' => (string)($participant['membershipTier']['name'] ?? ''),
        'discountAmount' => (int)round((float)($purchase['discountAmount'] ?? 0)),
    ];
}

/**
 * Короткое хранилище на диске: рядом с rate-счётчиками, наружу закрыто
 * правилом в .htaccess. Базы данных на этом хостинге нет, а данных тут —
 * десяток строк на заказ, живущих минуты.
 */
function uds_store_dir(): ?string
{
    $dir = __DIR__ . '/uds';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }

    // Изредка подчищаем: заброшенные корзины и неоплаченные заказы иначе
    // копились бы вечно.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }

    return $dir;
}

/**
 * Запоминает, кого опознал код, и отдаёт браузеру случайный номерок.
 *
 * Наружу уходит номерок, а не UID покупателя: зная чужой UID, можно было бы
 * потратить чужие баллы. Код из приложения подтверждает согласие один раз,
 * дальше человек опознаётся по номерку.
 */
function uds_session_put(array $data): ?string
{
    $dir = uds_store_dir();
    if ($dir === null) {
        return null;
    }
    $token = bin2hex(random_bytes(16));
    $data['createdAt'] = time();
    @file_put_contents($dir . '/s-' . $token . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $token;
}

/** Номерок действителен полчаса — дольше оформление заказа не длится. */
function uds_session_get(string $token): ?array
{
    $dir = uds_store_dir();
    if ($dir === null || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $file = $dir . '/s-' . $token . '.json';
    $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($data) || (int)($data['createdAt'] ?? 0) < time() - 1800) {
        return null;
    }

    return $data;
}

/**
 * Намерение списать баллы, отложенное до подтверждения оплаты.
 *
 * Баллы списываются только когда деньги действительно пришли: иначе
 * брошенная на полпути оплата съела бы у человека бонусы ни за что.
 */
function uds_pending_put(string $orderId, array $data): void
{
    $dir = uds_store_dir();
    if ($dir === null) {
        return;
    }
    $data['createdAt'] = time();
    @file_put_contents(
        $dir . '/p-' . preg_replace('/[^a-z0-9\-]/i', '', $orderId) . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE),
        LOCK_EX,
    );
}

/** Забирает намерение и сразу удаляет файл: операция должна пройти один раз. */
function uds_pending_take(string $orderId): ?array
{
    $dir = uds_store_dir();
    if ($dir === null) {
        return null;
    }
    $file = $dir . '/p-' . preg_replace('/[^a-z0-9\-]/i', '', $orderId) . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($file), true);
    @unlink($file);

    return is_array($data) ? $data : null;
}
