<?php
/**
 * Уведомления Т-Банка о смене статуса платежа. Банк шлёт POST JSON и ждёт
 * в ответе ровно "OK". Подпись проверяется тем же алгоритмом, что и запросы.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['TerminalKey'])) {
    http_response_code(400);
    exit('bad request');
}

$token = (string)($input['Token'] ?? '');
$check = $input;
unset($check['Token'], $check['Receipt'], $check['DATA']);
if (!hash_equals(tbank_token($check), $token)) {
    @file_put_contents(
        __DIR__ . '/orders.log',
        date('Y-m-d H:i:s') . " | notify: неверная подпись\n",
        FILE_APPEND | LOCK_EX,
    );
    http_response_code(403);
    exit('bad token');
}

$status = (string)($input['Status'] ?? '');
$orderId = (string)($input['OrderId'] ?? '');
$amount = (int)($input['Amount'] ?? 0) / 100;

if ($status === 'CONFIRMED') {
    notify_salon(
        'ОПЛАЧЕН заказ ' . $orderId . ' — ' . $amount . ' руб.',
        'Т-Банк подтвердил оплату заказа ' . $orderId . ' на сумму ' . $amount . " руб.\n"
        . 'Можно собирать букет.',
    );
} elseif (in_array($status, ['REJECTED', 'DEADLINE_EXPIRED'], true)) {
    notify_salon(
        'Не прошла оплата заказа ' . $orderId,
        'Статус платежа: ' . $status . '. Сумма: ' . $amount . " руб.\n"
        . 'Если клиент не перезвонит — заказ можно не собирать.',
    );
}

echo 'OK';
