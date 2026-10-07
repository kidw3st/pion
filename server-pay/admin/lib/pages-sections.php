<?php
/**
 * Разделы: порядок плиток в каталоге, новый раздел, карточка раздела.
 * Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/sections.php';

/** Плитки в порядке сетки: у раздела — его название и видимость, у постоянной — подпись. */
function admin_tiles(PDO $db): array
{
    return $db->query('SELECT t.id, t.type, t.section, t.label, s.label AS section_label, s.visible
        FROM tiles t LEFT JOIN sections s ON s.slug = t.section ORDER BY t.position, t.id')->fetchAll();
}

function admin_page_sections(array $req, array $ctx): array
{
    $error = '';
    if ($req['method'] === 'POST') {
        $post = $req['post'];
        $action = admin_str($post, 'action');
        try {
            if ($action === 'create') {
                $slug = catalog_create_section($ctx['db'], $ctx['user']['login'], admin_str($post, 'label'), $ctx['now']);
                return admin_redirect('section.php', ['slug' => $slug, 'notice' => 'section-created']);
            }
            if ($action === 'move') {
                $ids = array_map('intval', array_column(admin_tiles($ctx['db']), 'id'));
                $i = array_search((int)admin_str($post, 'tile'), $ids, true);
                $j = $i === false ? -1 : $i + (admin_str($post, 'dir') === 'up' ? -1 : 1);
                if ($i !== false && isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    catalog_reorder_tiles($ctx['db'], $ctx['user']['login'], $ids, $ctx['now']);
                }
                return admin_redirect('sections.php');
            }
            $error = 'Неизвестное действие — обновите страницу.';
        } catch (CatalogError $e) {
            $error = $e->getMessage();
        }
    }
    $rows = '';
    foreach (admin_tiles($ctx['db']) as $t) {
        $name = $t['type'] === 'section'
            ? '<a href="' . ADMIN_BASE . 'section.php?slug=' . h($t['section']) . '">' . h($t['section_label']) . '</a>'
                . ((int)$t['visible'] === 1 ? '' : ' <span class="badge">скрыт</span>')
            : h($t['label']) . ' <span class="hint">— постоянная плитка</span>';
        $move = '';
        foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow) {
            $move .= '<form method="post" action="' . ADMIN_BASE . 'sections.php">' . admin_csrf_field($ctx['user'])
                . '<input type="hidden" name="action" value="move"><input type="hidden" name="tile" value="' . (int)$t['id'] . '">'
                . '<button class="btn-small" type="submit" name="dir" value="' . $dir . '">' . $arrow . '</button></form>';
        }
        $rows .= '<li><div class="tile-row"><span>' . $name . '</span><span>' . $move . '</span></div></li>';
    }
    $html = '<h1>Разделы и плитки</h1>'
        . '<p class="hint">Порядок — как в сетке каталога на сайте. Скрытого раздела в сетке нет, но его страница работает.</p>'
        . admin_error($error) . '<ol class="tiles">' . $rows . '</ol>'
        . '<h2>Новый раздел</h2>'
        . '<form method="post" action="' . ADMIN_BASE . 'sections.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="create">'
        . '<label>Название<input name="label" maxlength="60" required value="' . h(admin_str($req['post'], 'label')) . '"></label>'
        . '<p class="buttons"><button class="btn" type="submit">Создать раздел</button></p></form>';
    return admin_html(admin_layout('Разделы', $html, $ctx['user'], $ctx['status'], admin_notice($req)), $error !== '' ? 422 : 200);
}

/**
 * Поля карточки, которые сотрудник поменял: сравниваются с исходными
 * значениями из формы (orig). Нет исходного значения — поле считается изменённым.
 */
function admin_section_changes(array $post): array
{
    $orig = is_array($post['orig'] ?? null) ? $post['orig'] : [];
    $now = [
        'label' => admin_str($post, 'label'),
        'tileImage' => admin_str($post, 'tileImage'),
        'visible' => admin_str($post, 'visible') === '1' ? '1' : '0',
        'coverTitle' => admin_str($post, 'coverTitle'),
        'coverSub' => admin_str($post, 'coverSub'),
        'covers' => json_encode(admin_list($post, 'covers'), CATALOG_JSON),
        'heading' => admin_str($post, 'heading'),
        'headingSub' => admin_str($post, 'headingSub'),
        'hasNotFound' => admin_str($post, 'hasNotFound') === '1' ? '1' : '0',
        'seoTitle' => admin_str($post, 'seoTitle'),
        'seoDescription' => admin_str($post, 'seoDescription'),
    ];
    $changes = [];
    foreach ($now as $field => $value) {
        if (!is_string($orig[$field] ?? null) || $orig[$field] !== $value) {
            $changes[$field] = match ($field) {
                'visible', 'hasNotFound' => $value === '1',
                'covers' => json_decode($value, true),
                default => $value,
            };
        }
    }
    return $changes;
}

