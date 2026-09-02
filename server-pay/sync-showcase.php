<?php
/**
 * Витрина из Posiflora на сайт.
 *
 * Флорист собирает букет и ставит его на витрину в CRM — этот скрипт раз в
 * несколько минут забирает витрину, скачивает фотографии и складывает всё в
 * api/showcase.json рядом с сайтом. Фронт читает этот файл и показывает
 * букеты как «в наличии», сервер по нему же считает цену при заказе.
 *
 * Продали или сняли букет с витрины — он пропадает с сайта на следующей
 * синхронизации. Постоянный каталог (api/catalog/*.json) не затрагивается.
 *
 * В CRM скрипт только читает: ни одного изменяющего запроса.
 *
 * Запускается из планировщика: php /путь/pay/sync-showcase.php
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/posiflora.php';

// Витриной считаются статусы, которые показывает сама CRM в разделе
// «Букеты на витрине» (подсмотрено в её же запросе).
const SHOWCASE_STATUSES = 'demonstrated,edited';
const SHOWCASE_LIMIT = 100;

$root = dirname(__DIR__);                    // .../www/pionperm.ru
$imgDir = $root . '/images/showcase';
$outFile = $root . '/api/showcase.json';
$logFile = __DIR__ . '/sync.log';

/** Строка в лог синхронизации: он маленький и его удобно читать из панели. */
function sync_log(string $message): void
{
    @file_put_contents(
        $GLOBALS['logFile'],
        date('Y-m-d H:i:s') . ' | ' . $message . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

/** Скачивает файл во временный путь и переносит на место только целиком. */
function download(string $url, string $dest): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $code !== 200 || strlen($body) < 1024) {
        return false;
    }
    $tmp = $dest . '.part';
    if (@file_put_contents($tmp, $body) === false) {
        return false;
    }
    return @rename($tmp, $dest);
}

try {
    $token = posiflora_token();
    if ($token === null) {
        sync_log('пропуск: не заданы доступы к Posiflora');
        exit(0);
    }

    $query = http_build_query([
        'filter[stores]' => POSIFLORA_STORE_ID,
        'filter[statuses]' => SHOWCASE_STATUSES,
        'page[size]' => SHOWCASE_LIMIT,
        'include' => 'images',
        'sort' => '-onWindowAt',
    ]);
    $resp = posiflora_request('GET', 'bouquets?' . $query, null, $token);

    // Фотографии приходят отдельным массивом included — собираем их по id.
    $photos = [];
    foreach (($resp['included'] ?? []) as $inc) {
        if (($inc['type'] ?? '') !== 'images') {
            continue;
        }
        $a = $inc['attributes'] ?? [];
        $photos[$inc['id']] = $a['fileShop'] ?? $a['fileMedium'] ?? $a['file'] ?? null;
    }

    if (!is_dir($imgDir) && !@mkdir($imgDir, 0755, true) && !is_dir($imgDir)) {
        throw new RuntimeException('не создать ' . $imgDir);
    }

    $products = [];
    $keepFiles = [];
    foreach (($resp['data'] ?? []) as $b) {
        $id = (string)($b['id'] ?? '');
        $attr = $b['attributes'] ?? [];
        // saleAmount — цена для покупателя, её и показываем. У букетов без
        // состава она бывает нулевой, тогда берём сумму букета, чтобы
        // карточка не пропадала молча.
        $price = (int)round((float)($attr['saleAmount'] ?? 0));
        if ($price <= 0) {
            $price = (int)round((float)($attr['trueSaleAmount'] ?? $attr['amount'] ?? 0));
        }
        $title = trim((string)($attr['title'] ?? ''));
        if ($id === '' || $price <= 0 || $title === '') {
            continue; // без цены или названия на витрине сайта делать нечего
        }

        // Первая фотография букета; без неё карточка выглядит пустой.
        $imageUrl = null;
        foreach (($b['relationships']['images']['data'] ?? []) as $rel) {
            if (!empty($photos[$rel['id']])) {
                $imageUrl = $photos[$rel['id']];
                break;
            }
        }
        if ($imageUrl === null) {
            continue;
        }

        $file = $id . '.jpg';
        $keepFiles[$file] = true;
        $dest = $imgDir . '/' . $file;
        if (!is_file($dest) && !download($imageUrl, $dest)) {
            sync_log('не скачалось фото для ' . $title . ' (' . $id . ')');
            continue;
        }

        $products[] = [
            // Префикс cs- отделяет витрину от постоянного каталога, чтобы
            // идентификаторы не могли пересечься.
            'uid' => 'cs-' . $id,
            'title' => $title,
            'price' => $price,
            'description' => trim((string)($attr['description'] ?? '')),
            'images' => ['/images/showcase/' . $file],
            'onWindowAt' => (string)($attr['onWindowAt'] ?? ''),
        ];
    }

    // Фотографии ушедших с витрины букетов больше не нужны.
    $removed = 0;
    foreach (glob($imgDir . '/*.jpg') ?: [] as $existing) {
        if (!isset($keepFiles[basename($existing)])) {
            @unlink($existing);
            $removed++;
        }
    }

    $payload = [
        'updatedAt' => date('c'),
        'count' => count($products),
        'products' => $products,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    if (!is_dir(dirname($outFile))) {
        @mkdir(dirname($outFile), 0755, true);
    }
    // Пишем через временный файл: посетитель никогда не увидит половину JSON.
    $tmp = $outFile . '.part';
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $outFile)) {
        throw new RuntimeException('не записать ' . $outFile);
    }

    sync_log('витрина: ' . count($products) . ' букетов, удалено фото: ' . $removed);
} catch (Throwable $e) {
    // Ошибка синхронизации не должна ничего ломать: на сайте просто останется
    // предыдущая версия витрины.
    sync_log('ОШИБКА: ' . $e->getMessage());
    exit(1);
}
