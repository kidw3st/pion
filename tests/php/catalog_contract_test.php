<?php
/**
 * Контракт между сборкой, выкладкой и админкой: scripts/pack-build.mjs пишет
 * каталог в build-info.json, deploy.php (deploy_release_from_info) переносит
 * его в запись о сборке, state.json хранит её, админка читает версию и время
 * выложенного каталога оттуда — в том же виде.
 *
 * Цепочка проверяется целиком: build-info → запись о сборке → состояние →
 * state.json на диске → admin_deployed_catalog. Опечатка в имени ключа или
 * неверное превращение null в '' на любом звене ломает проверку здесь, а не
 * молча на сервере.
 */

declare(strict_types=1);

require_once __DIR__ . '/catalog_fixture.php';
require_once __DIR__ . '/../../server-pay/deploy-lib.php';
require_once __DIR__ . '/../../server-pay/admin/lib/status.php';

/**
 * build-info.json в том виде, в каком его пишет pack-build.mjs, после
 * json_decode(.., true): null остаётся null, числа — числами.
 *
 * @param string|null $catalogJson JSON блока catalog; null — сборка без каталога
 */
function t_contract_build_info(?string $catalogJson): array
{
    $json = '{"commit": "' . str_repeat('b', 40) . '", "builtAt": "2026-10-05T10:00:00.000Z", '
        . '"site": {"file": "pion-site.tar.gz", "bytes": 15788256, "sha256": "' . str_repeat('a', 64) . '"}, '
        . '"pay": {"file": "pion-pay.tar.gz", "bytes": 99380, "sha256": "' . str_repeat('c', 64) . '"}'
        . ($catalogJson === null ? '' : ', "catalog": ' . $catalogJson) . '}';
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Всё, что делает выкладка с build-info, и то, что потом видит админка.
 *
 * @return array{0: array, 1: array|null} запись о сборке и admin_deployed_catalog()
 */
function t_contract_chain(array $info): array
{
    $home = t_tmpdir();
    $release = deploy_release_from_info(str_repeat('e', 40), $info);
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(), $release, 1000));
    return [$release, admin_deployed_catalog($home)];
}

/** Запись о сборке без каталога: что есть у любой сборки. */
function t_contract_base(): array
{
    return ['sha' => str_repeat('e', 40), 'commit' => str_repeat('b', 40), 'paySha256' => str_repeat('c', 64)];
}

t_case('сборка с каталогом: версия и время доходят до админки', function (): void {
    $changed = catalog_iso(t_now());
    [$release, $deployed] = t_contract_chain(t_contract_build_info(
        '{"version": "' . str_repeat('d', 64) . '", "changedAt": "' . $changed . '", "products": 487}',
    ));
    t_equal($release, t_contract_base() + ['catalogVersion' => str_repeat('d', 64), 'catalogChangedAt' => $changed, 'catalogProducts' => 487],
        'запись о сборке: версия, время и число товаров, под своими именами');
    t_equal($deployed, ['version' => str_repeat('d', 64), 'changedAt' => $changed, 'deployedAt' => 1000], 'админка видит ту же версию, время правки и время выкладки');
});

t_case('каталог ещё не менялся (changedAt: null — как в нынешнем снимке)', function (): void {
    [$release, $deployed] = t_contract_chain(t_contract_build_info(
        '{"version": "' . str_repeat('d', 64) . '", "changedAt": null, "products": 487}',
    ));
    t_equal($release['catalogChangedAt'] ?? 'нет ключа', '', 'null в build-info — пустая строка в записи о сборке');
    t_equal($release['catalogVersion'] ?? null, str_repeat('d', 64), 'версия сохранена');
    t_equal($release['catalogProducts'] ?? null, 487, 'число товаров сохранено');
    t_equal($deployed, ['version' => str_repeat('d', 64), 'changedAt' => '', 'deployedAt' => 1000], 'админка знает версию, а время правки — нет');
});

t_case('сборка без каталога', function (): void {
    [$release, $deployed] = t_contract_chain(t_contract_build_info(null));
    t_equal($release, t_contract_base(), 'в записи о сборке нет ключей catalog*');
    t_equal($deployed, null, 'выложенное неизвестно');
});

t_case('мусор в блоке catalog', function (): void {
    $version = str_repeat('d', 64);

    [$release, $deployed] = t_contract_chain(t_contract_build_info('{"version": "' . $version . '", "changedAt": ["2026-10-05T14:00:00+05:00"], "products": "12"}'));
    t_equal($release, t_contract_base() + ['catalogVersion' => $version, 'catalogChangedAt' => '', 'catalogProducts' => 12],
        'время-массив — пустая строка, число товаров строкой — число, версия сохранена');
    t_equal($deployed, ['version' => $version, 'changedAt' => '', 'deployedAt' => 1000], 'админка знает версию, а время правки — нет');

    foreach (['7', 'null', 'true', '["' . $version . '"]', '{"v": 1}'] as $badVersion) {
        [$release, $deployed] = t_contract_chain(t_contract_build_info('{"version": ' . $badVersion . ', "changedAt": "2026-10-05T14:00:00+05:00", "products": 487}'));
        t_equal($release, t_contract_base(), "версия $badVersion — не строка: в записи о сборке нет ключей catalog*");
        t_equal($deployed, null, "версия $badVersion — не строка: выложенное неизвестно");
    }

    foreach (['"abc"', '7', 'null', '[]', '["x"]'] as $badBlock) {
        [$release, $deployed] = t_contract_chain(t_contract_build_info($badBlock));
        t_equal($release, t_contract_base(), "catalog = $badBlock: ключей catalog* нет");
        t_equal($deployed, null, "catalog = $badBlock: выложенное неизвестно");
    }
});

t_case('build-info не прочитался', function (): void {
    foreach ([null, 'текст', 7, []] as $info) {
        t_equal(deploy_release_from_info(str_repeat('e', 40), $info), ['sha' => str_repeat('e', 40), 'commit' => '', 'paySha256' => ''],
            'остаются только sha и пустые строки, без падения');
    }
});

t_case('state.json: сборка без каталога и мусор вместо даты', function (): void {
    $home = t_tmpdir();
    deploy_state_save($home, deploy_state_after_success(deploy_empty_state(), t_contract_base(), 1000));
    t_equal(admin_deployed_catalog($home), null, 'сборка без каталога — выложенное неизвестно');
    foreach (['', "2026-10-05T14:05:00+05:00\0"] as $i => $junk) {
        file_put_contents($home . '/state.json', json_encode(['current' => [
            'catalogVersion' => $i === 0 ? '' : 'v1', 'catalogChangedAt' => $junk,
        ]]));
        $got = admin_deployed_catalog($home);
        t_true($i === 0 ? $got === null : $got['changedAt'] === '', $i === 0 ? 'пустая версия — неизвестно' : 'NUL-байт — дата неизвестна, без падения');
    }
});
