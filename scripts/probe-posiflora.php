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
        printf("  %-44s → %s\n", $path, mb_substr($e->getMessage(), 0, 60));
        return null;
    }
    $data = $resp['data'] ?? [];
    $list = isset($data['id']) ? [$data] : $data;
    $first = $list[0] ?? null;
    printf(
        "  %-44s → %d шт.; поля: %s\n",
        $path,
        count($list),
        $first ? implode(', ', array_slice(array_keys($first['attributes'] ?? []), 0, 16)) : '—',
    );
    if ($first && !empty($first['relationships'])) {
        printf("  %-44s   связи: %s\n", '', implode(', ', array_keys($first['relationships'])));
    }
    return $resp;
}

echo "=== Категории с товарами (непустые и не удалённые) ===\n";
$cats = posiflora_request('GET', 'categories?page[size]=200', null, $token)['data'] ?? [];
$shown = 0;
foreach ($cats as $c) {
    $a = $c['attributes'] ?? [];
    if (!empty($a['deleted'])) {
        continue;
    }
    printf(
        "  %-38s товаров: %-4s статус: %-10s путь: %s\n",
        mb_substr((string)($a['title'] ?? '?'), 0, 38),
        (string)($a['countPublicItems'] ?? '?'),
        (string)($a['status'] ?? '?'),
        mb_substr((string)($a['path'] ?? ''), 0, 60),
    );
    $shown++;
}
printf("  всего категорий без удалённых: %d\n", $shown);
$firstCatId = $cats[0]['id'] ?? null;

echo "\n=== Где лежат сами товары ===\n";
foreach ([
    'inventory-items', 'store-items', 'public-items', 'catalog',
    'variants', 'item-variants', 'specifications', 'specs',
    'groups', 'category-groups', 'flowers', 'materials',
] as $endpoint) {
    probe($endpoint . '?page[size]=3', $token);
}
if ($firstCatId) {
    probe('categories/' . $firstCatId . '?include=group,parent', $token);
}

echo "\nГотово. Ничего не изменено.\n";
