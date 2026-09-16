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
// freeFrom — сумма заказа, с которой доставка в эту зону бесплатна (null —
// никогда). Ближняя зона появилась вместе с акцией «бесплатно от 5000 ₽
// в радиусе 3 км»: раньше самой близкой была пятикилометровая.
const DELIVERY_OPTIONS = [
    'zone-3' => ['label' => 'Доставка до 3 км от салона', 'price' => 300,  'discountPercent' => 0, 'freeFrom' => 5000],
    'zone-5' => ['label' => 'Доставка до 5 км от салона', 'price' => 500,  'discountPercent' => 0, 'freeFrom' => null],
    'zone-7' => ['label' => 'Доставка до 7 км от салона', 'price' => 800,  'discountPercent' => 0, 'freeFrom' => null],
    'zone-9' => ['label' => 'Доставка до 9 км от салона', 'price' => 950,  'discountPercent' => 0, 'freeFrom' => null],
    'pickup' => ['label' => 'Самовывоз: ул. Газеты Звезда, 27', 'price' => 0, 'discountPercent' => 5, 'freeFrom' => null],
];

/** Часовой пояс салона. Сервер живёт по Москве, Пермь на два часа впереди. */
const SALON_TZ = 'Asia/Yekaterinburg';

// Вечерняя скидка на букеты с витрины: они собраны сегодня и до завтра не
// доживут, поэтому вечером их отдают дешевле. Действует, пока салон открыт.
const EVENING_PERCENT = 15;
const EVENING_FROM_HOUR = 20;
const EVENING_TO_HOUR = 22;

// Подарки за сумму заказа. Считается оплаченная стоимость букетов — без
// доставки и уже за вычетом скидок, то есть ровно то, что человек платит
// за товар.
const GIFT_BOX_FROM = 5000;
const GIFT_SHOPPER_FROM = 15000;

/**
 * Идёт ли сейчас вечерняя скидка. Время берём по Перми, а не по серверу:
 * иначе в 20:00 у покупателя скидки ещё нет, а в 18:00 она уже есть.
 */
function evening_discount_active(?DateTimeImmutable $now = null): bool
{
    $local = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(SALON_TZ));
    $hour = (int)$local->format('G');

    return $hour >= EVENING_FROM_HOUR && $hour < EVENING_TO_HOUR;
}

/**
 * Товар с витрины? Синхронизатор помечает такие идентификаторы префиксом
 * cs- (см. sync-showcase.php) — постоянный каталог его не использует.
 */
function is_showcase_uid(string $uid): bool
{
    return str_starts_with($uid, 'cs-');
}

/**
 * Пересчитывает заказ по серверным ценам и применяет акции.
 * Вход: [['uid' => ..., 'quantity' => ...], ...], id доставки.
 * Выход (всё в целых рублях):
 *   items          — позиции; price по прайсу, unitPrice и amount со скидкой
 *   goods          — стоимость товара по прайсу
 *   paidForGoods   — сколько за товар платят после скидок
 *   delivery       — цена доставки, уже обнулённая, если сработала акция
 *   deliveryFree   — доставка стала бесплатной по акции (а не самовывоз)
 *   eveningDiscount, pickupDiscount, discount — из чего сложилась скидка
 *   gifts          — что положить в заказ подарком
 *   total          — сумма к оплате
 *
 * $now подставляется только проверками: вечернюю скидку иначе не увидеть
 * до восьми вечера, а это самая свежая и самая денежная часть расчёта.
 */
