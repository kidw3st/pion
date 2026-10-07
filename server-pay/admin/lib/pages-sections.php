<?php
/**
 * Разделы: порядок плиток в каталоге, новый раздел, карточка раздела.
 * Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/../../catalog/sections.php';

/**
 * Поля карточки раздела, у каждого в форме рядом лежит исходное значение (orig).
 * Других ключей в orig форма не принимает: подделанный запрос не должен ломать страницу.
 */
const ADMIN_SECTION_FIELDS = [
    'label', 'tileImage', 'visible', 'coverTitle', 'coverSub', 'covers', 'heading', 'headingSub',
    'hasNotFound', 'seoTitle', 'seoDescription',
];
/**
 * Многострочные поля: на сайте переносы показываются как есть (white-space: pre-line).
 * Форма держит их в textarea; браузер присылает перенос как \r\n, в базе он хранится как \n.
 */
const ADMIN_SECTION_TEXTAREAS = ['coverTitle', 'coverSub', 'heading', 'headingSub'];
/** Однострочные поля (input): браузер выбрасывает из значения переводы строк, даже если в базе они были. */
const ADMIN_SECTION_LINES = ['label', 'tileImage', 'seoTitle', 'seoDescription'];

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
                // Только «вверх» и «вниз»: любое другое значение — подделанный запрос, плитки не двигаем.
                $step = ['up' => -1, 'down' => 1][admin_str($post, 'dir')] ?? 0;
                $j = $i === false ? -1 : $i + $step;
                if ($step !== 0 && $i !== false && isset($ids[$j])) {
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

/** Переводы строк \r\n и \r — в \n, как они хранятся в базе. */
function admin_section_lf(string $text): string
{
    return str_replace(["\r\n", "\r"], "\n", $text);
}

/**
 * Поля карточки, которые сотрудник поменял: сравниваются с исходными
 * значениями из формы (orig). Нет исходного значения — поле считается изменённым.
 * Перенос строки сам по себе правкой не считается: иначе простое «Сохранить»
 * склеивало бы строки подзаголовка и писало в журнал правку, которой не было.
 */
function admin_section_changes(array $post): array
{
    $orig = is_array($post['orig'] ?? null) ? $post['orig'] : [];
    try {
        $covers = json_encode(admin_list($post, 'covers'), CATALOG_JSON);
    } catch (JsonException) {
        // Путь с недопустимыми байтами сам не появится: форму подделали или она сломалась по дороге.
        throw new CatalogError('Не получилось сохранить фото обложки — обновите страницу и попробуйте снова.');
    }
    $now = [
        'label' => admin_str($post, 'label'),
        'tileImage' => admin_str($post, 'tileImage'),
        'visible' => admin_str($post, 'visible') === '1' ? '1' : '0',
        'coverTitle' => admin_section_lf(admin_str($post, 'coverTitle')),
        'coverSub' => admin_section_lf(admin_str($post, 'coverSub')),
        'covers' => $covers,
        'heading' => admin_section_lf(admin_str($post, 'heading')),
        'headingSub' => admin_section_lf(admin_str($post, 'headingSub')),
        'hasNotFound' => admin_str($post, 'hasNotFound') === '1' ? '1' : '0',
        'seoTitle' => admin_str($post, 'seoTitle'),
        'seoDescription' => admin_str($post, 'seoDescription'),
    ];
    $changes = [];
    foreach ($now as $field => $value) {
        $was = $orig[$field] ?? null;
        if (is_string($was)) {
            if (in_array($field, ADMIN_SECTION_TEXTAREAS, true)) {
                $was = admin_section_lf($was);
            } elseif (in_array($field, ADMIN_SECTION_LINES, true) && str_replace(["\r", "\n"], '', $was) === $value) {
                // Однострочное поле пришло без переносов, которые были в исходном значении, — это то же значение.
                $was = $value;
            }
        }
        if (!is_string($was) || $was !== $value) {
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
    // Закрываем чтение до записи ниже: открытое, оно отменяет ожидание записи, и при занятой базе
    // сохранение падало бы сразу («database is locked»), не дожидаясь busy_timeout.
    $q->closeCursor();
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
    // Исходные значения из присланной формы — только по известным полям: чужие ключи (подделанный запрос)
    // не должны ни попасть в страницу, ни сломать её.
    $sent = $post !== null && is_array($post['orig'] ?? null) ? $post['orig'] : [];
    foreach (ADMIN_SECTION_FIELDS as $field) {
        if (array_key_exists($field, $sent)) {
            $orig[$field] = is_string($sent[$field]) ? $sent[$field] : '';
        }
    }
    $value = fn (string $field): string => $post !== null ? admin_str($post, $field) : $orig[$field];
    $checked = fn (string $field): string => ($post !== null ? admin_str($post, $field) === '1' : $orig[$field] === '1') ? ' checked' : '';
    $covers = $post !== null ? admin_list($post, 'covers') : (json_decode($row['covers'], true) ?: []);
    $tile = $value('tileImage');
    $hidden = '';
    foreach (ADMIN_SECTION_FIELDS as $field) {
        $hidden .= '<input type="hidden" name="orig[' . $field . ']" value="' . h($orig[$field]) . '">';
    }
    $text = fn (string $label, string $field, int $max): string =>
        '<label>' . h($label) . '<input name="' . h($field) . '" maxlength="' . $max . '" value="' . h($value($field)) . '"></label>';
    // Заголовки и подзаголовки бывают в две строки (перенос виден на сайте), а в однострочном поле он пропал бы.
    $area = fn (string $label, string $field, int $max): string =>
        '<label>' . h($label) . '<textarea class="short" name="' . h($field) . '" rows="2" maxlength="' . $max . '">'
        . h($value($field)) . '</textarea></label>';
    return '<h1>' . h($row['label']) . '</h1>'
        . '<p class="hint">Адрес раздела: <span class="address">https://pionperm.ru/' . h($row['slug']) . '/</span></p>'
        . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'section.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="' . h($row['slug']) . '">' . $hidden
        . '<label>Название<input name="label" maxlength="60" required value="' . h($value('label')) . '"></label>'
        . '<p class="hint">Оно же — подпись плитки и название раздела на сайте.</p>'
        . '<label class="check"><input type="checkbox" name="visible" value="1"' . $checked('visible') . '> Показывать в каталоге</label>'
        . '<fieldset class="form"><legend>Фото плитки</legend>'
        . admin_photo_widget($ctx['user'], 'tileImage', $tile !== '' ? [$tile] : [], 1, ['section' => $row['slug'], 'kind' => 'tile']) . '</fieldset>'
        // После ошибки раскрыто: текст ошибки не называет поле, а почти все поля лежат здесь.
        . '<details' . ($error !== '' ? ' open' : '') . '><summary>Дополнительно</summary>'
        . $area('Заголовок обложки', 'coverTitle', 300) . $area('Подзаголовок обложки', 'coverSub', 300)
        . '<fieldset class="form"><legend>Фото обложки</legend><p class="hint">До трёх.</p>'
        . admin_photo_widget($ctx['user'], 'covers[]', $covers, CATALOG_COVERS_MAX, ['section' => $row['slug'], 'kind' => 'cover']) . '</fieldset>'
        . $area('Заголовок над сеткой', 'heading', 300) . $area('Подзаголовок над сеткой', 'headingSub', 300)
        . '<label class="check"><input type="checkbox" name="hasNotFound" value="1"' . $checked('hasNotFound') . '> Блок «Не нашли нужное?»</label>'
        . $text('Заголовок для поиска', 'seoTitle', 300) . $text('Описание для поиска', 'seoDescription', 300)
        . '<p class="hint">Если оставить оба поля пустыми, сайт подставит заголовок и описание сам.</p></details>'
        . '<p class="buttons"><button class="btn" type="submit">Сохранить</button></p></form>'
        . '<p class="hint">Раздел нельзя удалить — только скрыть: у его страницы копятся позиции в поиске.</p>'
        . '<p><a class="back" href="' . ADMIN_BASE . 'sections.php">← Все разделы</a></p>';
}
