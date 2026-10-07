<?php
/**
 * Переадресации: цепочки схлопываются, живые адреса от них свободны.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/redirects.php';

t_case('цепочки', function (): void {
    $db = t_catalog_db();
    catalog_redirect_add($db, '/a/', '/b/', t_now());
    catalog_redirect_add($db, '/b/', '/c/', t_now());
    t_equal(t_redirects($db), ['/a/' => '/c/', '/b/' => '/c/'], 'A → B и B → C превращаются в A → C');
    catalog_redirect_add($db, '/c/', '/a/', t_now());
    t_equal(t_redirects($db), ['/b/' => '/a/', '/c/' => '/a/'], 'петля не остаётся: переадресации на самого себя нет');
    catalog_redirect_add($db, '/x/', '/x/', t_now());
    t_equal(isset(t_redirects($db)['/x/']), false, 'адрес сам на себя не переадресуется');
});

t_case('живой адрес', function (): void {
    $db = t_catalog_with_sections();
    t_put_product($db, '100000000001', 'active', 'roses', ['roses' => 0], 'roza');
    t_put_product($db, '100000000002', 'deleted', 'bukety', ['bukety' => 0], 'pion');
    $db->exec("INSERT INTO redirects (from_path, to_path, created_at) VALUES
        ('/roses/roza/', '/roses/', '2026-10-01T10:00:00+05:00'),
        ('/bukety/pion/', '/bukety/', '2026-10-01T10:00:00+05:00')");
    catalog_redirect_clear_live($db);
    t_equal(t_redirects($db), ['/bukety/pion/' => '/bukety/'], 'с адреса живого букета переадресация снята, с удалённого — нет');
});
