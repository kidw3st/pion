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
t_equal(deploy_state_idle(deploy_note_head($s, str_repeat('f', 40), 2000))['failure']['kind'], 'fatal', 'испорченная сборка — сбой остаётся');

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
$s = deploy_note_head($s, str_repeat('a', 40), 0);
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
$s = deploy_note_head($s, str_repeat('a', 40), 0);
$s = deploy_note_master($s, 'commit-new', 1);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', str_repeat('f', 40), 0);
t_equal(deploy_pending_alerts($s, 0), ['fatal'], 'испорченная сборка — сразу');
t_equal(deploy_pending_alerts($s, 10_000), ['fatal'], 'при сбое выкладки об отставании не пишем — причина та же');
// Чтобы проверка выше не была пустой: без сбоя то же состояние дало бы отставание.
$quiet = $s;
$quiet['failure'] = null;
t_equal(deploy_pending_alerts($quiet, 10_000), ['lag'], 'без сбоя то же состояние дало бы отставание');

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

// --- Голова ветки server-build: новый ключ состояния ---------------------
$x = str_repeat('e', 40);
$y = str_repeat('d', 40);
$z = str_repeat('c', 40);
t_equal(
    array_keys(deploy_empty_state()),
    ['current', 'history', 'bad', 'failure', 'alerts', 'master', 'head'],
    'head — последний ключ состояния',
);
$h = deploy_note_head(deploy_empty_state(), $x, 500);
t_equal($h['head'], ['sha' => $x, 'since' => 500], 'голова ветки запоминается вместе со временем');
$h = deploy_note_head($h, $x, 900);
t_equal($h['head']['since'] ?? null, 500, 'та же сборка — время прежнее');
$h = deploy_note_head($h, $y, 1300);
t_equal($h['head'], ['sha' => $y, 'since' => 1300], 'другая сборка — время заново');
$home = t_tmpdir();
file_put_contents($home . '/state.json', json_encode(['current' => null, 'history' => [], 'bad' => [], 'failure' => null, 'alerts' => [], 'master' => null]));
t_equal(deploy_state_load($home)['head'], null, 'файл состояния без head читается');
deploy_state_save($home, $h);
t_equal(deploy_state_load($home)['head'], ['sha' => $y, 'since' => 1300], 'голова с временем сохраняется и читается');

// --- После отката ложного «отставания» нет -------------------------------
// Откатились с «b» на «a». Сборка «b» остаётся последней в server-build, но
// коммитов master после неё нет: писать не о чём. А коммит, появившийся уже
// после отката и так не ставший сборкой, — настоящее отставание.
$s = deploy_empty_state();
foreach (['a', 'b'] as $i => $c) {
    $s = deploy_state_after_success($s, $rel($c), 100 * ($i + 1));
    $s = deploy_note_head($s, str_repeat($c, 40), 100 * ($i + 1));
    $s = deploy_note_master($s, 'commit-' . $c, 100 * ($i + 1));
}
$s = deploy_state_idle(deploy_state_after_rollback($s, 400));
t_equal($s['failure'], null, 'после отката сбоя нет');
// Коммит master (commit-b) не совпадает с выложенной сборкой «a», но «b» не
// выложена, так что вторая ветка правила (последняя сборка выложена) молчит.
t_equal(deploy_pending_alerts($s, 200 + 5400), [], 'откат: «b» всё ещё голова, но коммитов после неё нет — тишина');
t_equal(deploy_pending_alerts($s, 200 + 5400 + 10800), [], 'откат: и через 3 часа об отставании не напоминаем');
$s = deploy_note_master($s, 'commit-c', 1000);
t_equal(deploy_pending_alerts($s, 1000 + 5399), [], 'откат: новый коммит — сначала 90 минут ждём');
t_equal(deploy_pending_alerts($s, 1000 + 5400), ['lag'], 'откат: новый коммит так и не собрался — отставание настоящее');

// --- Сбой сети не затирает испорченную сборку ----------------------------
$s = deploy_note_head(deploy_empty_state(), $x, 100);
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', $x, 100);
$s = deploy_mark_alerted($s, 'fatal', 100);
$fatal = $s['failure'];
$s = deploy_state_after_failure($s, 'transient', 'таймаут', null, 1000);
t_equal($s['failure'], $fatal, 'мигнула сеть — поломка головы ветки остаётся');
t_equal(deploy_state_idle($s)['failure'], $fatal, 'тихий проход поломку головы не снимает');
t_equal(deploy_pending_alerts($s, 100 + 10799), [], 'испорченная сборка: раньше чем через 3 часа не напоминаем');
t_equal(deploy_pending_alerts($s, 100 + 10800), ['fatal'], 'испорченная сборка: через 3 часа напоминаем');

