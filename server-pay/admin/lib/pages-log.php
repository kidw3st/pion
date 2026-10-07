<?php
/**
 * Журнал: кто, когда и что поменял — было → стало. Последние 500
 * изменений, у каждого — отметка «на сайте» или «ждёт выкладки».
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/queries.php';

const ADMIN_FIELD_LABELS = [
    'title' => 'Название', 'description' => 'Состав', 'price' => 'Цена', 'images' => 'Фото', 'sections' => 'Разделы',
    'mainSection' => 'Главный раздел', 'slug' => 'Адрес', 'status' => 'Статус', 'created' => 'Создан', 'deleted' => 'Удалён',
    'label' => 'Название раздела', 'tileImage' => 'Фото плитки', 'visible' => 'Показывать в каталоге',
    'coverTitle' => 'Заголовок обложки', 'coverSub' => 'Подзаголовок обложки', 'covers' => 'Фото обложки',
    'heading' => 'Заголовок над сеткой', 'headingSub' => 'Подзаголовок над сеткой', 'hasNotFound' => 'Блок «Не нашли нужное?»',
    'seoTitle' => 'Заголовок для поиска', 'seoDescription' => 'Описание для поиска', 'order' => 'Порядок плиток',
    'password' => 'Пароль', 'imported' => 'Загрузка каталога',
];

/**
 * Поля, у которых показывается только название: «было → стало» тут ничего не скажет
 * (или скажет лишнее: новый пароль, номера плиток в базе).
 */
const ADMIN_FIELDS_LABEL_ONLY = ['created', 'deleted', 'imported', 'password', 'order'];

/**
 * Кто записан в журнале, если это не сотрудник из админки: учётные записи заводит и пароли
 * сбрасывает разработчик из консоли, каталог загружает он же. Служебные слова сотрудникам не показываем.
 */
const ADMIN_SERVICE_ACTORS = ['console' => 'Разработчик', 'import' => 'Разработчик'];

/** Значение из журнала — по-человечески: рубли, статусы и разделы словами, фото — числом. */
function admin_audit_value(string $field, ?string $value, array $sectionLabels): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return match ($field) {
        'price' => ctype_digit($value) ? admin_rub((int)$value) : $value,
        'status' => ADMIN_STATUS_LABELS[$value] ?? $value,
        'images', 'covers' => count(json_decode($value, true) ?: []) . ' фото',
        'sections' => implode(', ', array_map(fn (string $s): string => $sectionLabels[$s] ?? $s, explode(', ', $value))),
        'mainSection' => $sectionLabels[$value] ?? $value,
        'visible', 'hasNotFound' => $value === '1' ? 'да' : 'нет',
        default => mb_strimwidth($value, 0, 160, '…'),
    };
}

/** Последние изменения (свежие — первыми) с именем сотрудника; $productUid — только по одному букету. */
function admin_audit_rows(PDO $db, ?string $productUid = null, int $limit = 500): array
{
    $where = $productUid !== null ? "WHERE a.object_type = 'product' AND a.object_id = ?" : '';
    $q = $db->prepare("SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.login = a.login $where ORDER BY a.id DESC LIMIT " . $limit);
    $q->execute($productUid !== null ? [$productUid] : []);
    return $q->fetchAll();
}

/**
 * Список изменений с отметками «на сайте / ждёт выкладки». $deployed и $inSync — из $ctx
 * (их уже прочитал admin_handle), state.json второй раз не читается.
 * $withObject — писать ли, что изменено (в карточке букета и так понятно, чьё это).
 */
function admin_audit_list(PDO $db, array $rows, ?array $deployed, bool $inSync, bool $withObject): string
{
    $sectionLabels = array_column(admin_sections($db), 'label', 'slug');
    $titles = $db->query('SELECT uid, title FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
    $items = '';
    foreach ($rows as $r) {
        $object = '';
        if ($withObject) {
            $object = match ($r['object_type']) {
                // Черновик, удалённый совсем, карточки уже не имеет — ссылка вела бы на «Такого букета нет».
                'product' => isset($titles[$r['object_id']])
                    ? '<a href="' . ADMIN_BASE . 'product.php?uid=' . h($r['object_id']) . '">' . h($titles[$r['object_id']]) . '</a> · '
                    : 'букет удалён · ',
                'section' => '<a href="' . ADMIN_BASE . 'section.php?slug=' . h($r['object_id']) . '">' . h($sectionLabels[$r['object_id']] ?? $r['object_id']) . '</a> · ',
                'tiles' => 'Плитки каталога · ',
                'user' => 'Сотрудник ' . h($r['object_id']) . ' · ',
                default => '',
            };
        }
        $field = ADMIN_FIELD_LABELS[$r['field']] ?? $r['field'];
        $change = in_array($r['field'], ADMIN_FIELDS_LABEL_ONLY, true)
            ? h($field)
            : h($field) . ': ' . h(admin_audit_value($r['field'], $r['old_value'], $sectionLabels))
                . ' → ' . h(admin_audit_value($r['field'], $r['new_value'], $sectionLabels));
        $mark = match (admin_change_on_site($r['at'], $deployed, $inSync)) {
            true => ' · <span class="on-site">на сайте</span>',
            false => ' · <span class="waiting">ждёт выкладки</span>',
            null => '',
        };
        $who = $r['name'] ?? ADMIN_SERVICE_ACTORS[$r['login']] ?? $r['login'];
        $items .= '<li class="log-item"><div class="log-head">' . h(admin_date($r['at'])) . ' · ' . h($who) . $mark . '</div>'
            . '<div>' . $object . $change . '</div></li>';
    }
    return $items === '' ? '<p class="hint">Изменений пока нет.</p>' : '<ul class="log">' . $items . '</ul>';
}

function admin_page_log(array $req, array $ctx): array
{
    $html = '<h1>Журнал</h1><p class="hint">Последние 500 изменений: кто, когда и что поменял.</p>'
        . admin_audit_list($ctx['db'], admin_audit_rows($ctx['db']), $ctx['deployed'], $ctx['inSync'], true);
    return admin_html(admin_layout('Журнал', $html, $ctx['user'], $ctx['status']));
}
