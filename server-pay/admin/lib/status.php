<?php
/**
 * Строка статуса вверху каждого экрана: на сайте ли то, что сохранили.
 * Сравнивается версия каталога в базе (catalog_meta) с версией, по которой
 * собрана выложенная сборка: её пишет выкладка в pion-deploy/state.json —
 * current.catalogVersion и current.catalogChangedAt (этап 3). Пока этих полей
 * нет, сайт собирается из прежнего каталога, и строка честно это говорит.
 * Сравнение — по версии, а не по времени: правка туда и обратно оставляет
 * новое время при том же содержимом.
 */

declare(strict_types=1);

require_once __DIR__ . '/view.php';

/** Через сколько минут ожидания выкладка считается задержавшейся. */
const ADMIN_DEPLOY_LATE_MINUTES = 90;

/** @return array{version: string, changedAt: string}|null */
function admin_deployed_catalog(string $deployHome): ?array
{
    $state = json_decode((string)@file_get_contents($deployHome . '/state.json'), true);
    $current = is_array($state) ? ($state['current'] ?? null) : null;
    if (!is_array($current) || !is_string($current['catalogVersion'] ?? null)) {
        return null;
    }
    $changed = $current['catalogChangedAt'] ?? '';
    // Мусор вместо даты не должен ронять журнал и карточки: такую отметку считаем неизвестной.
    if (!is_string($changed) || ($changed !== '' && DateTimeImmutable::createFromFormat(DATE_ATOM, $changed) === false)) {
        $changed = '';
    }
    return ['version' => $current['catalogVersion'], 'changedAt' => $changed];
}

/**
 * @param array<string, string> $meta catalog_meta(): version и changed_at последнего изменения выгрузки
 * @return array{kind: string, text: string}
 */
function admin_deploy_status(array $meta, ?array $deployed, DateTimeImmutable $now): array
{
    if ($deployed === null) {
        return ['kind' => 'offline', 'text' => 'Сайт пока собирается из прежнего каталога — изменения отсюда на нём не появятся.'];
    }
    $version = $meta['version'] ?? null;
    if ($version === null || $version === $deployed['version']) {
        return ['kind' => 'synced', 'text' => 'Все изменения на сайте.'];
    }
    $since = admin_perm($meta['changed_at']);
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
