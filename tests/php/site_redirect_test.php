<?php
/**
 * Переадресации каталога: адрес букета без страницы → куда вести по списку
 * api/redirects.json. Только адреса этого сайта — открытой переадресации
 * на чужой сайт быть не должно.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/catalog-redirect-lib.php';

$map = [
    '/korobki/buket-a/' => '/bukety/buket-a/',
    '/bukety/udalen/' => '/bukety/',
    '/bukety/chuzhoy/' => '//evil.example/',
    '/bukety/chuzhoy-2/' => 'https://evil.example/',
];

t_equal(catalog_redirect_target('/korobki/buket-a/', $map), '/bukety/buket-a/', 'букет переехал — на новый адрес');
t_equal(catalog_redirect_target('/korobki/buket-a', $map), '/bukety/buket-a/', 'без косой черты в конце — тоже');
t_equal(catalog_redirect_target('/korobki/buket-a/?utm_source=vk', $map), '/bukety/buket-a/', 'метки в адресе не мешают');
t_equal(catalog_redirect_target('/bukety/udalen/', $map), '/bukety/', 'удалённый — в раздел');
t_equal(catalog_redirect_target('/bukety/net-takogo/', $map), null, 'нет в списке — переадресации нет (будет 404)');
t_equal(catalog_redirect_target('/bukety/chuzhoy/', $map), null, 'адрес вида //сайт не пропускается');
t_equal(catalog_redirect_target('/bukety/chuzhoy-2/', $map), null, 'адрес с https:// не пропускается');
t_equal(catalog_redirect_target('/pay/catalog-redirect.php', $map), null, 'прямой заход на скрипт — не адрес букета');
t_equal(catalog_redirect_target('/a/b/c/', $map), null, 'три части — не адрес букета');
t_equal(catalog_redirect_target('', $map), null, 'пустой адрес');
