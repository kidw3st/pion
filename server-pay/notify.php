<?php
/**
 * Уведомления Т-Банка о смене статуса платежа. Банк шлёт POST JSON и ждёт
 * в ответе ровно "OK". Подпись проверяется тем же алгоритмом, что и запросы.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/uds.php';

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
    // Деньги пришли — только теперь списываем баллы и начисляем кешбэк.
    // На сам заказ UDS не влияет. Строчка в уведомлении флористу появляется,
    // только если покупатель списал баллы, а операцию провести не удалось.
    $udsNote = '';
    $pending = uds_pending_take($orderId);
    if ($pending !== null) {
        $who = (array)$pending['who'];
        // Только начисление по телефону, без кода из приложения: баллы не
        // списывались, поэтому неудача здесь ничего не стоит салону.
        $byPhone = isset($who['phone']) && !isset($who['uid']);
        $logNote = '';
        try {
            if ($byPhone && !uds_is_member_phone((string)$who['phone'])) {
                $logNote = 'UDS: покупатель не в программе, кешбэк не начислен';
            } else {
                uds_purchase(
                    $who,
                    (int)$pending['total'],
                    (int)$pending['points'],
                    (int)$pending['cash'],
                    (string)$pending['nonce'],
                    $orderId,
                );
                $udsNote = (int)$pending['points'] > 0
                    ? PHP_EOL . 'UDS: списано ' . (int)$pending['points'] . ' баллов, кешбэк начислен.'
                    : PHP_EOL . 'UDS: кешбэк начислен.';
                $logNote = trim($udsNote);
            }
        } catch (Throwable $e) {
            // Человек списал баллы по коду, а UDS операцию не принял: скидку
            // салон уже дал, а баллы у покупателя остались. Это флорист должен
            // провести вручную — поэтому только здесь строчка в уведомлении.
            $udsNote = $byPhone
                ? ''
                : PHP_EOL . 'UDS: НЕ ПРОВЕДЕНО (' . $e->getMessage() . ') — проведите вручную.';
            $logNote = 'UDS: ошибка (' . $e->getMessage() . ')';
        }
        @file_put_contents(
            __DIR__ . '/orders.log',
            date('Y-m-d H:i:s') . ' | ' . $orderId . ' | ' . $logNote . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    notify_salon(
        'ОПЛАЧЕН заказ ' . $orderId . ' — ' . $amount . ' руб.',
        'Т-Банк подтвердил оплату заказа ' . $orderId . ' на сумму ' . $amount . " руб.\n"
        . 'Можно собирать букет.' . $udsNote,
        "<b>✅ Заказ оплачен — можно собирать</b>\n\n"
        . 'Сумма: <b>' . rub((int)round($amount)) . "</b>\n"
        . '<i>Заказ ' . tg_escape($orderId) . '</i>' . tg_escape($udsNote),
    );
} elseif (in_array($status, ['REJECTED', 'DEADLINE_EXPIRED'], true)) {
    notify_salon(
        'Не прошла оплата заказа ' . $orderId,
        'Статус платежа: ' . $status . '. Сумма: ' . $amount . " руб.\n"
        . 'Если клиент не перезвонит — заказ можно не собирать.',
        "<b>❌ Оплата не прошла</b>\n\n"
        . 'Сумма: ' . rub((int)round($amount)) . "\n"
        . "Если клиент не перезвонит — собирать не нужно.\n"
        . '<i>Заказ ' . tg_escape($orderId) . '</i>',
    );
}

echo 'OK';
