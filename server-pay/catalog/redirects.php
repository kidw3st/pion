<?php
/**
 * Переадресации с адресов, которых больше нет: удалённый букет — в его
 * раздел, сменивший главный раздел — на новый адрес. Сайт отвечает по ним
 * 301 (правило .htaccess и pay/catalog-redirect.php — план 2В).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function catalog_redirect_add(PDO $db, string $from, string $to, DateTimeImmutable $now): void
{
    if ($from === $to) {
        return;
    }
    // Цепочки схлопываются: всё, что вело на $from, теперь ведёт прямо на $to.
    $db->prepare('UPDATE redirects SET to_path = ? WHERE to_path = ?')->execute([$to, $from]);
    $db->exec('DELETE FROM redirects WHERE from_path = to_path');
    $db->prepare('INSERT INTO redirects (from_path, to_path, created_at) VALUES (?, ?, ?)
        ON CONFLICT(from_path) DO UPDATE SET to_path = excluded.to_path, created_at = excluded.created_at')
        ->execute([$from, $to, catalog_iso($now)]);
}

/** Адрес снова занят живой страницей букета — переадресация с него снимается. */
function catalog_redirect_clear_live(PDO $db): void
{
    $db->exec("DELETE FROM redirects WHERE from_path IN (
        SELECT '/' || main_section || '/' || slug || '/' FROM products WHERE status IN ('active', 'hidden'))");
}
