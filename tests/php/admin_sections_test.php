<?php
/**
 * Разделы: порядок плиток, новый раздел, карточка раздела, правка только
 * изменённых полей.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-sections.php';

/** Строка раздела из базы. */
function t_section(PDO $db, string $slug): array
{
    $q = $db->prepare('SELECT * FROM sections WHERE slug = ?');
    $q->execute([$slug]);
    return $q->fetch();
}

/** POST карточки раздела: поля формы как есть в базе, плюс изменения. */
function t_section_post(PDO $db, string $slug, array $changes): array
{
    $row = t_section($db, $slug);
    $form = [
        'label' => $row['label'], 'tileImage' => $row['tile_image'], 'visible' => $row['visible'] ? '1' : '0',
        'coverTitle' => $row['cover_title'], 'coverSub' => $row['cover_sub'], 'heading' => $row['heading'],
        'headingSub' => $row['heading_sub'], 'hasNotFound' => $row['has_not_found'] ? '1' : '0',
        'seoTitle' => (string)$row['seo_title'], 'seoDescription' => (string)$row['seo_description'],
        'covers' => json_encode(json_decode($row['covers'], true), CATALOG_JSON),
    ];
    $post = ['action' => 'save', 'slug' => $slug, 'orig' => $form];
    foreach ($form as $field => $value) {
        if ($field === 'covers') {
            $post['covers'] = json_decode($value, true);
        } elseif (($field === 'visible' || $field === 'hasNotFound') && $value === '0') {
            continue; // снятая галочка в форму не приходит
        } else {
            $post[$field] = $value;
        }
    }
    return array_merge($post, $changes);
}

t_case('плитки', function (): void {
    $ctx = t_admin_ctx();
    $page = t_admin_call($ctx, 'sections', 'admin_page_sections')['body'];
    // Порядок смотрим только в списке плиток: «Букеты» есть ещё и в меню шапки, а оно стоит раньше списка.
    $list = substr($page, (int)strpos($page, '<ol class="tiles">'), (int)strpos($page, '</ol>') - (int)strpos($page, '<ol class="tiles">'));
    $at = array_map(fn (string $word) => strpos($list, $word), ['Цветы', 'Букеты', 'Розы']);
    t_true(!in_array(false, $at, true) && $at[0] < $at[1] && $at[1] < $at[2], 'плитки в порядке сетки');
    t_true(str_contains($page, 'Новинки') && str_contains($page, 'скрыт'), 'скрытый раздел виден и подписан');
    t_true(str_contains($page, 'постоянная плитка'), 'постоянные плитки подписаны');
    $roses = (int)$ctx['db']->query("SELECT id FROM tiles WHERE section = 'roses'")->fetchColumn();
    $r = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'move', 'tile' => (string)$roses, 'dir' => 'up']);
    t_equal($r['headers']['Location'], '/pay/admin/sections.php', 'после перестановки — снова список');
    t_equal(array_column(catalog_export_data($ctx['db'])['tiles'], 'slug'), ['roses', 'bukety'], 'розы поднялись выше букетов');
    $first = (int)$ctx['db']->query("SELECT id FROM tiles WHERE label = 'Цветы'")->fetchColumn();
    t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'move', 'tile' => (string)$first, 'dir' => 'up']);
    t_equal(catalog_export_data($ctx['db'])['tiles'][0]['label'] ?? null, 'Цветы', 'первую плитку выше не поднять — ничего не сломалось');
});

t_case('новый раздел', function (): void {
    $ctx = t_admin_ctx();
    $r = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'create', 'label' => 'Осень']);
    t_equal($r['headers']['Location'], '/pay/admin/section.php?slug=osen&notice=section-created', 'создан — сразу в его карточку');
    t_equal(t_section($ctx['db'], 'osen')['label'], 'Осень', 'раздел в базе');
    $bad = t_admin_call($ctx, 'sections', 'admin_page_sections', 'POST', post: ['action' => 'create', 'label' => '  ']);
    t_true($bad['status'] === 422 && str_contains($bad['body'], 'Название раздела'), 'без названия — объяснение');
});

