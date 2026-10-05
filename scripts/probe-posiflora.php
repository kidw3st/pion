<?php
/**
 * Что умеет Posiflora салона: есть ли там постоянный каталог с категориями,
 * из которого сайт мог бы брать букеты по разделам, как раньше из Tilda.
 *
 * Разведка показала: каталог букетов в Posiflora — это «спецификации»
 * (рецепты букетов) с категорией, тегами, фото и флажком «публичный».
 * Этот проход считает, насколько он заполнен у салона.
 *
 * Только чтение: одни GET-запросы, в CRM ничего не создаётся и не меняется.
 * Секретов не печатает — только счётчики и названия.
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

$specs = [];
$included = [];
for ($page = 1; $page <= 20; $page++) {
    $resp = posiflora_request(
        'GET',
        'specifications?page[size]=100&page[number]=' . $page . '&include=category,tags,images',
        null,
        $token,
    );
    $batch = $resp['data'] ?? [];
    foreach (($resp['included'] ?? []) as $inc) {
        $included[$inc['type'] . ':' . $inc['id']] = $inc;
    }
    $specs = array_merge($specs, $batch);
    if (count($batch) < 100) {
        break;
    }
}

$total = count($specs);
$public = 0;
$withImages = 0;
$withPrice = 0;
$byStatus = [];
$byCategory = [];
$byTag = [];
$recent = [];
foreach ($specs as $s) {
    $a = $s['attributes'] ?? [];
    $r = $s['relationships'] ?? [];
    if (!empty($a['public'])) {
        $public++;
    }
    if (!empty($r['images']['data'])) {
        $withImages++;
    }
    if ((float)($a['maxPrice'] ?? 0) > 0) {
        $withPrice++;
    }
    $st = (string)($a['status'] ?? '?');
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;

    $cat = $r['category']['data'] ?? null;
    $catTitle = $cat ? ($included['categories:' . $cat['id']]['attributes']['title'] ?? $cat['id']) : '— без категории';
    $byCategory[$catTitle] = ($byCategory[$catTitle] ?? 0) + 1;

    foreach (($r['tags']['data'] ?? []) as $t) {
        $title = $included[$t['type'] . ':' . $t['id']]['attributes']['title'] ?? ($t['type'] . ':' . $t['id']);
        $byTag[$title] = ($byTag[$title] ?? 0) + 1;
    }
    $recent[] = [(string)($a['updatedAt'] ?? ''), (string)($a['title'] ?? '?'), !empty($a['public']), !empty($r['images']['data'])];
}

printf("Спецификаций всего: %d\n", $total);
printf("  публичных: %d · с фото: %d · с ценой: %d\n", $public, $withImages, $withPrice);
echo "  по статусам: ";
foreach ($byStatus as $k => $v) {
    echo "$k=$v  ";
}
echo "\n\nПо категориям:\n";
arsort($byCategory);
foreach (array_slice($byCategory, 0, 15, true) as $k => $v) {
    printf("  %-34s %d\n", mb_substr((string)$k, 0, 34), $v);
}
echo "\nТеги:\n";
arsort($byTag);
if (!$byTag) {
    echo "  тегов нет\n";
}
foreach (array_slice($byTag, 0, 20, true) as $k => $v) {
    printf("  %-34s %d\n", mb_substr((string)$k, 0, 34), $v);
}
echo "\nПоследние изменённые:\n";
usort($recent, fn($x, $y) => strcmp($y[0], $x[0]));
foreach (array_slice($recent, 0, 8) as [$when, $title, $isPublic, $hasImg]) {
    printf("  %s  %-40s %s %s\n", substr($when, 0, 10), mb_substr($title, 0, 40), $isPublic ? 'публ.' : 'скрыт', $hasImg ? 'фото' : 'без фото');
}
echo "\nГотово. Ничего не изменено.\n";
