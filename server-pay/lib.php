<?php
/**
 * Общее для приёма заказов: серверный прайс, подпись Т-Банка, письма, лог.
 *
 * Деньги считаются ТОЛЬКО по ценам с сервера — сумма из браузера никогда
 * не используется. Товар ищется по uid в JSON-каталоге, который лежит рядом
 * с сайтом (тот же, из которого рисуются страницы), плюс короткий список
 * товаров главной страницы из config.php.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

/** Все товары каталога: uid => ['title' =>, 'price' =>]. */
function catalog_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = EXTRA_ITEMS;

    // Витрина из CRM: её кладёт sync-showcase.php. Файла может не быть —
    // тогда в корзине просто нет витринных букетов.
    $showcase = json_decode((string)@file_get_contents(__DIR__ . '/../api/showcase.json'), true);
    foreach (($showcase['products'] ?? []) as $p) {
        if (!empty($p['uid']) && isset($p['price'])) {
            $index[(string)$p['uid']] = [
                'title' => (string)($p['title'] ?? $p['uid']),
                'price' => (int)$p['price'],
            ];
        }
    }

    foreach (glob(__DIR__ . '/../api/catalog/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        foreach (($data['products'] ?? []) as $p) {
            // Публичный /api/catalog отдаёт id/priceRub, исходные данные — uid/price.
            $uid = (string)($p['uid'] ?? $p['id'] ?? '');
            $price = $p['price'] ?? $p['priceRub'] ?? null;
            if ($uid !== '' && $price !== null) {
                $index[$uid] = [
                    'title' => (string)($p['title'] ?? $uid),
                    'price' => (int)$price,
                ];
            }
        }
    }
    return $index;
}

/** Тарифы доставки — те же, что печатает сайт. */
const DELIVERY_OPTIONS = [
    'zone-5' => ['label' => 'Доставка до 5 км от салона', 'price' => 500,  'discountPercent' => 0],
    'zone-7' => ['label' => 'Доставка до 7 км от салона', 'price' => 800,  'discountPercent' => 0],
    'zone-9' => ['label' => 'Доставка до 9 км от салона', 'price' => 950,  'discountPercent' => 0],
    'pickup' => ['label' => 'Самовывоз: ул. Газеты Звезда, 27', 'price' => 0, 'discountPercent' => 5],
];

/**
 * Пересчитывает заказ по серверным ценам.
 * Вход: [['uid' => ..., 'quantity' => ...], ...], id доставки.
 * Выход: ['items' => [...], 'goods' => int, 'delivery' => int,
 *         'discount' => int, 'total' => int] — всё в рублях.
 */
function price_order(array $cartItems, string $deliveryId): array
{
    $catalog = catalog_index();
    $delivery = DELIVERY_OPTIONS[$deliveryId] ?? null;
    if ($delivery === null) {
        throw new InvalidArgumentException('Неизвестный способ доставки');
    }

    // Корзина салона — это несколько букетов, а не тысяча позиций: ограничение
    // отсекает раздутые запросы, на которых сервер считал бы цены впустую.
    if (count($cartItems) > 50) {
        throw new InvalidArgumentException('Слишком много позиций в заказе');
    }

    $items = [];
    $goods = 0;
    foreach ($cartItems as $row) {
        $uid = (string)($row['uid'] ?? '');
        $qty = (int)($row['quantity'] ?? 0);
        if ($uid === '' || $qty < 1 || $qty > 99) {
            throw new InvalidArgumentException('Некорректная позиция заказа');
        }
        if (!isset($catalog[$uid])) {
            throw new InvalidArgumentException('Товар не найден: ' . $uid);
        }
        $price = $catalog[$uid]['price'];
        $items[] = [
            'uid' => $uid,
            'title' => $catalog[$uid]['title'],
            'price' => $price,
            'quantity' => $qty,
            'amount' => $price * $qty,
        ];
        $goods += $price * $qty;
    }
    if ($items === []) {
        throw new InvalidArgumentException('Корзина пуста');
    }

    $discount = (int)round($goods * $delivery['discountPercent'] / 100);
    return [
        'items' => $items,
        'goods' => $goods,
        'deliveryLabel' => $delivery['label'],
        'delivery' => $delivery['price'],
        'discount' => $discount,
        'total' => $goods + $delivery['price'] - $discount,
    ];
}

