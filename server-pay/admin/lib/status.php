<?php
/**
 * Строка статуса вверху каждого экрана: на сайте ли то, что сохранили.
 * Сравнивается версия, которую выгрузка базы отдала бы сейчас
 * (catalog_current_version), с версией, по которой собрана выложенная сборка:
 * её пишет выкладка в pion-deploy/state.json — current.catalogVersion и
 * current.catalogChangedAt (этап 3). Пока этих полей нет, сайт собирается из
 * прежнего каталога, и строка честно это говорит. Сравнение — по версии, а не
 * по времени: правка туда и обратно оставляет новое время при том же
 * содержимом.
 */

declare(strict_types=1);

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/../../catalog/export.php';

/** Через сколько минут ожидания выкладка считается задержавшейся. */
const ADMIN_DEPLOY_LATE_MINUTES = 90;

/**
 * Что выложено: версия и время правки каталога выложенной сборки и когда её
 * выложили (deployedAt, секунды; null — неизвестно). Мусор в state.json не
 * роняет страницы: такое значение считается неизвестным.
 *
 * @return array{version: string, changedAt: string, deployedAt: ?int}|null
 */
function admin_deployed_catalog(string $deployHome): ?array
{
    $state = json_decode((string)@file_get_contents($deployHome . '/state.json'), true);
    $current = is_array($state) ? ($state['current'] ?? null) : null;
    // Пустая версия — то же, что отсутствующая: сравнивать с ней нечего.
    if (!is_array($current) || !is_string($current['catalogVersion'] ?? null) || $current['catalogVersion'] === '') {
        return null;
    }
    $changed = $current['catalogChangedAt'] ?? '';
    // Мусор вместо даты не должен ронять журнал и карточки: такую отметку считаем неизвестной.
    // Ловим Throwable, а не Exception: NUL-байт в строке даёт ValueError уже на проверке вида.
    try {
        if (!is_string($changed) || ($changed !== '' && DateTimeImmutable::createFromFormat(DATE_ATOM, $changed) === false)) {
            $changed = '';
        }
        if ($changed !== '') {
            // Дата по форме верная, но может не разбираться (месяц 13, час 25, пояс +99:00) — а разбирает её admin_change_on_site.
            new DateTimeImmutable($changed);
        }
    } catch (Throwable) {
        $changed = '';
    }
    // deployedAt пишет deploy.php целым числом (deploy_state_after_success, после отката — тоже); иное — неизвестно.
    $deployedAt = $current['deployedAt'] ?? null;
    return [
        'version' => $current['catalogVersion'],
        'changedAt' => $changed,
        'deployedAt' => is_int($deployedAt) && $deployedAt > 0 ? $deployedAt : null,
    ];
}

/**
 * Каталог в базе для строки статуса, из одного снимка базы: version — что
 * выгрузка отдала бы сейчас (не meta.version, см. catalog_current_version),
 * changed_at — время последней правки выгрузки из meta. Правок ещё не было (в
 * meta нет версии) — version null: ждать выкладки нечего. Выгрузка не считается
 * (строку в базе испортили руками) — version берётся из meta, одна строка уходит в
 * error_log: статус не роняет страницы (так же поступает сторож, deploy_catalog_watch).
 *
 * @return array{version: ?string, changed_at: ?string}
 */
function admin_catalog_now(PDO $db): array
{
    return catalog_read($db, static function () use ($db): array {
        $meta = catalog_meta($db);
        $saved = (string)($meta['version'] ?? '');
        if ($saved === '') {
            return ['version' => null, 'changed_at' => $meta['changed_at'] ?? null];
        }
        try {
            $version = catalog_current_version($db);
        } catch (Throwable $e) {
            // Выгрузка не считается (строку в базе испортили руками): строка статуса не должна
            // ронять все страницы — сверяем версию последнего сохранения, как сторож (deploy_catalog_watch).
            error_log('админка: выгрузка каталога не считается: ' . $e->getMessage());
            $version = $saved;
        }
        return ['version' => $version, 'changed_at' => $meta['changed_at'] ?? null];
    });
}

/**
 * Ждать выкладки — с последней правки, но не раньше выкладки текущей сборки:
 * расхождение версий бывает и без правки, когда выложили новый код выгрузки, а
 * сборку по нему привезёт следующий запуск (подробно — в
 * deploy_pending_catalog_alerts, server-pay/deploy-lib.php; сторож считает так же).
 * Времени выкладки нет — только от правки.
 *
 * @param array{version: ?string, changed_at: ?string} $catalog admin_catalog_now()
 * @param array{version: string, changedAt: string, deployedAt?: ?int}|null $deployed admin_deployed_catalog()
 * @return array{kind: string, text: string}
 */
function admin_deploy_status(array $catalog, ?array $deployed, DateTimeImmutable $now): array
{
    if ($deployed === null) {
        return ['kind' => 'offline', 'text' => 'Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.'];
    }
    $version = $catalog['version'] ?? null;
    if ($version === null || $version === $deployed['version']) {
        return ['kind' => 'synced', 'text' => 'Все изменения на сайте.'];
    }
    $since = admin_perm($catalog['changed_at']);
    $deployedAt = $deployed['deployedAt'] ?? null;
    // Время из будущего (не бывает: его пишет deploy.php через time()) отодвинуло бы «задерживается» до него —
    // такое время считаем неизвестным, и ожидание идёт от правки. (Подмена его на «сейчас» обнулила бы ожидание.)
    if (is_int($deployedAt) && $deployedAt > $since->getTimestamp() && $deployedAt <= $now->getTimestamp()) {
        $since = admin_perm('@' . $deployedAt);
    }
    if ($now->getTimestamp() - $since->getTimestamp() > ADMIN_DEPLOY_LATE_MINUTES * 60) {
        return ['kind' => 'late', 'text' => 'Выкладка задерживается — разработчик уведомлён.'];
    }
    return ['kind' => 'pending', 'text' => 'Ждёт выкладки с ' . $since->format('H:i') . ' — обычно до получаса.'];
}

function admin_status_line(array $status): string
{
    return '<p class="status status-' . h($status['kind']) . '">' . h($status['text']) . '</p>';
}

/**
 * Отметка у изменения в журнале и карточке: true — на сайте, false — ждёт выкладки, null — неизвестно.
 * $inSync — версия каталога в базе совпадает с выложенной: при совпадении версий все правки
 * (включая те, что не меняют выгрузку: черновики, пароли, смены сотрудников) уже на сайте.
 */
function admin_change_on_site(string $at, ?array $deployed, bool $inSync = false): ?bool
{
    if ($deployed === null) {
        return null;
    }
    if ($inSync) {
        return true;
    }
    if ($deployed['changedAt'] === '') {
        return null;
    }
    // Сравнение временных меток как моментов: они в одном формате и поясе (catalog_iso).
    return (new DateTimeImmutable($at))->getTimestamp() <= (new DateTimeImmutable($deployed['changedAt']))->getTimestamp();
}
