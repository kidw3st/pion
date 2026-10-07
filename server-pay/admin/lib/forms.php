<?php
/**
 * Поля букета из формы — в виде, который ждут правила каталога
 * (catalog/products.php): цена — целое число, разделы и фото — списки строк.
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../../catalog/db.php';

/** Цена из формы: «4 400», «4 400 ₽», «4400 руб.» → 4400. Не целое число — ошибка для сотрудника. */
function admin_parse_price(string $raw): int
{
    $digits = (string)preg_replace('/[\s\x{00A0}₽]|руб\.?/u', '', $raw);
    if ($digits === '' || !ctype_digit($digits) || strlen($digits) > 7) {
        throw new CatalogError('Цена — целое число рублей, например 4400.');
    }
    return (int)$digits;
}

function admin_product_fields(array $post): array
{
    return [
        'title' => admin_str($post, 'title'),
        'description' => admin_str($post, 'description'),
        'price' => admin_parse_price(admin_str($post, 'price')),
        'images' => admin_list($post, 'images'),
        'sections' => admin_list($post, 'sections'),
        'mainSection' => admin_str($post, 'mainSection'),
    ];
}

/** Цена изменилась больше чем вдвое (в любую сторону) — переспросить: лишний ноль стоит дорого. */
function admin_price_jump(int $old, int $new): bool
{
    return $old > 0 && ($new > $old * 2 || $new * 2 < $old);
}
