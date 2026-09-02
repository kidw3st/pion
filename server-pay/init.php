<?php
/**
 * Приём заказа с сайта.
 *
 * POST JSON:
 * {
 *   "items": [{"uid": "...", "quantity": 1}, ...],
 *   "delivery": "zone5" | "zone7" | "zone9" | "pickup",
 *   "payment": "card" | "cash",
 *   "customer": {"name": "...", "phone": "...", "email": "", "address": ""}
 * }
 *
 * card → создаёт платёж в Т-Банке, отвечает {"paymentUrl": ...};
 * cash → фиксирует заказ (письмо + CRM), отвечает {"ok": true}.
 * Сумма всегда пересчитывается по серверным ценам.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/posiflora.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Только POST']);
}

// Живому покупателю пяти заказов за десять минут хватает с запасом; всё, что
// сверх, — это перебор или спам формой.
if (!rate_limit('order', 5, 600)) {
    respond(429, ['error' => 'Слишком много заказов подряд. Позвоните нам: +7 342 258 45 45']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'Некорректный запрос']);
}

$customer = [
    'name' => trim((string)($input['customer']['name'] ?? '')),
    'phone' => trim((string)($input['customer']['phone'] ?? '')),
    'email' => trim((string)($input['customer']['email'] ?? '')),
    'address' => trim((string)($input['customer']['address'] ?? '')),
];
$payment = (string)($input['payment'] ?? '');

if ($customer['name'] === '' || $customer['phone'] === '') {
    respond(400, ['error' => 'Укажите имя и телефон']);
}
if (!in_array($payment, ['card', 'cash'], true)) {
    respond(400, ['error' => 'Выберите способ оплаты']);
}

try {
    $order = price_order((array)($input['items'] ?? []), (string)($input['delivery'] ?? ''));
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
}

$orderId = 'pion-' . date('ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

if ($payment === 'cash') {
    $paymentLine = 'наличными или картой при получении';
    $crm = posiflora_push_order($order, $customer, $paymentLine);
    notify_salon(
        'Заказ с сайта (оплата при получении) — ' . $order['total'] . ' руб.',
        order_mail_body($order, $customer, $paymentLine) . $crm['note'] . "\nНомер: " . $orderId,
        order_telegram_text(
            '🌸 Новый заказ с сайта',
            $order,
            $customer,
            $paymentLine,
            $orderId,
            $crm['id'],
        ),
    );
    respond(200, ['ok' => true]);
}

// Оплата картой: Init в Т-Банке.
$request = [
    'TerminalKey' => TBANK_TERMINAL,
    'Amount' => $order['total'] * 100,
    'OrderId' => $orderId,
    'Description' => 'Заказ в салоне цветов «Пион»',
    'SuccessURL' => SITE_ORIGIN . '/checkout/?payment=success',
    'FailURL' => SITE_ORIGIN . '/checkout/?payment=fail',
    'NotificationURL' => SITE_ORIGIN . '/pay/notify.php',
];
$request['Token'] = tbank_token($request);
$request['DATA'] = ['Phone' => $customer['phone'], 'Name' => $customer['name']];
$request['Receipt'] = tbank_receipt($order, $customer['email'], $customer['phone']);

$ch = curl_init(TBANK_API . 'Init');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE),
]);
$raw = curl_exec($ch);
curl_close($ch);

$resp = is_string($raw) ? (json_decode($raw, true) ?? []) : [];
if (($resp['Success'] ?? false) !== true || empty($resp['PaymentURL'])) {
    notify_salon(
        'Сбой создания платежа Т-Банк',
        'Заказ ' . $orderId . ' на ' . $order['total'] . " руб. не ушёл в оплату.\n"
        . 'Ответ банка: ' . mb_substr((string)$raw, 0, 500) . "\n\n"
        . order_mail_body($order, $customer, 'картой (не создался платёж)'),
        order_telegram_text(
            '⚠️ Заказ есть, но оплата не создалась — перезвоните клиенту',
            $order,
            $customer,
            'картой онлайн (платёж не создался)',
            $orderId,
        ),
    );
    respond(502, ['error' => 'Платёжная система недоступна, позвоните нам: +7 342 258 45 45']);
}

// Заказ фиксируем сразу (уведомление + CRM), оплату подтвердит notify.php.
$paymentLine = 'картой онлайн — ждём оплату';
$crm = posiflora_push_order($order, $customer, $paymentLine);
notify_salon(
    'Заказ с сайта (ожидает оплату картой) — ' . $order['total'] . ' руб.',
    order_mail_body($order, $customer, $paymentLine) . $crm['note'] . "\nНомер: " . $orderId,
    order_telegram_text(
        '🕐 Заказ с сайта — ждёт оплаты картой',
        $order,
        $customer,
        $paymentLine,
        $orderId,
        $crm['id'],
    ),
);

respond(200, ['paymentUrl' => $resp['PaymentURL']]);