function price_order(array $cartItems, string $deliveryId, ?DateTimeImmutable $now = null): array
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

    // Время проверяем один раз на весь заказ: если считать его для каждой
    // позиции, заказ, оформленный ровно в 22:00:00, получил бы скидку на
    // первый букет и не получил на второй.
    $eveningActive = evening_discount_active($now);

    $items = [];
    $goods = 0;          // по прайсу, без скидок
    $paidForGoods = 0;   // сколько человек реально платит за товар
    $evening = 0;
    $pickup = 0;
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
        $showcase = is_showcase_uid($uid);

        // Скидки считаем сразу в цене за штуку и в целых рублях. Так сумма
        // позиций всегда в точности равна сумме платежа — банк отвергает чек,
        // в котором они разошлись хоть на копейку, а распределять скидку по
        // позициям задним числом пришлось бы с остатками от деления.
        $unit = $price;
        if ($showcase && $eveningActive) {
            $unit -= (int)round($unit * EVENING_PERCENT / 100);
            $evening += ($price - $unit) * $qty;
        }
        // Скидка самовывоза идёт следом, от уже уценённого: иначе две скидки
        // на одну сумму накладывались бы друг на друга.
        if ($delivery['discountPercent'] > 0) {
            $afterEvening = $unit;
            $unit -= (int)round($unit * $delivery['discountPercent'] / 100);
            $pickup += ($afterEvening - $unit) * $qty;
        }

        $items[] = [
            'uid' => $uid,
            'title' => $catalog[$uid]['title'],
            'price' => $price,
            // Цена и стоимость строки уже со скидкой — их и платит покупатель.
            'unitPrice' => $unit,
            'quantity' => $qty,
            'amount' => $unit * $qty,
            'showcase' => $showcase,
        ];
        $goods += $price * $qty;
        $paidForGoods += $unit * $qty;
    }
    if ($items === []) {
        throw new InvalidArgumentException('Корзина пуста');
    }

    $discount = $evening + $pickup;

    $deliveryPrice = $delivery['price'];
    $deliveryFree = $delivery['freeFrom'] !== null
        && $deliveryPrice > 0
        && $paidForGoods >= $delivery['freeFrom'];
    if ($deliveryFree) {
        $deliveryPrice = 0;
    }

    // Подарки кладём в заказ строками: флорист должен видеть, что положить
    // в пакет, а покупатель — за что именно он их получил.
    $gifts = [];
    if ($paidForGoods >= GIFT_BOX_FROM) {
        $gifts[] = 'Фирменная транспортировочная коробка';
    }
    if ($paidForGoods >= GIFT_SHOPPER_FROM) {
        $gifts[] = 'Фирменный шоппер «Пион»';
    }

    return [
        'items' => $items,
        'goods' => $goods,
        'paidForGoods' => $paidForGoods,
        'deliveryLabel' => $delivery['label'],
        'delivery' => $deliveryPrice,
        'deliveryFree' => $deliveryFree,
        'eveningDiscount' => $evening,
        'pickupDiscount' => $pickup,
        // Общая скидка: на неё опираются чек и тексты уведомлений.
        'discount' => $discount,
        'gifts' => $gifts,
        'total' => $paidForGoods + $deliveryPrice,
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
            'Price' => $item['unitPrice'] * 100,
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
    // Ничего вычитать не нужно: price_order уже отдал цены со скидкой, и
    // сумма позиций по построению равна сумме платежа. Раньше скидка целиком
    // снималась с первой позиции — при 5% это сходило с рук, но с вечерними
    // 15% дешёвая первая позиция ушла бы в минус, и банк отверг бы чек.

    // Подарки — отдельными позициями по нулевой цене: так они видны и в чеке,
    // и флористу в задании на сборку.
    foreach ($order['gifts'] ?? [] as $gift) {
        $items[] = [
            'Name' => mb_substr($gift . ' (подарок)', 0, 128),
            'Price' => 0,
            'Quantity' => 1,
            'Amount' => 0,
            'Tax' => 'none',
            'PaymentMethod' => 'full_payment',
            'PaymentObject' => 'commodity',
        ];
    }

    $receipt = ['Items' => $items, 'Taxation' => 'usn_income'];
    if ($email !== '') {
        $receipt['Email'] = $email;
    } elseif ($phone !== '') {
        $receipt['Phone'] = $phone;
    }
    return $receipt;
}

/** Сумма как её читают люди: 6 061 ₽. */
function rub(int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' ₽';
}

/**
 * Из чего сложилась скидка — одним списком на все три уведомления (Telegram,
 * письмо, заявка в CRM), чтобы флорист везде видел одинаковые цифры.
 * Возвращает пары [подпись, сумма в рублях].
 *
 * @return list<array{0: string, 1: int}>
 */
function order_discount_lines(array $order): array
{
    $lines = [];
    if (($order['eveningDiscount'] ?? 0) > 0) {
        $lines[] = ['Вечерняя скидка ' . EVENING_PERCENT . '% на витрину', (int)$order['eveningDiscount']];
    }
    if (($order['pickupDiscount'] ?? 0) > 0) {
        $lines[] = ['Скидка за самовывоз', (int)$order['pickupDiscount']];
    }

    return $lines;
}

