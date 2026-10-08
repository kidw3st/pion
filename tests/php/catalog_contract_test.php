<?php
/**
 * Контракт между выкладкой и админкой: deploy.php пишет выложенную версию
 * каталога в state.json, админка читает её оттуда — в том же виде.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/deploy-lib.php';
require_once __DIR__ . '/../../server-pay/admin/lib/status.php';

t_case('state.json: выложенный каталог доходит до админки', function (): void {
    $home = t_tmpdir();
    $changed = catalog_iso(t_now());
    $release = ['sha' => str_repeat('a', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('c', 64),
        'catalogVersion' => str_repeat('d', 64), 'catalogChangedAt' => $changed, 'catalogProducts' => 487];
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(), $release, 1000));
    t_equal(admin_deployed_catalog($home), ['version' => str_repeat('d', 64), 'changedAt' => $changed], 'админка видит ту же версию и время');
});

t_case('state.json: сборка без каталога и мусор', function (): void {
    $home = t_tmpdir();
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(),
        ['sha' => str_repeat('a', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('c', 64)], 1000));
    t_equal(admin_deployed_catalog($home), null, 'сборка без каталога — выложенное неизвестно');
    foreach (['', "2026-10-05T14:05:00+05:00\0"] as $i => $junk) {
        file_put_contents($home . '/state.json', json_encode(['current' => [
            'catalogVersion' => $i === 0 ? '' : 'v1', 'catalogChangedAt' => $junk,
        ]]));
        $got = admin_deployed_catalog($home);
        t_true($i === 0 ? $got === null : $got['changedAt'] === '', $i === 0 ? 'пустая версия — неизвестно' : 'NUL-байт — дата неизвестна, без падения');
    }
});
