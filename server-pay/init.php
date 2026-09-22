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
require __DIR__ . '/uds.php';

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

// Программа лояльности UDS. Баллы списываем только при оплате картой:
// списать их раньше, чем пришли деньги, значит оставить человека без
// бонусов, если он передумает на странице банка. Заказы «при получении»
// флорист проводит в UDS сам, как делает это в салоне.
//
// Сама операция уходит в UDS не здесь, а после подтверждения оплаты
// (notify.php) — тут только запоминаем намерение.
$udsWho = null;
$udsPoints = 0;
$udsTotal = $order['total'];
if ($payment === 'card' && uds_enabled()) {
    try {
        $session = uds_session_get(trim((string)($input['udsToken'] ?? '')));
        if ($session !== null) {
            $udsWho = ['uid' => (string)$session['uid']];

            // Число баллов пересчитываем сами: тому, что прислал браузер,
            // верить нельзя — это деньги.
            $limits = uds_summary(uds_calc($udsWho, $udsTotal, 0));
            $wanted = max(0, min(
                (int)($input['udsPoints'] ?? 0),
                $limits['maxPoints'],
                $order['paidForGoods'],
            ));
            $withPoints = apply_points_discount($order, $wanted);

            if ($withPoints['udsPoints'] > 0) {
                $check = uds_summary(uds_calc($udsWho, $udsTotal, $withPoints['udsPoints']));
                // Списываем, только если UDS согласен с нашей суммой
                // до рубля. Разошлись — платит деньгами полностью.
                if ($check['cash'] === $withPoints['total']) {
                    $order = $withPoints;
                    $udsPoints = $withPoints['udsPoints'];
                }
            }
        }

        // Кода не было — не беда: кешбэк начислим по телефону, для этого
        // в настройках компании включён purchaseByPhone.
        if ($udsWho === null) {
            $phone = uds_phone($customer['phone']);
            $udsWho = $phone === null ? null : ['phone' => $phone];
        }
    } catch (Throwable $e) {
        // Лояльность не должна мешать покупке: заказ идёт дальше без неё.
        $udsWho = null;
        $udsPoints = 0;
        @file_put_contents(
            __DIR__ . '/orders.log',
            date('Y-m-d H:i:s') . ' | UDS при оформлении: ' . $e->getMessage() . "
",
            FILE_APPEND | LOCK_EX,
        );
    }
}

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

// Намерение по баллам ждёт подтверждения оплаты. nonce делает операцию
// повторяемой без последствий: банк иногда шлёт уведомление дважды.
if ($udsWho !== null) {
    uds_pending_put($orderId, [
        'who' => $udsWho,
        'total' => $udsTotal,
        'points' => $udsPoints,
        'cash' => $order['total'],
        'nonce' => uds_uuid(),
    ]);
}

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
