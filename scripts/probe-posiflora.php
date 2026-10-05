<?php
/**
 * Что умеет Posiflora салона: есть ли там постоянный каталог с категориями,
 * из которого сайт мог бы брать букеты по разделам, как раньше из Tilda.
 *
 * Только чтение: одни GET-запросы, в CRM ничего не создаётся и не меняется.
 * Секретов не печатает — только структуру ответов и счётчики.
 *
 * Запуск на сервере: php probe-posiflora.php /путь/к/pay
 */

declare(strict_types=1);

$payDir = rtrim($argv[1] ?? __DIR__, "/\\");
require $payDir . '/config.php';
require $payDir . '/posiflora.php';

$token = posiflora_token();
if ($token === null) {
    echo "Нет доступа к Posiflora (пустые логин/пароль в config.php)\n";
    exit(1);
}

/** GET и короткая сводка: сколько записей, какие поля и связи у первой. */
function probe(string $path, string $token): ?array
{
    try {
        $resp = posiflora_request('GET', $path, null, $token);
    } catch (Throwable $e) {
        printf("  %-48s → %s\n", $path, mb_substr($e->getMessage(), 0, 90));
        return null;
    }
    $data = $resp['data'] ?? [];
    $list = isset($data['id']) ? [$data] : $data;
    $first = $list[0] ?? null;
    printf(
        "  %-48s → %d шт.; поля: %s\n",
        $path,
        count($list),
        $first ? implode(', ', array_slice(array_keys($first['attributes'] ?? []), 0, 14)) : '—',
    );
    if ($first && !empty($first['relationships'])) {
        printf("  %-48s   связи: %s\n", '', implode(', ', array_keys($first['relationships'])));
    }
    return $resp;
}

echo "=== Букеты: все статусы, не только витрина ===\n";
$all = probe('bouquets?page[size]=100&filter[stores]=' . POSIFLORA_STORE_ID, $token);
$byStatus = [];
foreach (($all['data'] ?? []) as $b) {
    $s = (string)($b['attributes']['status'] ?? '?');
    $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
}
arsort($byStatus);
foreach ($byStatus as $s => $n) {
    printf("    статус %-14s — %d\n", $s, $n);
}

echo "\n=== Возможные справочники каталога ===\n";
foreach ([
    'categories', 'bouquet-categories', 'catalog-categories', 'product-categories',
    'item-categories', 'tags', 'bouquet-tags', 'collections',
    'products', 'catalog-items', 'items', 'goods', 'nomenclatures',
    'bouquet-templates', 'templates', 'compositions',
] as $endpoint) {
    probe($endpoint . '?page[size]=5', $token);
}

echo "\nГотово. Ничего не изменено.\n";
