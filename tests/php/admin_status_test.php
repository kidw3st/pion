<?php
/**
 * Строка статуса: на сайте ли то, что сохранили, и отметки у изменений.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';

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
});

t_case('что выложено', function (): void {
    $home = t_tmpdir();
    t_equal(admin_deployed_catalog($home), null, 'нет state.json — неизвестно');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'commit' => 'def']]));
    t_equal(admin_deployed_catalog($home), null, 'сборка этапа 1 о каталоге не знает');
    file_put_contents("$home/state.json", json_encode(['current' => ['sha' => 'abc', 'catalogVersion' => 'v7', 'catalogChangedAt' => '2026-10-05T13:00:00+05:00']]));
    t_equal(admin_deployed_catalog($home), ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00'], 'версия и время выложенного каталога');
});

t_case('отметки у изменений', function (): void {
    $deployed = ['version' => 'v7', 'changedAt' => '2026-10-05T13:00:00+05:00'];
    t_equal(admin_change_on_site('2026-10-05T12:59:00+05:00', $deployed), true, 'раньше выложенного — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:00:00+05:00', $deployed), true, 'то же время — на сайте');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', $deployed), false, 'позже — ждёт выкладки');
    t_equal(admin_change_on_site('2026-10-05T13:01:00+05:00', null), null, 'неизвестно — без отметки');
});

t_case('строка на страницах', function (): void {
    $ctx = t_admin_ctx();
    $seen = '';
    $page = function (array $req, array $c) use (&$seen): array {
        $seen = $c['status'];
        return admin_html('ok');
    };
    t_admin_call($ctx, 'products', $page);
    t_equal($seen, '<p class="status status-offline">Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.</p>',
        'страницы получают готовую строку статуса');
});
