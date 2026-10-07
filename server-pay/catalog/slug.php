<?php
/**
 * Slug из названия — перенос slugify из scripts/lib/slugify.mjs один в один:
 * по нему получены адреса всех нынешних букетов. Совпадение проверяется на
 * названиях всего каталога (tests/php/fixtures/slugs.json).
 */

declare(strict_types=1);

const CATALOG_TRANSLIT = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
    'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
    'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
    'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
    'я' => 'ya',
];

/**
 * Адреса, которые раздел занять не может: служебные папки сайта и его
 * страницы (PAGE_SLUGS в src/lib/content.ts).
 */
const CATALOG_RESERVED_SLUGS = [
    'catalog', 'checkout', 'v-nalichii', 'bukety-do-5000', 'blog', 'pay', 'api', 'images', 'md',
    'fonts', '_next', 'tproduct', 'tstore', 'feed',
    'about', 'delivery-and-payment', 'flower-delivery', 'contacts', 'uds', 'stock', 'policy',
    'doza_endorfina', 'flowers', 'indoorflowers',
];

function catalog_slugify(string $title): string
{
    $out = '';
    foreach (mb_str_split(mb_strtolower($title, 'UTF-8'), 1, 'UTF-8') as $ch) {
        $out .= CATALOG_TRANSLIT[$ch] ?? $ch;
    }
    // Как в JS: всё, что не латиница и не цифра, — дефис; дефисы по краям
    // убираются, и только потом обрезка до 60 знаков.
    $slug = substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', $out), '-'), 0, 60);
    return $slug === '' ? 'tovar' : $slug;
}
