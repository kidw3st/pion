<?php
/**
 * Проверка расчёта акций на сервере. Ничего не создаёт и никуда не пишет:
 * только считает price_order() и складывает строки чека.
 *
 * Запуск: php check-promo.php /путь/к/pay
 *
 * Путь берётся аргументом, чтобы скрипт не пришлось класть в веб-корень:
 * на сервере он лежит в /tmp и просто показывает на боевой /pay.
 */

declare(strict_types=1);

$payDir = rtrim($argv[1] ?? __DIR__, '/\');
if (!is_file($payDir . '/lib.php')) {
    fwrite(STDERR, "Не нашёл lib.php в $payDir
");
    exit(2);
}
require $payDir . '/lib.php';

$fails = 0;
function check(string $what, $got, $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) {
        $fails++;
    }
    printf("  [%s] %s: получили %s, ждали %s\n", $ok ? 'ok' : 'ПЛОХО', $what,
        var_export($got, true), var_export($want, true));
}

// Берём настоящие товары из каталога: один с витрины, один обычный.
$catalog = catalog_index();
$showcaseUid = null;
$shopUid = null;
foreach ($catalog as $uid => $row) {
    if ($showcaseUid === null && is_showcase_uid((string)$uid) && $row['price'] > 0) {
        $showcaseUid = (string)$uid;
    }
    if ($shopUid === null && !is_showcase_uid((string)$uid) && $row['price'] > 0) {
        $shopUid = (string)$uid;
    }
}

echo "Витринный товар: " . ($showcaseUid ?? 'НЕТ НА ВИТРИНЕ') . "\n";
echo "Каталожный товар: " . ($shopUid ?? 'НЕ НАЙДЕН') . "\n";
echo "Вечерняя скидка сейчас: " . (evening_discount_active() ? 'идёт' : 'нет')
    . ' (в Перми ' . (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SALON_TZ))->format('H:i') . ")\n\n";

if ($shopUid === null) {
    echo "Каталог пуст — проверять нечего.\n";
    exit(1);
}

$cases = ['zone-3', 'zone-5', 'pickup'];
foreach ($cases as $zone) {
    $cart = [['uid' => $shopUid, 'quantity' => 2]];
    if ($showcaseUid !== null) {
        $cart[] = ['uid' => $showcaseUid, 'quantity' => 1];
    }
    $order = price_order($cart, $zone);

    printf(
        "%s: товары %d, оплачено за товар %d, вечерняя −%d, самовывоз −%d, доставка %d%s, итого %d%s\n",
        $zone,
        $order['goods'],
        $order['paidForGoods'],
        $order['eveningDiscount'],
        $order['pickupDiscount'],
        $order['delivery'],
        $order['deliveryFree'] ? ' (бесплатно по акции)' : '',
        $order['total'],
        $order['gifts'] ? ' + ' . implode(', ', $order['gifts']) : '',
    );

    // Сумма строк заказа обязана сойтись с итогом.
    $sum = 0;
    foreach ($order['items'] as $item) {
        check("$zone: цена×количество = стоимость строки", $item['unitPrice'] * $item['quantity'], $item['amount']);
        check("$zone: скидка не увела цену в минус", $item['unitPrice'] >= 0, true);
        $sum += $item['amount'];
    }
    check("$zone: сумма позиций", $sum, $order['paidForGoods']);
    check("$zone: итог", $order['total'], $order['paidForGoods'] + $order['delivery']);
    check("$zone: скидка", $order['discount'], $order['goods'] - $order['paidForGoods']);

    // Чек 54-ФЗ: банк отвергнет платёж, если сумма строк не равна Amount.
    $receipt = tbank_receipt($order, 'test@pionperm.ru', '+79000000000');
    $receiptSum = 0;
    foreach ($receipt['Items'] as $line) {
        check("$zone: строка чека «{$line['Name']}» сходится", $line['Price'] * $line['Quantity'], $line['Amount']);
        $receiptSum += $line['Amount'];
    }
    check("$zone: сумма чека = сумма платежа", $receiptSum, $order['total'] * 100);
    echo "\n";
}

echo $fails === 0 ? "ВСЁ СОШЛОСЬ\n" : "ОШИБОК: $fails\n";
exit($fails === 0 ? 0 : 1);