// --- Пришла другая сборка: прежняя поломка новый сбой не затеняет --------
$s = deploy_note_head(deploy_empty_state(), $x, 100);
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', $x, 100);
$s = deploy_note_head($s, $y, 1000);
$s = deploy_state_after_failure($s, 'transient', 'таймаут', $y, 1000);
t_equal($s['failure']['kind'], 'transient', 'голова сменилась — старая поломка не держится');
t_equal($s['failure']['since'], 1000, 'новый сбой — отсчёт заново');
t_equal(array_keys($s['bad']), [$x], 'испорченная сборка остаётся в плохих');

// --- Новая поломка сообщается по своему расписанию -----------------------
// Между поломками была выкладка.
$s = deploy_note_head(deploy_empty_state(), $x, 0);
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', $x, 0);
$s = deploy_mark_alerted($s, 'fatal', 0);
$s = deploy_state_after_success($s, $rel('a'), 900);
$s = deploy_note_head($s, $z, 1800);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', $z, 1800);
t_equal(deploy_pending_alerts($s, 1800), ['fatal'], 'после выкладки новая поломка — сразу, хоть писали недавно');
// Выкладки не было: следующая сборка тоже испорчена.
$s = deploy_note_head(deploy_empty_state(), $x, 0);
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', $x, 0);
$s = deploy_mark_alerted($s, 'fatal', 0);
$s = deploy_note_head($s, $z, 1800);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', $z, 1800);
t_equal($s['failure']['since'], 1800, 'другая испорченная сборка — отсчёт заново');
t_equal(deploy_pending_alerts($s, 1800), ['fatal'], 'другая испорченная сборка — сразу, не через 3 часа после прошлой');
// Та же сборка, тот же сбой: расписание прежнее.
$s = deploy_mark_alerted($s, 'fatal', 1800);
$s = deploy_state_after_failure($s, 'fatal', 'php -l', $z, 2700);
t_equal($s['failure']['since'], 1800, 'та же испорченная сборка — время начала прежнее');
t_equal(deploy_pending_alerts($s, 2700), [], 'та же испорченная сборка — расписание напоминаний прежнее');
// Выкладка сама сбрасывает расписание сбоев (но не отставания).
$s = deploy_mark_alerted(deploy_mark_alerted(deploy_mark_alerted(deploy_empty_state(), 'fatal', 1), 'transient', 2), 'lag', 3);
$s = deploy_state_after_success($s, $rel('a'), 10);
t_equal($s['alerts'], ['lag' => 3], 'выкладка сбрасывает расписание сбоев, но не отставания');

// --- Сбой сети отсчитывается сквозь смену стадии -------------------------
$s = deploy_state_after_failure(deploy_empty_state(), 'transient', 'GitHub не ответил', null, 0);
$s = deploy_state_after_failure($s, 'transient', 'не скачалась сборка', $x, 900);
$s = deploy_state_after_failure($s, 'transient', 'GitHub не ответил', null, 1800);
t_equal($s['failure']['since'], 0, 'сбои чередуются (опрос, скачивание), а отсчёт общий');
t_equal(deploy_pending_alerts($s, 3599), [], 'сеть: меньше часа подряд — молчим');
t_equal(deploy_pending_alerts($s, 3600), ['transient'], 'сеть: час подряд — пишем');

// --- Новый коммит в master — новое напоминание об отставании -------------
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_head($s, str_repeat('a', 40), 0);
$s = deploy_note_master($s, 'commit-new', 1000);
$s = deploy_mark_alerted($s, 'lag', 6400);
$s = deploy_note_master($s, 'commit-newer', 6500);
t_equal(deploy_pending_alerts($s, 6500 + 5399), [], 'новый коммит: сначала 90 минут ждём');
t_equal(deploy_pending_alerts($s, 6500 + 5400), ['lag'], 'новый коммит — своё напоминание, прошлое не мешает');