t_case('карточка раздела', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $card = t_admin_call($ctx, 'section', 'admin_page_section', query: ['slug' => 'roses'])['body'];
    t_true(str_contains($card, 'value="Розы"') && str_contains($card, 'name="orig[label]" value="Розы"'), 'поля и их исходные значения');
    t_true(str_contains($card, 'data-kind="tile"') && str_contains($card, 'data-kind="cover"') && str_contains($card, 'data-limit="3"'), 'фото плитки и обложки (до трёх)');
    t_true(str_contains($card, 'нельзя удалить'), 'объяснение, почему нет кнопки «Удалить»');

    $r = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['label' => 'Розы поштучно']));
    t_equal($r['headers']['Location'], '/pay/admin/section.php?slug=roses&notice=saved', 'сохранено');
    t_equal(t_section($db, 'roses')['label'], 'Розы поштучно', 'название сменилось');
    t_equal($db->query("SELECT field FROM audit WHERE object_type = 'section'")->fetchAll(PDO::FETCH_COLUMN), ['label'], 'в журнале — только изменённое поле');

    $post = t_section_post($db, 'roses', ['heading' => 'РОЗЫ ИЗ ЭКВАДОРА']);
    // Пока форма была открыта, коллега поменял подзаголовок обложки.
    catalog_update_section($db, 'olga', 'roses', ['coverSub' => 'Прямые поставки'], t_now());
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: $post);
    $row = t_section($db, 'roses');
    t_equal([$row['heading'], $row['cover_sub']], ['РОЗЫ ИЗ ЭКВАДОРА', 'Прямые поставки'], 'своё поле сохранено, правка коллеги не затёрта');

    $post = t_section_post($db, 'roses', []);
    unset($post['visible']);
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: $post);
    t_equal((int)t_section($db, 'roses')['visible'], 0, 'сняли галочку — раздел скрыт');

    $bad = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['label' => '']));
    t_true($bad['status'] === 422 && str_contains($bad['body'], 'Название раздела'), 'ошибка — понятная, форма на месте');
    t_equal(t_admin_call($ctx, 'section', 'admin_page_section', query: ['slug' => 'nope'])['status'], 404, 'нет такого раздела');
});

t_case('фото в карточке раздела', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    catalog_update_section($db, 'olga', 'roses', ['tileImage' => '/images/site/catalog-tiles/tile-0.webp', 'covers' => ['/images/site/category-covers/a.webp']], t_now());
    $card = t_admin_call($ctx, 'section', 'admin_page_section', query: ['slug' => 'roses'])['body'];
    t_true(str_contains($card, 'name="tileImage" value="/images/site/catalog-tiles/tile-0.webp"')
        && str_contains($card, 'name="covers[]" value="/images/site/category-covers/a.webp"'), 'фото плитки и обложки уже в форме');

    // Новые обложки: форма присылает их по порядку, как расставил сотрудник.
    $covers = ['/images/catalog/_sections/roses-cover-bbbbbbbb.webp', '/images/site/category-covers/a.webp'];
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['covers' => $covers]));
    t_equal(json_decode(t_section($db, 'roses')['covers'], true), $covers, 'обложки — в новом порядке');
    t_equal((string)t_section($db, 'roses')['tile_image'], '/images/site/catalog-tiles/tile-0.webp', 'плитка не тронута');

    // Убрали фото плитки: поля tileImage в форме просто нет — это значит «пусто».
    $post = t_section_post($db, 'roses', []);
    unset($post['tileImage']);
    t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: $post);
    t_equal(t_section($db, 'roses')['tile_image'], '', 'фото плитки убрано');
    t_equal(json_decode(t_section($db, 'roses')['covers'], true), $covers, 'обложки при этом остались');

    // Ошибка: форма возвращается с тем, что ввела сотрудница, и с прежними исходными значениями.
    $long = str_repeat('я', 301);
    $bad = t_admin_call($ctx, 'section', 'admin_page_section', 'POST', post: t_section_post($db, 'roses', ['heading' => $long]));
    t_true($bad['status'] === 422 && str_contains($bad['body'], 'длиннее 300') && str_contains($bad['body'], 'value="' . $long . '"')
        && str_contains($bad['body'], 'name="orig[heading]" value="РОЗЫ"'), 'ошибка: введённое осталось, исходное — прежнее');
});
