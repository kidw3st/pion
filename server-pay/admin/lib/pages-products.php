<?php
/**
 * Букеты: список с поиском и фильтрами, карточка, действия со статусом.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../../catalog/products.php';

/** Фильтр «Статус»: значение => подпись. Пустое значение — все, кроме удалённых. */
const ADMIN_STATUS_FILTER = ['' => 'Все, кроме удалённых', 'active' => 'В продаже', 'hidden' => 'Сняты с продажи', 'draft' => 'Черновики', 'deleted' => 'Удалённые'];

function admin_page_products(array $req, array $ctx): array
{
    $db = $ctx['db'];
    $q = trim(admin_str($req['query'], 'q'));
    $section = admin_str($req['query'], 'section');
    $status = admin_str($req['query'], 'status');
    $sections = admin_sections($db);
    $labels = array_column($sections, 'label', 'slug');
    $needle = mb_strtolower($q);
    // Поиск — в PHP: LIKE и lower() в SQLite не понимают регистр кириллицы, а букетов — сотни.
    $items = array_filter(admin_products($db), function (array $p) use ($needle, $section, $status): bool {
        if ($status === '' ? $p['status'] === 'deleted' : $p['status'] !== $status) {
            return false;
        }
        if ($section !== '' && !in_array($section, $p['sections'], true)) {
            return false;
        }
        return $needle === '' || str_contains(mb_strtolower($p['title']), $needle);
    });

    $sectionOptions = '<option value="">Все разделы</option>';
    foreach ($sections as $s) {
        $sectionOptions .= '<option value="' . h($s['slug']) . '"' . ($s['slug'] === $section ? ' selected' : '') . '>' . h($s['label']) . '</option>';
    }
    $statusOptions = '';
    foreach (ADMIN_STATUS_FILTER as $value => $label) {
        $statusOptions .= '<option value="' . h($value) . '"' . ($value === $status ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $list = '';
    foreach ($items as $p) {
        $thumb = $p['images'] !== []
            ? '<img src="' . h($p['images'][0]) . '" alt="" loading="lazy">'
            : '<span class="thumb"></span>';
        $where = implode(', ', array_map(fn (string $s): string => $labels[$s] ?? $s, $p['sections']));
        $list .= '<li><a href="' . ADMIN_BASE . 'product.php?uid=' . h($p['uid']) . '">' . $thumb . '<span>'
            . '<span class="item-title">' . h($p['title']) . '</span><br><span class="item-meta">'
            . admin_rub((int)$p['price']) . ' · <span class="badge badge-' . h($p['status']) . '">'
            . h(ADMIN_STATUS_LABELS[$p['status']] ?? $p['status']) . '</span> · ' . h($where) . '</span></span></a></li>';
    }
    $html = '<h1>Букеты</h1>'
        . '<p class="buttons"><a class="btn" href="' . ADMIN_BASE . 'product.php?new=1">Добавить букет</a></p>'
        . '<form method="get" action="' . ADMIN_BASE . '" class="form filters">'
        . '<label>Поиск<input type="search" name="q" value="' . h($q) . '" placeholder="Название букета"></label>'
        . '<label>Раздел<select name="section">' . $sectionOptions . '</select></label>'
        . '<label>Статус<select name="status">' . $statusOptions . '</select></label>'
        . '<p class="buttons"><button class="btn-quiet" type="submit">Показать</button></p></form>'
        . '<p class="hint">Найдено: ' . count($items) . '</p>'
        . ($list !== '' ? '<ul class="items">' . $list . '</ul>' : '<p>Ничего не нашлось.</p>');
    return admin_html(admin_layout('Букеты', $html, $ctx['user'], $ctx['status'], admin_notice($req)));
}
