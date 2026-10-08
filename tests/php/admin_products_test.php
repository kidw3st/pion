<?php
/**
 * Список букетов: поиск, фильтры по разделу и статусу, экранирование.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_fixture.php';
require_once __DIR__ . '/../../server-pay/admin/lib/pages-products.php';

/** Названия букетов на странице списка, по алфавиту (порядок в списке зависит от времени правки). */
function t_list_titles(string $html): array
{
    preg_match_all('~<span class="item-title">(.*?)</span>~u', $html, $m);
    $titles = array_map(fn (string $t): string => html_entity_decode($t, ENT_QUOTES, 'UTF-8'), $m[1]);
    sort($titles);
    return $titles;
}

t_case('список и фильтры', function (): void {
    $ctx = t_admin_ctx();
    $db = $ctx['db'];
    $a = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет А', 'images' => ['/images/catalog/bukety/a.webp']]), t_now());
    catalog_publish($db, 'anna', $a, 1, t_now());
    catalog_create_product($db, 'anna', t_fields(['title' => 'Букет Б', 'sections' => ['roses']]), t_now());
    $rose = catalog_create_product($db, 'anna', t_fields(['title' => 'Роза Эквадор', 'sections' => ['roses']]), t_now());
    catalog_publish($db, 'anna', $rose, 1, t_now());
    catalog_hide($db, 'anna', $rose, 2, t_now());
    $gone = catalog_create_product($db, 'anna', t_fields(['title' => 'Букет В']), t_now());
    catalog_publish($db, 'anna', $gone, 1, t_now());
    catalog_delete($db, 'anna', $gone, 2, t_now());

    $titles = fn (array $query): array => t_list_titles(t_admin_call($ctx, 'products', 'admin_page_products', query: $query)['body']);
    t_equal($titles([]), ['Букет А', 'Букет Б', 'Роза Эквадор'], 'по умолчанию — все, кроме удалённых');
    t_equal($titles(['status' => 'deleted']), ['Букет В'], 'удалённые — отдельным фильтром');
    t_equal($titles(['status' => 'draft']), ['Букет Б'], 'черновики');
    t_equal($titles(['q' => 'роза']), ['Роза Эквадор'], 'поиск без учёта регистра — и по-русски');
    t_equal($titles(['section' => 'roses']), ['Букет Б', 'Роза Эквадор'], 'фильтр по разделу');

    $page = t_admin_call($ctx, 'products', 'admin_page_products')['body'];
    t_true(str_contains($page, 'src="/images/catalog/bukety/a.webp"') && str_contains($page, 'loading="lazy"'), 'превью — первое фото, грузится по мере прокрутки');
    t_true(str_contains($page, '<img src="/images/catalog/bukety/a.webp" alt="" width="56" height="56" loading="lazy" decoding="async">'), 'у превью задан размер — страница не прыгает, пока грузятся фото');
    t_true(str_contains($page, 'href="/pay/admin/product.php?new=1"'), 'кнопка «Добавить букет»');
    t_true(str_contains($page, 'href="/pay/admin/product.php?uid=' . $a . '"'), 'букет открывается в карточке');
    t_true(str_contains($page, 'Найдено: 3'), 'сколько найдено');
});

t_case('экранирование и сообщения', function (): void {
    $ctx = t_admin_ctx();
    catalog_create_product($ctx['db'], 'anna', t_fields(['title' => '<b>жирный</b>']), t_now());
    $page = t_admin_call($ctx, 'products', 'admin_page_products')['body'];
    t_true(str_contains($page, '&lt;b&gt;жирный&lt;/b&gt;') && !str_contains($page, '<b>жирный</b>'), 'название экранировано');
    $search = t_admin_call($ctx, 'products', 'admin_page_products', query: ['q' => '"><script>'])['body'];
    t_true(!str_contains($search, '"><script>') && str_contains($search, 'value="&quot;&gt;&lt;script&gt;"'), 'строка поиска экранирована');
    t_true(str_contains(t_admin_call($ctx, 'products', 'admin_page_products', query: ['notice' => 'saved'])['body'], '<p class="notice">Сохранено.</p>'), 'сообщение после действия');
});

t_case('список букетов — по 60, с «Показать ещё»', function (): void {
    $ctx = t_admin_ctx();
    for ($i = 1; $i <= 65; $i++) {
        catalog_create_product($ctx['db'], 'anna', t_fields(['title' => "Букет «Номер {$i}»"]), t_now("+$i seconds"));
    }
    $page = t_admin_call($ctx, 'products', 'admin_page_products', query: ['status' => 'draft'])['body'];
    t_equal(substr_count($page, 'product.php?uid='), 60, 'первые 60');
    t_true(str_contains($page, 'Найдено: 65'), 'всего найдено — 65');
    t_true(str_contains($page, 'shown=120') && str_contains($page, 'status=draft') && str_contains($page, 'Показать ещё'), 'ссылка «Показать ещё» с теми же фильтрами');
    $more = t_admin_call($ctx, 'products', 'admin_page_products', query: ['status' => 'draft', 'shown' => '120'])['body'];
    t_equal(substr_count($more, 'product.php?uid='), 65, 'по ссылке — все 65');
    t_true(!str_contains($more, 'Показать ещё'), 'больше показывать нечего');
});
