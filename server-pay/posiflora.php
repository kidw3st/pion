<?php
/**
 * Клиент Posiflora: авторизация по интеграционной учётке и создание заказа.
 *
 * CRM — второй получатель заказа после письма: если Posiflora недоступна или
 * креды ещё не выданы, заказ НЕ падает — он уже ушёл письмом, а сюда пишется
 * пометка в лог. Ничего в CRM не удаляется и не меняется: только POST новых
 * записей (клиент, заказ).
 */

declare(strict_types=1);

function posiflora_request(string $method, string $path, ?array $body, ?string $token): array
{
    $ch = curl_init(POSIFLORA_BASE . ltrim($path, '/'));
    $headers = ['Content-Type: application/vnd.api+json', 'Accept: application/vnd.api+json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw)) {
        throw new RuntimeException('Posiflora: нет ответа');
    }
    $json = json_decode($raw, true) ?? [];
    if ($status >= 400) {
        throw new RuntimeException('Posiflora HTTP ' . $status . ': ' . mb_substr($raw, 0, 300));
    }
    return $json;
}

function posiflora_token(): ?string
{
    if (POSIFLORA_USER === '' || POSIFLORA_PASSWORD === '') {
        return null; // интеграция ещё не включена
    }
    $resp = posiflora_request('POST', 'sessions', [
        'data' => [
            'type' => 'sessions',
            'attributes' => ['username' => POSIFLORA_USER, 'password' => POSIFLORA_PASSWORD],
        ],
    ], null);
    return $resp['data']['attributes']['accessToken'] ?? null;
}

/**
 * Отправляет заказ в CRM. Возвращает пометку для лога; никогда не бросает
 * исключение наружу — CRM не должна ронять приём заказа.
 */
function posiflora_push_order(array $order, array $customer, string $paymentLine): string
{
    try {
        $token = posiflora_token();
        if ($token === null) {
            return 'CRM: пропущено (интеграция не настроена)';
        }

        $lines = [];
        foreach ($order['items'] as $item) {
            $lines[] = sprintf('%s x %d — %d руб.', $item['title'], $item['quantity'], $item['amount']);
        }
        $description = "Заказ с сайта pionperm.ru\n"
            . implode("\n", $lines) . "\n"
            . $order['deliveryLabel'] . ' — ' . $order['delivery'] . " руб.\n"
            . ($order['discount'] > 0 ? 'Скидка самовывоза: -' . $order['discount'] . " руб.\n" : '')
            . 'ИТОГО: ' . $order['total'] . " руб.\n"
            . 'Оплата: ' . $paymentLine . "\n"
            . 'Клиент: ' . $customer['name'] . ', ' . $customer['phone']
            . ($customer['email'] !== '' ? ', ' . $customer['email'] : '');

        $isDelivery = $customer['address'] !== '';
        $resp = posiflora_request('POST', 'orders', [
            'data' => [
                'type' => 'orders',
                'attributes' => [
                    'date' => date('Y-m-d'),
                    // docNo обязателен и не длиннее 12 символов: st + месяц-день-часы-минуты-секунды.
                    'docNo' => 'st' . date('mdHis'),
                    // dueTime обязателен (ISO 8601); по умолчанию собрать «через 3 часа».
                    'dueTime' => date('c', time() + 3 * 3600),
                    'status' => 'new',
                    'delivery' => $isDelivery,
                    'deliveryCity' => $isDelivery ? 'Пермь' : '',
                    'deliveryStreet' => $customer['address'],
                    'deliveryContact' => $customer['name'],
                    'deliveryPhoneNumber' => preg_replace('/\D/', '', $customer['phone']),
                    'description' => $description,
                    'budget' => $order['total'],
                ],
                'relationships' => [
                    'store' => ['data' => ['type' => 'stores', 'id' => POSIFLORA_STORE_ID]],
                    'source' => ['data' => ['type' => 'order-sources', 'id' => POSIFLORA_SOURCE_ID]],
                ],
            ],
        ], $token);

        $id = $resp['data']['id'] ?? '?';
        return 'CRM: заказ создан (' . $id . ')';
    } catch (Throwable $e) {
        return 'CRM: не удалось (' . $e->getMessage() . ')';
    }
}
