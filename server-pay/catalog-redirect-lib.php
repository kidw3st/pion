<?php
/**
 * Переадресации каталога: старые адреса букетов — на новые.
 *
 * Список ведёт админка (таблица redirects). Он приходит со сборкой сайта
 * в api/redirects.json в виде {"/откуда/": "/куда/"}. Правило в .htaccess
 * шлёт в catalog-redirect.php адреса вида /<раздел>/<букет>/, у которых в
 * сборке нет страницы: букет переехал в другой раздел или удалён.
 */

declare(strict_types=1);

/** Куда вести адрес $uri по списку $map; null — переадресации нет. */
function catalog_redirect_target(string $uri, array $map): ?string
{
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~^/[a-z0-9_-]+/[a-z0-9_-]+/?\z~', $path)) {
        return null;
    }
    $to = $map[rtrim($path, '/') . '/'] ?? null;
    // Только адрес этого же сайта: «//чужой.сайт» и «https://…» не пропускаем,
    // даже если они как-то попали в файл.
    if (!is_string($to) || !preg_match('~^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*/\z~', $to)) {
        return null;
    }
    return $to;
}
