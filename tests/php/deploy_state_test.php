<?php

declare(strict_types=1);

require_once __DIR__ . '/../../server-pay/deploy-lib.php';

$rel = static fn(string $c): array => ['sha' => str_repeat($c, 40), 'commit' => 'commit-' . $c, 'paySha256' => 'pay-' . $c];

// --- Успешные выкладки: для отката хранятся две прошлые -------------------
$s = deploy_empty_state();
$s = deploy_state_after_success($s, $rel('a'), 100);
t_equal($s['current'], $rel('a') + ['deployedAt' => 100], 'первая выкладка');
t_equal($s['history'], [], 'истории ещё нет');
$s = deploy_state_after_success($s, $rel('b'), 200);
$s = deploy_state_after_success($s, $rel('c'), 300);
$s = deploy_state_after_success($s, $rel('d'), 400);
t_equal($s['current']['sha'], str_repeat('d', 40), 'текущая — последняя');
t_equal(array_column($s['history'], 'sha'), [str_repeat('c', 40), str_repeat('b', 40)], 'хранятся две прошлые, новая первой');

// --- Успешная выкладка снимает сбой ---------------------------------------
$s = deploy_state_after_failure($s, 'transient', 'GitHub не ответил', null, 500);
$s = deploy_state_after_success($s, $rel('e'), 600);
t_equal($s['failure'], null, 'после выкладки сбоя нет');

// --- Повторяющийся сбой помнит, когда начался -----------------------------
$s = deploy_empty_state();
$s = deploy_state_after_failure($s, 'transient', 'таймаут', null, 1000);
$s = deploy_state_after_failure($s, 'transient', 'код 502', null, 1900);
t_equal($s['failure']['since'], 1000, 'тот же сбой — время начала прежнее');
t_equal($s['failure']['message'], 'код 502', 'текст — последний');
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', str_repeat('f', 40), 2000);
t_equal($s['failure']['since'], 2000, 'другой сбой — отсчёт заново');
t_equal($s['bad'], [str_repeat('f', 40) => 'нет robots.txt'], 'испорченная сборка помечена плохой');

// --- Без дела: сеть восстановилась, а испорченная сборка всё ещё последняя -
$t = deploy_state_after_failure(deploy_empty_state(), 'transient', 'таймаут', null, 1);
t_equal(deploy_state_idle($t)['failure'], null, 'сеть восстановилась — сбоя нет');
t_equal(deploy_state_idle($s)['failure']['kind'], 'fatal', 'испорченная сборка — сбой остаётся');

// --- Список плохих сборок не растёт бесконечно ----------------------------
$b = deploy_empty_state();
for ($i = 0; $i < 25; $i++) {
    $b = deploy_mark_bad($b, str_pad(dechex($i), 40, 'a', STR_PAD_LEFT), 'плохая');
}
t_equal(count($b['bad']), 20, 'помним 20 последних плохих сборок');
t_true(isset($b['bad'][str_pad(dechex(24), 40, 'a', STR_PAD_LEFT)]), 'последняя на месте');
t_true(!isset($b['bad'][str_pad(dechex(0), 40, 'a', STR_PAD_LEFT)]), 'самая старая забыта');

// --- Откат -------------------------------------------------------------------
$s = deploy_empty_state();
foreach (['a', 'b', 'c'] as $i => $c) {
    $s = deploy_state_after_success($s, $rel($c), 100 * ($i + 1));
}
$s = deploy_state_after_rollback($s, 999);
t_equal($s['current'], $rel('b') + ['deployedAt' => 999], 'вернулись на прошлую');
t_equal(array_column($s['history'], 'sha'), [str_repeat('a', 40)], 'в истории осталась позапрошлая');
t_equal(array_keys($s['bad']), [str_repeat('c', 40)], 'откаченная помечена плохой');
$s = deploy_state_after_rollback($s, 1000);
t_throws(fn() => deploy_state_after_rollback($s, 1001), RuntimeException::class, 'дальше откатываться некуда');

// --- Отставание сборки от master ------------------------------------------
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_master($s, 'commit-a', 0);
t_equal(deploy_pending_alerts($s, 10_000), [], 'master собран — тишина');
$s = deploy_note_master($s, 'commit-new', 1000);
$s = deploy_note_master($s, 'commit-new', 3000);
t_equal($s['master']['since'], 1000, 'тот же master — время прежнее');
t_equal(deploy_pending_alerts($s, 1000 + 5399), [], 'меньше 90 минут — ждём');
t_equal(deploy_pending_alerts($s, 1000 + 5400), ['lag'], '90 минут без сборки — пишем');
$s = deploy_mark_alerted($s, 'lag', 6400);
t_equal(deploy_pending_alerts($s, 6400 + 3600), [], 'через час не повторяем');
t_equal(deploy_pending_alerts($s, 6400 + 10800), ['lag'], 'через 3 часа напоминаем');

// --- Сбои -------------------------------------------------------------------
$s = deploy_state_after_failure(deploy_empty_state(), 'transient', 'таймаут', null, 0);
t_equal(deploy_pending_alerts($s, 3599), [], 'сеть: меньше часа — молчим');
t_equal(deploy_pending_alerts($s, 3600), ['transient'], 'сеть: час — пишем');
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_master($s, 'commit-new', 0);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', str_repeat('f', 40), 0);
t_equal(deploy_pending_alerts($s, 0), ['fatal'], 'испорченная сборка — сразу');
t_equal(deploy_pending_alerts($s, 10_000), ['fatal'], 'при сбое выкладки об отставании не пишем — причина та же');

// --- Текст сообщений ------------------------------------------------------
$text = deploy_alert_text('fatal', $s);
t_true(str_contains($text, 'fffffff') && str_contains($text, 'php -l'), 'в сообщении сборка и причина');
t_true(str_contains(deploy_alert_text('lag', deploy_note_master($s, 'abcdef1234', 0)), 'abcdef1'), 'в сообщении об отставании — коммит');

// --- Состояние на диске ----------------------------------------------------
$home = t_tmpdir();
t_equal(deploy_state_load($home), deploy_empty_state(), 'нет файла — пустое состояние');
deploy_state_save($home, $s);
t_equal(deploy_state_load($home), $s, 'сохраняется и читается без потерь');

// --- Скачанные сборки ------------------------------------------------------
$home = t_tmpdir();
$s = deploy_empty_state();
foreach (['a', 'b', 'c', 'd'] as $i => $c) {
    mkdir(deploy_release_dir($home, str_repeat($c, 40)), 0777, true);
    $s = deploy_state_after_success($s, $rel($c), $i);
}
mkdir(deploy_release_dir($home, str_repeat('e', 40)) . '.part', 0777, true);
t_equal(
    deploy_prune_releases($home, $s),
    [str_repeat('a', 40), str_repeat('e', 40) . '.part'],
    'удалены лишняя сборка и недокачанная',
);
t_true(is_dir(deploy_release_dir($home, str_repeat('b', 40))), 'позапрошлая для отката на месте');
deploy_save_release_files($home, str_repeat('d', 40), ['index.html', 'a/b.html']);
t_equal(deploy_release_files($home, str_repeat('d', 40)), ['index.html', 'a/b.html'], 'список файлов сборки');
t_equal(deploy_release_files($home, null), null, 'нет сборки — нет списка');
t_equal(deploy_release_files($home, str_repeat('b', 40)), null, 'список не сохранялся — null');
