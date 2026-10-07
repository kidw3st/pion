<?php
/**
 * Букеты: список с поиском и фильтрами, карточка, действия со статусом.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/../../catalog/products.php';
require_once __DIR__ . '/forms.php';

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

function admin_page_product(array $req, array $ctx): array
{
    if ($req['method'] === 'POST') {
        $action = admin_str($req['post'], 'action');
        if ($action === 'create') {
            return admin_product_create($req, $ctx);
        }
        if ($action === 'save' || $action === 'publish') {
            return admin_product_save($req, $ctx, $action);
        }
        return admin_product_action($req, $ctx, $action);
    }
    if (admin_str($req['query'], 'new') !== '') {
        return admin_html(admin_layout('Новый букет', admin_new_form($ctx, [], '', null), $ctx['user'], $ctx['status']));
    }
    $p = admin_find_product($ctx['db'], admin_str($req['query'], 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    return admin_html(admin_layout($p['title'], admin_product_form($ctx, $p, null, '', '') . admin_product_extras($ctx, $p),
        $ctx['user'], $ctx['status'], admin_notice($req)));
}

function admin_find_product(PDO $db, string $uid): ?array
{
    try {
        return catalog_product_row($db, $uid);
    } catch (CatalogError) {
        return null;
    }
}

/** Галочки разделов и выбор главного: по главному строится адрес страницы. */
function admin_sections_picker(array $sections, array $checked, string $main): string
{
    $rows = '';
    foreach ($sections as $s) {
        $slug = $s['slug'];
        $rows .= '<li><label class="check"><input type="checkbox" name="sections[]" value="' . h($slug) . '"'
            . (in_array($slug, $checked, true) ? ' checked' : '') . '> ' . h($s['label'])
            . ($s['visible'] ? '' : ' <span class="hint">(скрыт)</span>') . '</label>'
            . '<label class="check"><input type="radio" name="mainSection" value="' . h($slug) . '"'
            . ($slug === $main ? ' checked' : '') . '> главный</label></li>';
    }
    return '<fieldset class="form"><legend>Разделы</legend>'
        . '<p class="hint">Можно несколько. Главный — по нему строится адрес страницы. Если главный не выбран, у нового букета им станет первый отмеченный (кроме «Новинок»), у сохранённого — останется прежний.</p>'
        . '<ul class="sections-pick">' . $rows . '</ul></fieldset>';
}

/** Первый шаг нового букета. $twin — снятый или удалённый тёзка: его предлагают вернуть. */
function admin_new_form(array $ctx, array $post, string $error, ?array $twin): string
{
    $twinBox = '';
    if ($twin !== null) {
        $what = $twin['status'] === 'hidden' ? 'снят с продажи' : 'удалён';
        $twinBox = '<div class="ask"><p>Такой букет уже был — ' . $what . ' ' . h(admin_day($twin['since'])) . '. Вернуть его?</p>'
            . '<p class="buttons"><a class="btn" href="' . ADMIN_BASE . 'product.php?uid=' . h($twin['uid']) . '">Открыть прежний букет</a></p>'
            . '<p class="hint">Если это другой букет — нажмите «Создать черновик» ещё раз.</p></div>';
    }
    return '<h1>Новый букет</h1>' . $twinBox . admin_error($error)
        . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="action" value="create">'
        . ($twin !== null ? '<input type="hidden" name="confirm_new" value="' . h(catalog_title_key(admin_str($post, 'title'))) . '">' : '')
        . '<label>Название<input name="title" maxlength="120" required value="' . h(admin_str($post, 'title')) . '"></label>'
        . '<label>Цена, ₽<input name="price" inputmode="numeric" required value="' . h(admin_str($post, 'price')) . '"></label>'
        . '<label>Состав<textarea name="description" maxlength="1000">' . h(admin_str($post, 'description')) . '</textarea></label>'
        . admin_sections_picker(admin_sections($ctx['db']), admin_list($post, 'sections'), admin_str($post, 'mainSection'))
        . '<p class="hint">Фото добавите на следующем шаге. Черновика на сайте нет, пока вы его не опубликуете.</p>'
        . '<p class="buttons"><button class="btn" type="submit">Создать черновик</button></p></form>';
}