/** Экранирование для разметки Telegram: имя и адрес пишет посторонний. */
function tg_escape(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Сообщение о заказе для флориста: сверху — что собирать, снизу — кому
 * отдать. Читается с телефона одним взглядом, без прокрутки.
 */
function order_telegram_text(
    string $headline,
    array $order,
    array $customer,
    string $paymentLine,
    string $orderId,
    ?string $crmId = null,
): string {
    $lines = ['<b>' . tg_escape($headline) . '</b>', ''];

    foreach ($order['items'] as $item) {
        // Если на позицию была скидка, рядом зачёркнута цена по прайсу:
        // флорист сразу видит, что букет ушёл дешевле не по ошибке.
        $full = $item['price'] * $item['quantity'];
        $lines[] = '• ' . tg_escape($item['title'])
            . ($item['quantity'] > 1 ? ' × ' . $item['quantity'] : '')
            . ' — ' . rub($item['amount'])
            . ($item['amount'] < $full ? ' <s>' . rub($full) . '</s>' : '');
    }

    $lines[] = '';
    $lines[] = tg_escape($order['deliveryLabel'])
        . ($order['delivery'] > 0
            ? ' — ' . rub($order['delivery'])
            : (!empty($order['deliveryFree']) ? ' — бесплатно по акции' : ''));
    foreach (order_discount_lines($order) as [$label, $amount]) {
        $lines[] = tg_escape($label) . ': −' . rub($amount);
    }
    $lines[] = '<b>Итого: ' . rub($order['total']) . '</b>';
    $lines[] = 'Оплата: ' . tg_escape($paymentLine);

    if (!empty($order['gifts'])) {
        $lines[] = '🎁 Положить в заказ: ' . tg_escape(implode(', ', $order['gifts']));
    }

    $lines[] = '';
    $lines[] = '👤 <b>' . tg_escape($customer['name']) . '</b>';
    $lines[] = '📞 ' . tg_escape($customer['phone']);
    if ($customer['address'] !== '') {
        $lines[] = '📍 ' . tg_escape($customer['address']);
    }
    if ($customer['email'] !== '') {
        $lines[] = '✉️ ' . tg_escape($customer['email']);
    }

    $lines[] = '';
    $lines[] = '<i>Заказ ' . tg_escape($orderId) . '</i>';
    if ($crmId !== null && $crmId !== '') {
        $lines[] = str_replace('/api/v1/', '', POSIFLORA_BASE) . '/admin/orders/' . $crmId;
    }

    return implode("\n", $lines);
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

    // Получателей может быть несколько (флорист, управляющая) — перечисляются
    // через запятую. Сбой у одного не мешает остальным получить заказ.
    $ok = 0;
    $errors = [];
    foreach (explode(',', TELEGRAM_CHAT_ID) as $chatId) {
        $chatId = trim($chatId);
        if ($chatId === '') {
            continue;
        }

        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => mb_substr($text, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($raw) && str_contains($raw, '"ok":true')) {
            $ok++;
        } else {
            $errors[] = $chatId . ': ' . $code . ' ' . mb_substr((string)$raw, 0, 80);
        }
    }

    if ($errors === []) {
        return 'telegram: доставлено (' . $ok . ')';
    }
    return 'telegram: доставлено ' . $ok . ', ОШИБКИ — ' . implode('; ', $errors);
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
function notify_salon(string $subject, string $body, ?string $telegramText = null): void
{
    // В Telegram уходит вёрстанный текст (его читает флорист с телефона),
    // в почту — та же информация простым текстом.
    $tg = notify_telegram($telegramText ?? ($subject . "\n\n" . $body));

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
        $full = $item['price'] * $item['quantity'];
        $lines[] = sprintf('  %s x %d — %d руб.', $item['title'], $item['quantity'], $item['amount'])
            . ($item['amount'] < $full ? sprintf(' (по прайсу %d руб.)', $full) : '');
    }

    $discounts = '';
    foreach (order_discount_lines($order) as [$label, $amount]) {
        $discounts .= $label . ': -' . $amount . " руб.\n";
    }

    return "Новый заказ на сайте pionperm.ru\n\n"
        . implode("\n", $lines) . "\n\n"
        . 'Доставка: ' . $order['deliveryLabel'] . ' — '
        . ($order['delivery'] > 0
            ? $order['delivery'] . " руб.\n"
            : (!empty($order['deliveryFree']) ? "бесплатно по акции\n" : "0 руб.\n"))
        . $discounts
        . 'ИТОГО: ' . $order['total'] . " руб.\n"
        . (!empty($order['gifts']) ? 'Подарки: ' . implode(', ', $order['gifts']) . "\n" : '')
        . "\n"
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