// --- Отставание — это коммит, после которого сборки так и нет ------------
// Сравниваются времена: сборка появляется в ветке через несколько минут после
// коммита. Коммит и сборка замечены за один запуск или сборка позже коммита —
// отставания нет, сколько бы времени ни прошло, если этот коммит в сборке есть.
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_master($s, 'commit-a', 5000);
$s = deploy_note_head($s, str_repeat('a', 40), 5000);
t_equal(deploy_pending_alerts($s, 5000 + 5400 + 10800), [], 'коммит и сборка замечены за один запуск — отставания нет');
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_head($s, str_repeat('a', 40), 5000);
$s = deploy_note_master($s, 'commit-a', 5000);
t_equal(deploy_pending_alerts($s, 5000 + 5400 + 10800), [], 'порядок вызовов в одном запуске не важен');
$s = deploy_state_after_success(deploy_empty_state(), $rel('a'), 0);
$s = deploy_note_head($s, str_repeat('a', 40), 0);
$s = deploy_note_master($s, 'commit-b', 1000);
$s = deploy_note_head($s, str_repeat('b', 40), 1900);
t_equal(deploy_pending_alerts($s, 1000 + 5400), [], 'сборка появилась после коммита — отставания нет');
t_equal(deploy_pending_alerts(deploy_note_master(deploy_empty_state(), 'commit-a', 0), 10_000), [], 'головы ветки ещё не видели — об отставании не пишем');
// Правилу по времени выложенная сборка не нужна: решают время коммита и сборки.
$s = deploy_note_head(deploy_empty_state(), str_repeat('a', 40), 0);
$s = deploy_note_master($s, 'commit-b', 1000);
t_equal(deploy_pending_alerts($s, 1000 + 5400), ['lag'], 'отставание определяют время коммита и сборки, выложенная сборка не нужна');

// --- Коммит, увиденный вместе с прошлой сборкой, а своей сборки так и нет -
// 12:14: опрос видит сборку B2 (коммит c2) и сразу коммит c3, по которому сборка
// не пошла (тесты упали). Время у коммита и сборки одно, «позже» не выходит; но
// последняя в ветке сборка уже выложена, а коммита master в ней нет.
$b2 = ['sha' => str_repeat('b', 40), 'commit' => 'c2', 'paySha256' => 'pay-b2'];
$b3 = ['sha' => str_repeat('c', 40), 'commit' => 'c3', 'paySha256' => 'pay-b3'];
$late = deploy_note_head(deploy_empty_state(), $b2['sha'], 0);
$late = deploy_note_master($late, 'c3', 0);
$late = deploy_state_after_success($late, $b2, 0);
t_equal(deploy_pending_alerts($late, 5399), [], 'коммит увиден вместе с прошлой сборкой: сначала 90 минут ждём');
t_equal(deploy_pending_alerts($late, 5400), ['lag'], 'коммит увиден вместе с прошлой сборкой и так не собрался — отставание');
$late = deploy_mark_alerted($late, 'lag', 5400);
t_equal(deploy_pending_alerts($late, 5400 + 10799), [], 'это отставание напоминает не чаще раза в 3 часа');
t_equal(deploy_pending_alerts($late, 5400 + 10800), ['lag'], 'это отставание напоминает через 3 часа');
// Обычный ход дел: коммит c3 замечен, затем появилась сборка B3 и выложена.
$s = deploy_state_after_success(deploy_empty_state(), $b2, -100);
$s = deploy_note_head($s, $b2['sha'], -100);
$s = deploy_note_master($s, 'c3', 0);
t_equal(deploy_pending_alerts($s, 5400), ['lag'], 'пока сборки по коммиту нет, отставание подступает');
$s = deploy_note_head($s, $b3['sha'], 600);
$s = deploy_state_after_success($s, $b3, 600);
t_equal(deploy_pending_alerts($s, 600 + 10_000), [], 'сборка по коммиту появилась и выложена — отставания нет');

// --- Тихий проход снимает устаревшую поломку -----------------------------
$s = deploy_note_head(deploy_empty_state(), $x, 100);
$s = deploy_state_after_failure($s, 'fatal', 'нет robots.txt', $x, 100);
t_equal(deploy_state_idle($s)['failure']['kind'], 'fatal', 'голова всё ещё испорчена — сбой остаётся');
t_equal(deploy_state_idle(deploy_note_head($s, $y, 200))['failure'], null, 'голова другая — поломка устарела, сбоя нет');

// --- Байты не в UTF-8 в тексте сбоя не мешают сохранить состояние --------
// php -l цитирует файл как есть, в том числе в CP1251.
$home = t_tmpdir();
$s = deploy_state_after_failure(deploy_empty_state(), 'fatal', "pay/init.php: ошибка \xC0\xAF и \xCF\xF0\xE8", $x, 100);
$threw = null;
try {
    deploy_state_save($home, $s);
} catch (Throwable $e) {
    $threw = $e;
}
t_true($threw === null, 'состояние сохраняется, хотя в тексте сбоя битые байты' . ($threw === null ? '' : ' (' . $threw->getMessage() . ')'));
$msg = (string)(deploy_state_load($home)['failure']['message'] ?? '');
t_true($msg !== '' && preg_match('//u', $msg) === 1, 'в файле корректный UTF-8');
t_true(str_starts_with($msg, 'pay/init.php: ошибка ') && str_contains($msg, "\u{FFFD}"), 'битые байты заменены, остальной текст на месте');