/** Фото букета в форме: превью и скрытые поля, чтобы сохранение их не потеряло. */
function admin_photo_list(array $images): string
{
    $items = '';
    foreach ($images as $path) {
        $items .= '<li><img src="' . h($path) . '" alt=""><input type="hidden" name="images[]" value="' . h($path) . '"></li>';
    }
    return '<div class="photos"><ul>' . $items . '</ul></div>';
}

/**
 * Карточка букета. $post — что прислала форма (показать снова после ошибки
 * или вопроса о цене), null — значения из базы. $ask — вопрос перед
 * сохранением (цена изменилась больше чем вдвое). $askPrice — привязка
 * подтверждения к конкретной цене: если изменили цену в форме, нужно заново.
 */
function admin_product_form(array $ctx, array $p, ?array $post, string $error, string $ask, int $askPrice = 0): string
{
    $db = $ctx['db'];
    $images = $post !== null ? admin_list($post, 'images') : (json_decode($p['images'], true) ?: []);
    $checked = $post !== null ? admin_list($post, 'sections') : catalog_product_sections($db, $p['uid']);
    $main = $post !== null ? admin_str($post, 'mainSection') : $p['main_section'];
    $value = fn (string $field): string => $post !== null ? admin_str($post, $field) : (string)$p[$field];
    $address = 'https://pionperm.ru/' . $p['main_section'] . '/' . $p['slug'] . '/';
    $html = '<h1>' . h($p['title']) . ' <span class="badge badge-' . h($p['status']) . '">' . h(ADMIN_STATUS_LABELS[$p['status']]) . '</span></h1>'
        . ($p['status'] === 'draft'
            ? '<p class="hint">Адрес появится после публикации: <span class="address">' . h($address) . '</span></p>'
            : '<p class="hint">Адрес страницы: <span class="address">' . h($address) . '</span></p>')
        . admin_error($error)
        . ($ask !== '' ? '<div class="ask"><p>' . h($ask) . '</p>'
            . '<p class="hint">Если всё верно — нажмите ту же кнопку ещё раз. Если нет — исправьте цену.</p></div>' : '');
    if ($p['status'] === 'deleted') {
        return $html . '<p>Букет удалён ' . h(admin_date((string)$p['deleted_at']))
            . '. Его нет на сайте; со старого адреса — переадресация в раздел.</p>' . admin_photo_list($images);
    }
    $buttons = $p['status'] === 'draft'
        ? '<button class="btn-quiet" type="submit" name="action" value="save">Сохранить черновик</button>'
            . '<button class="btn" type="submit" name="action" value="publish">Опубликовать</button>'
        : '<button class="btn" type="submit" name="action" value="save">Сохранить</button>';
    return $html . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="form">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
        . '<input type="hidden" name="version" value="' . h($post !== null ? admin_str($post, 'version') : (string)$p['version']) . '">'
        . ($ask !== '' ? '<input type="hidden" name="confirm_price" value="' . h((string)$askPrice) . '">' : '')
        . '<label>Название<input name="title" maxlength="120" required value="' . h($value('title')) . '"></label>'
        . '<label>Цена, ₽<input name="price" inputmode="numeric" required value="' . h($value('price')) . '"></label>'
        . '<label>Состав<textarea name="description" maxlength="1000">' . h($value('description')) . '</textarea></label>'
        . '<fieldset class="form"><legend>Фото</legend><p class="hint">До четырёх. Первое — главное.</p>' . admin_photo_list($images) . '</fieldset>'
        . admin_sections_picker(admin_sections($db), $checked, $main)
        . '<p class="buttons">' . $buttons . '</p></form>';
}