function admin_page_section(array $req, array $ctx): array
{
    $slug = $req['method'] === 'POST' ? admin_str($req['post'], 'slug') : admin_str($req['query'], 'slug');
    $q = $ctx['db']->prepare('SELECT * FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    $row = $q->fetch();
    if ($row === false) {
        return admin_not_found($ctx, 'Такого раздела нет', 'sections.php');
    }
    if ($req['method'] === 'POST') {
        try {
            $changes = admin_section_changes($req['post']);
            if ($changes !== []) {
                catalog_update_section($ctx['db'], $ctx['user']['login'], $slug, $changes, $ctx['now']);
            }
            return admin_redirect('section.php', ['slug' => $slug, 'notice' => 'saved']);
        } catch (CatalogError $e) {
            return admin_html(admin_layout($row['label'], admin_section_form($ctx, $row, $req['post'], $e->getMessage()),
                $ctx['user'], $ctx['status']), 422);
        }
    }
    return admin_html(admin_layout($row['label'], admin_section_form($ctx, $row, null, ''), $ctx['user'], $ctx['status'], admin_notice($req)));
}

/** Карточка раздела. $post — прислано формой (показать снова после ошибки), null — из базы. */
function admin_section_form(array $ctx, array $row, ?array $post, string $error): string
{
    $orig = [
        'label' => $row['label'], 'tileImage' => $row['tile_image'], 'visible' => $row['visible'] ? '1' : '0',
        'coverTitle' => $row['cover_title'], 'coverSub' => $row['cover_sub'],
        'covers' => json_encode(json_decode($row['covers'], true) ?: [], CATALOG_JSON),
        'heading' => $row['heading'], 'headingSub' => $row['heading_sub'], 'hasNotFound' => $row['has_not_found'] ? '1' : '0',
        'seoTitle' => (string)$row['seo_title'], 'seoDescription' => (string)$row['seo_description'],
    ];
    if ($post !== null && is_array($post['orig'] ?? null)) {
        $orig = array_map(fn ($v): string => is_string($v) ? $v : '', $post['orig'] + $orig);
    }
    $value = fn (string $field): string => $post !== null ? admin_str($post, $field) : $orig[$field];
    $checked = fn (string $field): string => ($post !== null ? admin_str($post, $field) === '1' : $orig[$field] === '1') ? ' checked' : '';
    $covers = $post !== null ? admin_list($post, 'covers') : (json_decode($row['covers'], true) ?: []);
    $tile = $value('tileImage');
    $hidden = '';
    foreach ($orig as $field => $original) {
        $hidden .= '<input type="hidden" name="orig[' . h($field) . ']" value="' . h($original) . '">';
    }
    $text = fn (string $label, string $field, int $max): string =>
        '<label>' . h($label) . '<input name="' . h($field) . '" maxlength="' . $max . '" value="' . h($value($field)) . '"></label>';
    return '<h1>' . h($row['label']) . '</h1>'
        . '<p class="hint">Адрес раздела: <span class="address">https://pionperm.ru/' . h($row['slug']) . '/</span></p>'
        . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'section.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="' . h($row['slug']) . '">' . $hidden
        . '<label>Название<input name="label" maxlength="60" required value="' . h($value('label')) . '"></label>'
        . '<p class="hint">Оно же — подпись плитки, хлебные крошки и заголовок для поиска.</p>'
        . '<label class="check"><input type="checkbox" name="visible" value="1"' . $checked('visible') . '> Показывать в каталоге</label>'
        . '<fieldset class="form"><legend>Фото плитки</legend>'
        . admin_photo_widget($ctx['user'], 'tileImage', $tile !== '' ? [$tile] : [], 1, ['section' => $row['slug'], 'kind' => 'tile']) . '</fieldset>'
        . '<details><summary>Дополнительно</summary>'
        . $text('Заголовок обложки', 'coverTitle', 300) . $text('Подзаголовок обложки', 'coverSub', 300)
        . '<fieldset class="form"><legend>Фото обложки</legend><p class="hint">До трёх.</p>'
        . admin_photo_widget($ctx['user'], 'covers[]', $covers, CATALOG_COVERS_MAX, ['section' => $row['slug'], 'kind' => 'cover']) . '</fieldset>'
        . $text('Заголовок над сеткой', 'heading', 300) . $text('Подзаголовок над сеткой', 'headingSub', 300)
        . '<label class="check"><input type="checkbox" name="hasNotFound" value="1"' . $checked('hasNotFound') . '> Блок «Не нашли нужное?»</label>'
        . $text('SEO-заголовок', 'seoTitle', 300) . $text('SEO-описание', 'seoDescription', 300)
        . '<p class="hint">Пустые SEO-поля — сайт подставит заголовок и описание по шаблону.</p></details>'
        . '<p class="buttons"><button class="btn" type="submit">Сохранить</button></p></form>'
        . '<p class="hint">Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.</p>'
        . '<p><a href="' . ADMIN_BASE . 'sections.php">← Все разделы</a></p>';
}
