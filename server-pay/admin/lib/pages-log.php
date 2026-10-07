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
 * (или скажет лишнее: число загруженных букетов, номера плиток в базе).
 */
const ADMIN_FIELDS_LABEL_ONLY = ['created', 'deleted', 'imported', 'order'];

/**
 * Что журнал хранит вместо пароля: только слова «сброшен» (сбросил разработчик) и «сменён» (сменил сам
 * сотрудник), сам пароль — никогда. Показываем только эти слова: что бы ни попало в журнал, лишнее не выводится.
 */
const ADMIN_PASSWORD_WORDS = ['сброшен', 'сменён'];

/**
 * Кто записан в журнале, если это не сотрудник из админки: учётные записи заводит и пароли
 * сбрасывает разработчик из консоли, каталог загружает он же. Служебные слова сотрудникам не показываем.
 */
const ADMIN_SERVICE_ACTORS = ['console' => 'Разработчик', 'import' => 'Разработчик'];

/** Список фото из журнала (JSON-массив путей) или null, если там что-то другое. */
function admin_audit_photos(?string $value): ?array
{
    $list = json_decode((string)$value, true);
    if (!is_array($list) || !array_is_list($list)) {
        return null;
    }
    foreach ($list as $path) {
        if (!is_string($path)) {
            return null;
        }
    }
    return $list;
}

/** Значение из журнала — по-человечески: рубли, статусы и разделы словами, фото — числом. */
function admin_audit_value(string $field, ?string $value, array $sectionLabels): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if ($field === 'images' || $field === 'covers') {
        // Не список путей (в журнал такое не пишут, но он не должен ронять страницу) — показываем как есть.
        $photos = admin_audit_photos($value);
        return $photos !== null ? count($photos) . ' фото' : mb_strimwidth($value, 0, 160, '…');
    }
    return match ($field) {
        'price' => ctype_digit($value) ? admin_rub((int)$value) : $value,
        'status' => ADMIN_STATUS_LABELS[$value] ?? $value,
        // Путь к файлу сотруднику ничего не говорит: есть ли фото — вот что важно.
        'tileImage' => 'есть фото',
        'sections' => implode(', ', array_map(fn (string $s): string => $sectionLabels[$s] ?? $s, explode(', ', $value))),
        'mainSection' => $sectionLabels[$value] ?? $value,
        'visible', 'hasNotFound' => $value === '1' ? 'да' : 'нет',
        default => mb_strimwidth($value, 0, 160, '…'),
    };
}

/**
 * Фото не добавили и не убрали, а заменили или переставили: «1 фото → 1 фото» ничего не объясняет,
 * поэтому говорим словами. null — это не тот случай, остаётся обычное «было → стало».
 */
function admin_audit_photo_change(string $field, ?string $old, ?string $new): ?string
{
    if ($field === 'tileImage') {
        return ($old ?? '') !== '' && ($new ?? '') !== '' && $old !== $new ? 'заменено' : null;
    }
    if ($field !== 'images' && $field !== 'covers') {
        return null;
    }
    $before = admin_audit_photos($old);
    $after = admin_audit_photos($new);
    if ($before === null || $after === null || $before === $after || count($before) !== count($after)) {
        return null;
    }
    sort($before);
    sort($after);
    return $before === $after ? 'порядок изменён' : 'заменены';
}

/** Что изменилось, одной строкой текста (до экранирования): «Цена: 4 400 ₽ → 4 800 ₽», «Фото: порядок изменён». */
function admin_audit_change(string $field, ?string $old, ?string $new, array $sectionLabels): string
{
    $label = ADMIN_FIELD_LABELS[$field] ?? $field;
    if (in_array($field, ADMIN_FIELDS_LABEL_ONLY, true)) {
        return $label;
    }
    if ($field === 'password') {
        return in_array($new, ADMIN_PASSWORD_WORDS, true) ? $label . ' ' . $new : $label;
    }
    $photos = admin_audit_photo_change($field, $old, $new);
    if ($photos !== null) {
        return $label . ': ' . $photos;
    }
    return $label . ': ' . admin_audit_value($field, $old, $sectionLabels) . ' → ' . admin_audit_value($field, $new, $sectionLabels);
}

/** Последние изменения (свежие — первыми) с именем сотрудника; $productUid — только по одному букету. */
function admin_audit_rows(PDO $db, ?string $productUid = null, int $limit = 500): array
{
    $where = $productUid !== null ? "WHERE a.object_type = 'product' AND a.object_id = ?" : '';
    // В SQLite LIMIT -1 — «без предела»: отрицательное число не должно открыть весь журнал.
    $q = $db->prepare("SELECT a.*, u.name FROM audit a LEFT JOIN users u ON u.login = a.login $where ORDER BY a.id DESC LIMIT " . max(1, $limit));
    $q->execute($productUid !== null ? [$productUid] : []);
    return $q->fetchAll();
}