function admin_product_create(array $req, array $ctx): array
{
    $post = $req['post'];
    $show = fn (string $error, ?array $twin, int $status): array => admin_html(
        admin_layout('Новый букет', admin_new_form($ctx, $post, $error, $twin), $ctx['user'], $ctx['status']), $status);
    try {
        $fields = admin_product_fields($post);
        // Сначала сухая проверка: поля и цена должны быть валидны, иначе не спрашиваем о тёзке.
        catalog_product_fields($ctx['db'], $fields);
        $confirmKey = catalog_title_key($fields['title']);
        if (admin_str($post, 'confirm_new') !== $confirmKey) {
            $twin = catalog_find_namesake($ctx['db'], $fields['title'], $ctx['now']);
            if ($twin !== null) {
                return $show('', $twin, 200);
            }
        }
        $uid = catalog_create_product($ctx['db'], $ctx['user']['login'], $fields, $ctx['now']);
        return admin_redirect('product.php', ['uid' => $uid, 'notice' => 'created']);
    } catch (CatalogError $e) {
        return $show($e->getMessage(), null, 422);
    }
}

function admin_product_save(array $req, array $ctx, string $action): array
{
    $post = $req['post'];
    $p = admin_find_product($ctx['db'], admin_str($post, 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    $show = fn (string $error, string $ask, int $askPrice, int $status): array => admin_html(
        admin_layout($p['title'], admin_product_form($ctx, $p, $post, $error, $ask, $askPrice), $ctx['user'], $ctx['status']), $status);
    try {
        $fields = admin_product_fields($post);
        $version = (int)admin_str($post, 'version');
        // Проверяем версию без записи — если чужие правки, то вопроса о цене не нужно.
        catalog_product_for_change($ctx['db'], $p['uid'], $version);
        // Сухая проверка полей — если цена невалидна или проблемы другие, вопроса не спрашиваем.
        catalog_product_fields($ctx['db'], $fields, $p['main_section']);
        // Теперь проверяем цену: привязываем подтверждение к конкретной сумме.
        $confirmPrice = (int)admin_str($post, 'confirm_price');
        if ($confirmPrice !== $fields['price'] && admin_price_jump((int)$p['price'], $fields['price'])) {
            return $show('', 'Цена была ' . admin_rub((int)$p['price']) . ', станет ' . admin_rub($fields['price']) . '. Всё верно?', $fields['price'], 200);
        }
        catalog_update_product($ctx['db'], $ctx['user']['login'], $p['uid'], $version, $fields, $ctx['now']);
        if ($action === 'publish') {
            // «Опубликовать» из карточки черновика: сначала правки, потом публикация — версия уже на один больше.
            catalog_publish($ctx['db'], $ctx['user']['login'], $p['uid'], $version + 1, $ctx['now']);
            return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'published']);
        }
        return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => 'saved']);
    } catch (CatalogConflict $e) {
        $html = admin_product_form($ctx, $p, $post, $e->getMessage(), '', 0)
            . '<p><a class="btn-quiet" href="' . ADMIN_BASE . 'product.php?uid=' . h($p['uid']) . '">Открыть букет заново</a></p>';
        return admin_html(admin_layout($p['title'], $html, $ctx['user'], $ctx['status']), 409);
    } catch (CatalogError $e) {
        return $show($e->getMessage(), '', 0, 422);
    }
}

const ADMIN_ACTION_NOTICES = ['hide' => 'hidden', 'unhide' => 'unhidden', 'delete' => 'deleted', 'restore' => 'restored'];

