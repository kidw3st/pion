<?php
/**
 * Адрес букета, у которого в сборке нет страницы: переадресация по
 * api/redirects.json или наша страница 404 с кодом 404. Сюда шлёт правило
 * в .htaccess сайта; логика — в catalog-redirect-lib.php.
 */

declare(strict_types=1);

require __DIR__ . '/catalog-redirect-lib.php';

$map = json_decode((string)@file_get_contents(__DIR__ . '/../api/redirects.json'), true);
$target = catalog_redirect_target((string)($_SERVER['REQUEST_URI'] ?? ''), is_array($map) ? $map : []);

if ($target !== null) {
    // 301: адрес сменился навсегда — поисковик перенесёт на новую страницу то,
    // что накопила старая.
    header('Location: ' . $target, true, 301);
    header('Cache-Control: max-age=3600');
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
if (@readfile(__DIR__ . '/../404.html') === false) {
    echo 'Страница не найдена';
}