/**
 * Названия букетов, которых в базе уже нет: черновик, удалённый совсем, стирает запись, но в журнале
 * его название остаётся — в «создан» (новое значение) и «удалён» (прежнее). uid => название.
 */
function admin_audit_gone_titles(PDO $db, array $uids): array
{
    $titles = [];
    // Пачками: в SQLite 3.26 на запрос не больше 999 параметров.
    foreach (array_chunk($uids, 400) as $chunk) {
        $q = $db->prepare("SELECT object_id, field, old_value, new_value FROM audit
            WHERE object_type = 'product' AND field IN ('created', 'deleted')
                AND object_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $q->execute($chunk);
        foreach ($q->fetchAll() as $a) {
            $title = $a['field'] === 'created' ? $a['new_value'] : $a['old_value'];
            if ($title !== null && $title !== '') {
                $titles[$a['object_id']] = $title;
            }
        }
    }
    return $titles;
}

/** Что изменено — HTML для строки над изменением: букет или раздел (ссылкой), плитки, сотрудник. */
function admin_audit_object(array $r, array $titles, array $goneTitles, array $sectionLabels): string
{
    return match ($r['object_type']) {
        'product' => isset($titles[$r['object_id']])
            ? '<a href="' . ADMIN_BASE . 'product.php?uid=' . h($r['object_id']) . '">' . h($titles[$r['object_id']]) . '</a>'
            // Черновик, удалённый совсем, карточки уже не имеет — ссылка вела бы на «Такого букета нет».
            : (isset($goneTitles[$r['object_id']]) ? h($goneTitles[$r['object_id']]) . ' (удалён)' : 'букет удалён'),
        'section' => '<a href="' . ADMIN_BASE . 'section.php?slug=' . h($r['object_id']) . '">' . h($sectionLabels[$r['object_id']] ?? $r['object_id']) . '</a>',
        'tiles' => 'Плитки каталога',
        'user' => 'Сотрудник ' . h($r['object_id']),
        default => '',
    };
}

/**
 * Список изменений с отметками «на сайте / ждёт выкладки». $deployed и $inSync — из $ctx
 * (их уже прочитал admin_handle), state.json второй раз не читается.
 * $withObject — писать ли, что изменено, отдельной строкой над изменением (в карточке букета
 * и так понятно, чьё это).
 */
function admin_audit_list(PDO $db, array $rows, ?array $deployed, bool $inSync, bool $withObject): string
{
    $sectionLabels = array_column(admin_sections($db), 'label', 'slug');
    $titles = [];
    $goneTitles = [];
    if ($withObject) {
        $titles = $db->query('SELECT uid, title FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
        $gone = [];
        foreach ($rows as $r) {
            if ($r['object_type'] === 'product' && !isset($titles[$r['object_id']])) {
                $gone[$r['object_id']] = $r['object_id'];
            }
        }
        $goneTitles = $gone === [] ? [] : admin_audit_gone_titles($db, array_map(strval(...), array_values($gone)));
    }
    $items = '';
    foreach ($rows as $r) {
        $object = $withObject ? admin_audit_object($r, $titles, $goneTitles, $sectionLabels) : '';
        // Ссылка на своей строке и не ниже 44 px (admin.css): в одной строке с текстом её не нажать пальцем.
        $objectLine = $object === '' ? '' : '<div class="log-object">' . $object . '</div>';
        $change = admin_audit_change($r['field'], $r['old_value'], $r['new_value'], $sectionLabels);
        // Учётные записи и пароли на сайт не выкладываются — «на сайте» про них ничего не значит.
        $on = $r['object_type'] === 'user' ? null : admin_change_on_site($r['at'], $deployed, $inSync);
        $mark = match ($on) {
            true => ' · <span class="on-site">на сайте</span>',
            false => ' · <span class="waiting">ждёт выкладки</span>',
            null => '',
        };
        $who = $r['name'] ?? ADMIN_SERVICE_ACTORS[$r['login']] ?? $r['login'];
        $items .= '<li class="log-item"><div class="log-head">' . h(admin_date($r['at'])) . ' · ' . h($who) . $mark . '</div>'
            . $objectLine . '<div>' . h($change) . '</div></li>';
    }
    return $items === '' ? '<p class="hint">Изменений пока нет.</p>' : '<ul class="log">' . $items . '</ul>';
}

function admin_page_log(array $req, array $ctx): array
{
    $html = '<h1>Журнал</h1><p class="hint">Последние 500 изменений: кто, когда и что поменял.</p>'
        . admin_audit_list($ctx['db'], admin_audit_rows($ctx['db']), $ctx['deployed'], $ctx['inSync'], true);
    return admin_html(admin_layout('Журнал', $html, $ctx['user'], $ctx['status']));
}