/** Подпись запроса к Т-Банку: sha256 значений корневых скаляров + пароль. */
function tbank_token(array $request): string
{
    $pairs = [];
    foreach ($request as $key => $value) {
        if (is_scalar($value)) {
            $pairs[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
    }
    $pairs['Password'] = TBANK_PASSWORD;
    ksort($pairs);
    return hash('sha256', implode('', array_values($pairs)));
}

/** Чек 54-ФЗ: позиции + доставка, всё без НДС, УСН-доходы (как в Tilda). */
function tbank_receipt(array $order, string $email, string $phone): array
{
    $items = [];
    foreach ($order['items'] as $item) {
        $items[] = [
            'Name' => mb_substr($item['title'], 0, 128),
            'Price' => $item['price'] * 100,
            'Quantity' => $item['quantity'],
            'Amount' => $item['amount'] * 100,
            'Tax' => 'none',
            'PaymentMethod' => 'full_payment',
            'PaymentObject' => 'commodity',
        ];
    }
    if ($order['delivery'] > 0) {
        $items[] = [
            'Name' => 'Доставка',
            'Price' => $order['delivery'] * 100,
            'Quantity' => 1,
            'Amount' => $order['delivery'] * 100,
            'Tax' => 'none',
            'PaymentMethod' => 'full_payment',
            'PaymentObject' => 'service',
        ];
    }
    // Скидка самовывоза уменьшает первую позицию, чтобы сумма чека сошлась
    // с суммой платежа копейка в копейку.
    if ($order['discount'] > 0 && $items !== []) {
        $items[0]['Amount'] -= $order['discount'] * 100;
        $items[0]['Price'] = (int)($items[0]['Amount'] / $items[0]['Quantity']);
    }

    $receipt = ['Items' => $items, 'Taxation' => 'usn_income'];
    if ($email !== '') {
        $receipt['Email'] = $email;
    } elseif ($phone !== '') {
        $receipt['Phone'] = $phone;
    }
    return $receipt;
}

/**
 * Сообщение в Telegram — основной канал: приходит мгновенно и не зависит от
 * почтовой доставки. Возвращает пометку для лога.
 */
function notify_telegram(string $text): string
{
    // Конфиг на сервере может быть старее этого файла — проверяем обе константы.
    if (!defined('TELEGRAM_TOKEN') || !defined('TELEGRAM_CHAT_ID')
        || TELEGRAM_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        return 'telegram: не настроен';
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => mb_substr($text, 0, 4000),
            'disable_web_page_preview' => 'true',
        ]),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code === 200 && is_string($raw) && str_contains($raw, '"ok":true')) {
        return 'telegram: доставлено';
    }
    return 'telegram: ОШИБКА ' . $code . ' ' . mb_substr((string)$raw, 0, 120);
}

/**
 * Уведомление салону о заказе.
 *
 * Три независимых канала, чтобы заказ не потерялся:
 *   1) Telegram — мгновенно, основной;
 *   2) письмо — дублирует, если почта настроена;
 *   3) orders.log — остаётся на сервере в любом случае.
 * Результат каждого канала пишется в лог, поэтому молчаливых сбоев больше нет.
 */
function notify_salon(string $subject, string $body): void
{
    $tg = notify_telegram($subject . "\n\n" . $body);

    // SALON_EMAIL может содержать несколько адресов через запятую: пока не
    // известно, какой ящик салон читает на самом деле, письмо уходит на все.
    // В Reply-To ставим первый, чтобы ответ клиенту шёл в один адрес.
    $replyTo = trim(explode(',', SALON_EMAIL)[0]);

    // Обратный адрес должен быть на нашем домене, иначе письмо не пройдёт
    // проверку у получателя. Reply-To ведёт на живой ящик салона.
    $headers = 'From: "Сайт Пион" <robot@pionperm.ru>' . "\r\n"
        . 'Reply-To: ' . $replyTo . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "X-Mailer: pionperm-site\r\n";
    $sent = @mail(SALON_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f robot@pionperm.ru');
    $mailNote = 'почта: ' . ($sent ? 'принята сервером' : 'ОТКАЗ (mail() вернул false)');

    @file_put_contents(
        __DIR__ . '/orders.log',
        date('Y-m-d H:i:s') . ' | ' . $mailNote . ' | ' . $tg . ' | '
        . str_replace("\n", ' ~ ', $subject . ' | ' . $body) . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

/** Тело письма о заказе. */
function order_mail_body(array $order, array $customer, string $paymentLine): string
{
    $lines = [];
    foreach ($order['items'] as $item) {
        $lines[] = sprintf('  %s x %d — %d руб.', $item['title'], $item['quantity'], $item['amount']);
    }
    return "Новый заказ на сайте pionperm.ru\n\n"
        . implode("\n", $lines) . "\n\n"
        . 'Доставка: ' . $order['deliveryLabel'] . ' — ' . $order['delivery'] . " руб.\n"
        . ($order['discount'] > 0 ? 'Скидка самовывоза: -' . $order['discount'] . " руб.\n" : '')
        . 'ИТОГО: ' . $order['total'] . " руб.\n\n"
        . 'Имя: ' . $customer['name'] . "\n"
        . 'Телефон: ' . $customer['phone'] . "\n"
        . 'Email: ' . $customer['email'] . "\n"
        . 'Адрес: ' . ($customer['address'] !== '' ? $customer['address'] : '—') . "\n"
        . 'Оплата: ' . $paymentLine . "\n";
}

/**
 * Ограничение частоты обращений с одного адреса: защита от спама заказами
 * и от перебора. Возвращает false, когда лимит исчерпан.
 *
 * Счётчик — простой файл со списком времён обращений; на объёмах салона
 * этого достаточно, а базы данных здесь нет.
 */
function rate_limit(string $bucket, int $limit, int $window): bool
{
    $dir = __DIR__ . '/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // не смогли завести счётчик — не мешаем покупателю
    }

    // Раз в сотню обращений подчищаем файлы, которые давно никому не нужны.
    if (random_int(1, 100) === 1) {
        foreach (glob($dir . '/*.txt') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $file = $dir . '/' . $bucket . '-' . substr(hash('sha256', $ip), 0, 24) . '.txt';
    $now = time();

    $hits = [];
    if (is_file($file)) {
        foreach (explode(',', (string)@file_get_contents($file)) as $t) {
            $t = (int)$t;
            if ($t > $now - $window) {
                $hits[] = $t;
            }
        }
    }
    if (count($hits) >= $limit) {
        return false;
    }

    $hits[] = $now;
    @file_put_contents($file, implode(',', $hits), LOCK_EX);
    return true;
}

/** Единый JSON-ответ. */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