/** Снять, вернуть, удалить, восстановить. Снять и удалить — только после подтверждения. */
function admin_product_action(array $req, array $ctx, string $action): array
{
    $post = $req['post'];
    $p = admin_find_product($ctx['db'], admin_str($post, 'uid'));
    if ($p === null) {
        return admin_not_found($ctx);
    }
    if (!isset(ADMIN_ACTION_NOTICES[$action])) {
        return admin_html(admin_layout('Ошибка', admin_error('Неизвестное действие — обновите страницу.'), $ctx['user'], $ctx['status']), 400);
    }
    $version = (int)admin_str($post, 'version');
    if (in_array($action, ['hide', 'delete'], true) && admin_str($post, 'confirm') !== '1') {
        return admin_html(admin_layout($p['title'], admin_confirm_page($ctx, $p, $action, $version), $ctx['user'], $ctx['status']));
    }
    $db = $ctx['db'];
    $login = $ctx['user']['login'];
    try {
        match ($action) {
            'hide' => catalog_hide($db, $login, $p['uid'], $version, $ctx['now']),
            'unhide' => catalog_unhide($db, $login, $p['uid'], $version, $ctx['now']),
            'delete' => catalog_delete($db, $login, $p['uid'], $version, $ctx['now']),
            'restore' => catalog_restore($db, $login, $p['uid'], $version, $ctx['now']),
        };
    } catch (CatalogConflict|CatalogError $e) {
        $fresh = admin_find_product($db, $p['uid']) ?? $p;
        $html = admin_product_form($ctx, $fresh, null, $e->getMessage(), '') . admin_product_extras($ctx, $fresh);
        return admin_html(admin_layout($fresh['title'], $html, $ctx['user'], $ctx['status']), $e instanceof CatalogConflict ? 409 : 422);
    }
    if ($action === 'delete' && $p['status'] === 'draft') {
        return admin_redirect('', ['notice' => 'removed']);
    }
    return admin_redirect('product.php', ['uid' => $p['uid'], 'notice' => ADMIN_ACTION_NOTICES[$action]]);
}

function admin_confirm_page(array $ctx, array $p, string $action, int $version): string
{
    $name = '«' . $p['title'] . '»';
    [$question, $explain, $button] = match (true) {
        $action === 'hide' => [
            'Снять ' . $name . ' с продажи?',
            'Страница останется на сайте с пометкой «Сейчас нет в продаже», заказать букет будет нельзя. Вернуть его в продажу можно в любой момент — на прежнее место.',
            'Снять с продажи',
        ],
        $p['status'] === 'draft' => ['Удалить черновик ' . $name . '?', 'Он исчезнет совсем — на сайте его не было.', 'Удалить черновик'],
        default => [
            'Удалить ' . $name . '?',
            'Страница исчезнет с сайта, с её адреса будет переадресация в раздел. Восстановить букет можно в течение 90 дней.',
            'Удалить',
        ],
    };
    return '<h1>' . h($question) . '</h1><p>' . h($explain) . '</p>'
        . '<form method="post" action="' . ADMIN_BASE . 'product.php" class="buttons">' . admin_csrf_field($ctx['user'])
        . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
        . '<input type="hidden" name="version" value="' . $version . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<button class="btn-danger" type="submit">' . h($button) . '</button>'
        . '<a class="btn-quiet" href="' . ADMIN_BASE . 'product.php?uid=' . h($p['uid']) . '">Отмена</a></form>';
}

/** Блок «Действия» под карточкой — отдельные формы: правки в карточке они не сохраняют. */
function admin_product_extras(array $ctx, array $p): string
{
    if ($p['status'] === 'deleted' && (string)$p['deleted_at'] < catalog_iso($ctx['now']->modify('-' . CATALOG_RESTORE_DAYS . ' days'))) {
        return '<h2>Действия</h2><p>Букет удалён больше 90 дней назад — восстановить его уже нельзя.</p>';
    }
    $actions = match ($p['status']) {
        'draft' => ['delete' => ['Удалить черновик', 'btn-danger']],
        'active' => ['hide' => ['Снять с продажи', 'btn-quiet'], 'delete' => ['Удалить', 'btn-danger']],
        'hidden' => ['unhide' => ['Вернуть в продажу', 'btn'], 'delete' => ['Удалить', 'btn-danger']],
        default => ['restore' => ['Восстановить', 'btn']],
    };
    $forms = '';
    foreach ($actions as $action => [$label, $class]) {
        $forms .= '<form method="post" action="' . ADMIN_BASE . 'product.php">' . admin_csrf_field($ctx['user'])
            . '<input type="hidden" name="uid" value="' . h($p['uid']) . '">'
            . '<input type="hidden" name="version" value="' . (int)$p['version'] . '">'
            . '<button class="' . $class . '" type="submit" name="action" value="' . h($action) . '">' . h($label) . '</button></form>';
    }
    return '<h2>Действия</h2>'
        . ($p['status'] !== 'deleted' ? '<p class="hint">Несохранённые правки в карточке выше при этом не сохранятся.</p>' : '')
        . '<div class="buttons">' . $forms . '</div>';
}
