<?php
/**
 * Строка статуса: на сайте ли то, что сохранили, и отметки у изменений.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/catalog/export.php';

t_case('статус по версии', function (): void {
    $deployed = ['version' => 'v1', 'changedAt' => '2026-10-05T13:00:00+05:00'];
    t_equal(admin_deploy_status([], null, t_now())['kind'], 'offline', 'сборка о каталоге не знает — сайт собирается из прежнего');
    t_true(str_contains(admin_deploy_status([], null, t_now())['text'], 'из прежнего каталога'), 'и строка так и говорит');
    t_equal(admin_deploy_status(['version' => 'v1', 'changed_at' => '2026-10-05T13:00:00+05:00'], $deployed, t_now()),
        ['kind' => 'synced', 'text' => 'Все изменения на сайте.'], 'версии совпали — всё на сайте');
    t_equal(admin_deploy_status([], $deployed, t_now())['kind'], 'synced', 'ещё ничего не сохраняли — ждать нечего');
    t_equal(admin_deploy_status(['version' => 'v2', 'changed_at' => '2026-10-05T14:00:00+05:00'], $deployed, t_now('+10 minutes')),
        ['kind' => 'pending', 'text' => 'Ждёт выкладки с 14:00 — обычно до получаса.'], 'новая версия — ждёт выкладки');
    t_equal(admin_deploy_status(['version' => 'v2', 'changed_at' => '2026-10-05T14:00:00+05:00'], $deployed, t_now('+91 minutes'))['kind'],
        'late', 'больше 90 минут — выкладка задерживается');
    t_equal(admin_deploy_status(['version' => 'v1', 'changed_at' => '2026-10-05T14:00:00+05:00'], $deployed, t_now()),
        ['kind' => 'synced', 'text' => 'Все изменения на сайте.'], 'та же версия, но новое время — считается синхронизировано (версия, не время)');
});

t_case('статус: ждать — не раньше выкладки текущей сборки', function (): void {
    // Выложили новый код выгрузки: версии разные без единой правки, а последняя правка была давно.
    $old = ['version' => 'v2', 'changed_at' => '2026-09-05T10:00:00+05:00'];
    $deployedAt = fn (string $shift): array => ['version' => 'v1', 'changedAt' => '', 'deployedAt' => t_now($shift)->getTimestamp()];
    t_equal(admin_deploy_status($old, $deployedAt('-10 minutes'), t_now()),
        ['kind' => 'pending', 'text' => 'Ждёт выкладки с 13:50 — обычно до получаса.'], 'выкладка 10 минут назад, правка месяц назад — ждёт с выкладки, а не «задерживается»');
    t_equal(admin_deploy_status($old, $deployedAt('-90 minutes'), t_now())['kind'], 'pending', 'ровно 90 минут после выкладки — ещё ждёт');
    t_equal(admin_deploy_status($old, $deployedAt('-91 minutes'), t_now())['kind'], 'late', 'больше 90 минут после выкладки — задерживается');
    t_equal(admin_deploy_status(['version' => 'v2', 'changed_at' => '2026-10-05T13:55:00+05:00'], $deployedAt('-1 day'), t_now()),
        ['kind' => 'pending', 'text' => 'Ждёт выкладки с 13:55 — обычно до получаса.'], 'правка позже выкладки — ждёт с правки');
    t_equal(admin_deploy_status($old, ['version' => 'v1', 'changedAt' => '', 'deployedAt' => null], t_now())['kind'], 'late', 'времени выкладки нет — по правке');
    t_equal(admin_deploy_status($old, ['version' => 'v1', 'changedAt' => ''], t_now())['kind'], 'late', 'ключа времени выкладки нет — по правке');
    t_equal(admin_deploy_status(['version' => 'v1'] + $old, $deployedAt('-10 minutes'), t_now())['kind'], 'synced', 'версии совпали — на сайте, время не важно');
});

t_case('что выложено', function (): void {
    $home = t_tmpdir();
    t_equal(admin_deployed_catalog($home), null, 'нет state.json — неизвестно');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'commit' => 'def']]));
    t_equal(admin_deployed_catalog($home), null, 'сборка этапа 1 о каталоге не знает');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'catalogVersion' => 'v7', 'catalogChangedAt' => '2026-10-05T13:00:00+05:00']]));
    t_equal(admin_deployed_catalog($home), ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00', 'deployedAt' => null], 'версия и время выложенного каталога; времени выкладки нет — null');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'catalogVersion' => 'v7', 'catalogChangedAt' => '', 'deployedAt' => 1_790_000_000]]));
    t_equal(admin_deployed_catalog($home)['deployedAt'] ?? 'нет', 1_790_000_000, 'время выкладки — как записала выкладка');
    foreach (['вчера', '1790000000', 1_790_000_000.5, 0, -5, true, [1_790_000_000], null] as $junk) {
        file_put_contents("$home/state.json", json_encode(['current' => ['catalogVersion' => 'v7', 'catalogChangedAt' => '', 'deployedAt' => $junk]]));
        t_equal(admin_deployed_catalog($home), ['version' => 'v7', 'changedAt' => '', 'deployedAt' => null], 'время выкладки ' . json_encode($junk) . ' — неизвестно, без падения');
    }
});

t_case('отметки у изменений', function (): void {
    $deployed = ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00'];
    t_equal(admin_change_on_site('2026-10-05T12:59:00+05:00', $deployed), true, 'раньше выложенного — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:00:00+05:00', $deployed), true, 'то же время — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', $deployed), false, 'позже — ждёт выкладки');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', null), null, 'неизвестно — без отметки');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', $deployed, true), true, 'при синхронизации версий все правки — на сайте, даже позже deployed.changedAt');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', null, true), null, 'синхронизация без deployed — всё равно неизвестно');
    t_equal(admin_change_on_site('2026-10-05T08:00:00Z', $deployed), true, 'UTC и +05:00 одного момента — сравнение как временных меток');
});

t_case('строка на страницах', function (): void {
    $ctx = t_admin_ctx();
    $seen = ['status' => '', 'deployed' => '', 'inSync' => ''];
    $page = function (array $req, array $c) use (&$seen): array {
        $seen = ['status' => $c['status'], 'deployed' => $c['deployed'], 'inSync' => $c['inSync']];
        return admin_html('ok');
    };
    t_admin_call($ctx, 'products', $page);
    t_equal($seen['status'], '<p class="status status-offline">Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.</p>',
        'страницы получают готовую строку статуса');
    t_equal($seen['deployed'], null, 'deployed — null без state.json');
    t_equal($seen['inSync'], false, 'inSync — false без state.json');

    // Синхронизировать: создать версию в каталоге и выложить её в state.json
    $db = $ctx['db'];
    if ($db !== null) {
        catalog_touch($db, $ctx['now']);
        $meta = catalog_meta($db);
        $version = $meta['version'] ?? 'v0';
        $changedAt = $meta['changed_at'] ?? '';

        $deployHome = $ctx['deployHome'];
        file_put_contents("$deployHome/state.json", json_encode(['current' => [
            'catalogVersion' => $version,
            'catalogChangedAt' => $changedAt,
        ]]));

        $seen = ['status' => '', 'deployed' => '', 'inSync' => ''];
        t_admin_call($ctx, 'products', $page);
        t_equal($seen['status'], '<p class="status status-synced">Все изменения на сайте.</p>',
            'когда версии совпадают — статус synced');
        t_equal($seen['inSync'], true, 'inSync — true когда версии совпадают');
    }
});

t_case('строка на страницах: сменился код выгрузки', function (): void {
    // meta.version осталась от последней правки и посчитана прежним кодом выгрузки;
    // содержимое то же, и сборка выложена с версией, которую выгрузка отдаёт сейчас.
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    catalog_touch($db, t_now('-30 days'));
    $live = catalog_current_version($db);
    $db->exec("UPDATE meta SET value = 'прежний-код-выгрузки' WHERE key = 'version'");
    t_equal(admin_catalog_now($db), ['version' => $live, 'changed_at' => catalog_iso(t_now('-30 days'))], 'для статуса — версия выгрузки, время — из meta');
    t_true(!$db->inTransaction(), 'чтение для статуса транзакцию не оставляет');
    $seen = ['status' => '', 'inSync' => ''];
    $page = function (array $req, array $c) use (&$seen): array {
        $seen = ['status' => $c['status'], 'inSync' => $c['inSync']];
        return admin_html('ok');
    };
    $state = fn (string $version): string => (string)json_encode(['current' => [
        'catalogVersion' => $version, 'catalogChangedAt' => catalog_iso(t_now('-30 days')), 'deployedAt' => t_now('-10 minutes')->getTimestamp(),
    ]]);
    file_put_contents($ctx['deployHome'] . '/state.json', $state($live));
    t_admin_call($ctx, 'products', $page);
    t_equal($seen, ['status' => '<p class="status status-synced">Все изменения на сайте.</p>', 'inSync' => true],
        'выложена версия выгрузки — «Все изменения на сайте.», хоть meta.version другая');
    // Сборка с новым кодом выложена 10 минут назад, а каталог в ней посчитан прежним кодом: ждём следующую.
    file_put_contents($ctx['deployHome'] . '/state.json', $state('прежний-код-выгрузки'));
    t_admin_call($ctx, 'products', $page);
    t_equal($seen['status'], '<p class="status status-pending">Ждёт выкладки с 13:50 — обычно до получаса.</p>',
        'версии разные, правка месяц назад, выкладка 10 минут назад — ждёт, а не «задерживается»');
    $db->exec("DELETE FROM meta");
    t_equal(admin_catalog_now($db), ['version' => null, 'changed_at' => null], 'правок ещё не было — ждать нечего');
});
