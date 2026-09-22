<?php
/**
 * Проверка кода UDS из корзины.
 *
 * POST JSON: {"items": [...], "delivery": "...", "code": "123456"}
 *        или {"items": [...], "delivery": "...", "token": "...", "points": 500}
 *
 * Первый запрос — с кодом из приложения: опознаём покупателя и отдаём
 * номерок. Дальше корзина шлёт номерок и число баллов, а код больше не
 * нужен — он живёт минуты и до возврата с оплаты не доживёт.
 *
 * Ничего не создаёт: под капотом POST /operations/calc, который у UDS
 * только считает. Баллы спишутся позже, когда придут деньги.
 *
 * Суммы считает сервер по своему прайсу — числа из браузера не используются
 * даже здесь, иначе можно было бы подобрать себе выгодный ответ.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/uds.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Только POST']);
}
if (!uds_enabled()) {
    respond(503, ['error' => 'Программа лояльности временно недоступна']);
}
// Код одноразовый и короткий — перебирать его бессмысленно, но пусть никто
// и не пробует.
if (!rate_limit('uds', 30, 600)) {
    respond(429, ['error' => 'Слишком много попыток. Позвоните нам: +7 342 258 45 45']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'Некорректный запрос']);
}

try {
    $order = price_order((array)($input['items'] ?? []), (string)($input['delivery'] ?? ''));
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
}

$token = trim((string)($input['token'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

try {
    if ($token !== '') {
        $session = uds_session_get($token);
        if ($session === null) {
            respond(400, ['error' => 'Код устарел — введите новый из приложения UDS']);
        }
        $who = ['uid' => (string)$session['uid']];
        $name = (string)($session['name'] ?? '');
    } elseif ($code !== '') {
        $found = uds_find($code);
        $uid = (string)($found['user']['uid'] ?? '');
        if ($uid === '') {
            respond(400, ['error' => 'Код не распознан — проверьте цифры в приложении UDS']);
        }
        $name = (string)($found['user']['displayName'] ?? '');
        $who = ['uid' => $uid];
        $token = (string)uds_session_put(['uid' => $uid, 'name' => $name]);
        if ($token === '') {
            respond(503, ['error' => 'Не получилось начать списание, попробуйте позже']);
        }
    } else {
        respond(400, ['error' => 'Введите код из приложения UDS']);
    }

    // Сколько всего можно списать на такой чек — спрашиваем без списания.
    $limits = uds_summary(uds_calc($who, $order['total'], 0));
    // Баллами гасим только товар: доставка — услуга, она в чеке отдельной
    // строкой и к скидке отношения не имеет.
    $ceiling = min($limits['maxPoints'], $order['paidForGoods']);

    $wanted = max(0, min((int)($input['points'] ?? 0), $ceiling));
    $withPoints = apply_points_discount($order, $wanted);
    $spent = $withPoints['udsPoints'];

    $final = $spent > 0 ? uds_summary(uds_calc($who, $order['total'], $spent)) : $limits;

    // UDS насчитал не то же, что мы? Значит в программе включено что-то,
    // чего этот код не знает — например, автоматическая скидка вместо
    // списания. Тогда баллы не трогаем: заказ пройдёт по обычной цене,
    // а в логе останется след, чтобы разобраться.
    if ($spent > 0 && $final['cash'] !== $withPoints['total']) {
        @file_put_contents(
            __DIR__ . '/orders.log',
            date('Y-m-d H:i:s') . ' | UDS: расхождение сумм, списание отключено — у нас '
            . $withPoints['total'] . ', у UDS ' . $final['cash'] . "\n",
            FILE_APPEND | LOCK_EX,
        );
        respond(200, [
            'token' => $token,
            'name' => $name,
            'tier' => $limits['tier'],
            'balance' => $limits['points'],
            'maxPoints' => 0,
            'points' => 0,
            'total' => $order['total'],
            'cashBack' => $limits['cashBack'],
            'note' => 'Списать баллы сейчас не получается — бонусы просто начислим за покупку.',
        ]);
    }

    respond(200, [
        'token' => $token,
        'name' => $name,
        'tier' => $limits['tier'],
        'balance' => $limits['points'],
        'maxPoints' => $ceiling,
        'points' => $spent,
        'total' => $spent > 0 ? $withPoints['total'] : $order['total'],
        'cashBack' => $final['cashBack'],
    ]);
} catch (Throwable $e) {
    // Текст ошибки UDS показываем как есть: «код не найден» или «код
    // просрочен» покупателю понятнее любой нашей формулировки.
    respond(400, ['error' => str_replace('UDS: ', '', $e->getMessage())]);
}
