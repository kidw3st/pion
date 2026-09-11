<?php
/**
 * Тема блога салона «Пион».
 *
 * Шапка и подвал повторяют основной сайт pionperm.ru. Чтобы меню, телефон и
 * реквизиты не разъезжались с сайтом, они не вписаны в шаблоны руками, а
 * берутся из site-data.php — его генерирует scripts/build-blog-theme.mjs из
 * того же data/site.json, по которому собирается сам сайт.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PION_SITE_URL', 'https://pionperm.ru');

/**
 * Данные сайта: меню, контакты, соцсети, колонки подвала.
 * Если файла нет (тему скопировали руками) — отдаём минимум, чтобы блог
 * остался рабочим, а не упал с фатальной ошибкой.
 */
function pion_site_data(): array
{
    static $data = null;

    if ($data === null) {
        $file = get_template_directory() . '/site-data.php';
        $data = is_readable($file) ? require $file : [];

        $data += [
            'nav'     => [],
            'phone'   => '',
            'address' => '',
            'social'  => [],
            'footer'  => ['columns' => [], 'legal' => '', 'hours' => ''],
        ];
    }

    return $data;
}

/**
 * Адрес раздела основного сайта. В site.json пути записаны без хвостового
 * слэша, а сайт отдаёт страницы со слэшем — приводим к одному виду, чтобы не
 * ловить лишний редирект на каждой ссылке.
 */
function pion_site_link(string $path): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }

    $path = '/' . ltrim($path, '/');

    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }

    return PION_SITE_URL . $path;
}

/** Телефон в виде, пригодном для tel:. */
function pion_tel(string $phone): string
{
    return preg_replace('/[^+\d]/', '', $phone) ?? '';
}

/**
 * Иконки соцсетей — те же круглые глифы, что и в шапке основного сайта
 * (100x100, вырез по fill-rule evenodd).
 */
function pion_social_icon(string $href): string
{
    $open  = '<svg role="presentation" width="30" height="30" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="';
    $close = '"/></svg>';

    $paths = [
        'vk.com' => 'M50 100c27.614 0 50-22.386 50-50S77.614 0 50 0 0 22.386 0 50s22.386 50 50 50ZM25 34c.406 19.488 10.15 31.2 27.233 31.2h.968V54.05c6.278.625 11.024 5.216 12.93 11.15H75c-2.436-8.87-8.838-13.773-12.836-15.647C66.162 47.242 71.783 41.62 73.126 34h-8.058c-1.749 6.184-6.932 11.805-11.867 12.336V34h-8.057v21.611C40.147 54.362 33.838 48.304 33.556 34H25Z',
        't.me'   => 'M50 100c27.614 0 50-22.386 50-50S77.614 0 50 0 0 22.386 0 50s22.386 50 50 50Zm21.977-68.056c.386-4.38-4.24-2.576-4.24-2.576-3.415 1.414-6.937 2.85-10.497 4.302-11.04 4.503-22.444 9.155-32.159 13.734-5.268 1.932-2.184 3.864-2.184 3.864l8.351 2.577c3.855 1.16 5.91-.129 5.91-.129l17.988-12.238c6.424-4.38 4.882-.773 3.34.773l-13.49 12.882c-2.056 1.804-1.028 3.35-.129 4.123 2.55 2.249 8.82 6.364 11.557 8.16.712.467 1.185.778 1.292.858.642.515 4.111 2.834 6.424 2.319 2.313-.516 2.57-3.479 2.57-3.479l3.083-20.226c.462-3.511.993-6.886 1.417-9.582.4-2.546.705-4.485.767-5.362Z',
        'wa.me'  => 'M50 100C77.6142 100 100 77.6142 100 50C100 22.3858 77.6142 0 50 0C22.3858 0 0 22.3858 0 50C0 77.6142 22.3858 100 50 100ZM69.7626 28.9928C64.6172 23.841 57.7739 21.0027 50.4832 21C35.4616 21 23.2346 33.2252 23.2292 48.2522C23.2274 53.0557 24.4823 57.7446 26.8668 61.8769L23 76L37.4477 72.2105C41.4282 74.3822 45.9107 75.5262 50.4714 75.528H50.4823C65.5029 75.528 77.7299 63.301 77.7363 48.2749C77.7408 40.9915 74.9089 34.1446 69.7626 28.9928ZM62.9086 53.9588C62.2274 53.6178 58.8799 51.9708 58.2551 51.7435C57.6313 51.5161 57.1766 51.4024 56.7228 52.0845C56.269 52.7666 54.964 54.2998 54.5666 54.7545C54.1692 55.2092 53.7718 55.2656 53.0915 54.9246C52.9802 54.8688 52.8283 54.803 52.6409 54.7217C51.6819 54.3057 49.7905 53.4855 47.6151 51.5443C45.5907 49.7382 44.2239 47.5084 43.8265 46.8272C43.4291 46.1452 43.7837 45.7769 44.1248 45.4376C44.3292 45.2338 44.564 44.9478 44.7987 44.662C44.9157 44.5194 45.0328 44.3768 45.146 44.2445C45.4345 43.9075 45.56 43.6516 45.7302 43.3049C45.7607 43.2427 45.7926 43.1776 45.8272 43.1087C46.0545 42.654 45.9409 42.2565 45.7708 41.9155C45.6572 41.6877 45.0118 40.1167 44.4265 38.6923C44.1355 37.984 43.8594 37.3119 43.671 36.8592C43.1828 35.687 42.6883 35.69 42.2913 35.6924C42.2386 35.6928 42.1876 35.6931 42.1386 35.6906C41.7421 35.6706 41.2874 35.667 40.8336 35.667C40.3798 35.667 39.6423 35.837 39.0175 36.5191C38.9773 36.5631 38.9323 36.6111 38.8834 36.6633C38.1738 37.4209 36.634 39.0648 36.634 42.2002C36.634 45.544 39.062 48.7748 39.4124 49.2411L39.415 49.2444C39.4371 49.274 39.4767 49.3309 39.5333 49.4121C40.3462 50.5782 44.6615 56.7691 51.0481 59.5271C52.6732 60.2291 53.9409 60.6475 54.9303 60.9612C56.5618 61.4796 58.046 61.4068 59.22 61.2313C60.5286 61.0358 63.2487 59.5844 63.8161 57.9938C64.3836 56.4033 64.3836 55.0392 64.2136 54.7554C64.0764 54.5258 63.7545 54.3701 63.2776 54.1395C63.1633 54.0843 63.0401 54.0247 62.9086 53.9588Z',
    ];

    foreach ($paths as $needle => $d) {
        if (str_contains($href, $needle)) {
            return $open . $d . $close;
        }
    }

    return '';
}

function pion_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('responsive-embeds');
    add_theme_support('html5', [
        'search-form',
        'gallery',
        'caption',
        'style',
        'script',
    ]);

    // Карточки в ленте — 3:2, поэтому нужен свой размер: WordPress по
    // умолчанию режет только квадрат 150x150 и «средний» 300x300.
    add_image_size('pion-card', 760, 507, true);
    add_image_size('pion-cover', 1200, 800, true);
}
add_action('after_setup_theme', 'pion_setup');

function pion_assets(): void
{
    $dir = get_template_directory();

    // Montserrat лежит в теме теми же файлами, что и на основном сайте, —
    // так шрифт совпадает точно и не зависит от внешних сервисов.
    if (is_readable($dir . '/fonts/fonts.css')) {
        wp_enqueue_style(
            'pion-fonts',
            get_template_directory_uri() . '/fonts/fonts.css',
            [],
            (string) filemtime($dir . '/fonts/fonts.css')
        );
    }

    wp_enqueue_style(
        'pion-style',
        get_stylesheet_uri(),
        ['pion-fonts'],
        (string) filemtime($dir . '/style.css')
    );

    wp_enqueue_script(
        'pion-nav',
        get_template_directory_uri() . '/js/nav.js',
        [],
        (string) filemtime($dir . '/js/nav.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'pion_assets');

/**
 * Блок «Читать дальше» рисуется в шаблоне, поэтому многоточие в анонсе должно
 * быть просто многоточием, а не ссылкой [...].
 */
add_filter('excerpt_more', static fn(): string => '…');
add_filter('excerpt_length', static fn(): int => 28);

/** Первая рубрика записи — она подписывает карточку и статью. */
function pion_primary_category(): ?WP_Term
{
    $terms = get_the_category();

    return $terms ? $terms[0] : null;
}

/**
 * Дата в подписи. Формат берём из настроек сайта, чтобы он совпадал с тем,
 * что видно в админке.
 */
function pion_posted_on(): string
{
    return sprintf(
        '<time datetime="%s">%s</time>',
        esc_attr(get_the_date(DATE_W3C)),
        esc_html(get_the_date())
    );
}

/**
 * Описание страницы для поисковика.
 *
 * Блог отдавался вообще без description: в выдаче под заголовком поисковик
 * показывал случайный кусок текста. У записи берём её же анонс, у рубрики —
 * описание рубрики, у остальных страниц — подзаголовок блога.
 */
function pion_meta_description(): string
{
    if (is_singular()) {
        $text = get_the_excerpt();
        if ($text === '') {
            $text = wp_strip_all_tags((string) get_post_field('post_content', get_the_ID()));
        }
    } elseif (is_category() || is_tag() || is_tax()) {
        $text = (string) term_description();
        if (trim(wp_strip_all_tags($text)) === '') {
            $text = sprintf('%s — заметки флористов салона «Пион», Пермь.', single_term_title('', false));
        }
    } elseif (is_search()) {
        $text = sprintf('Поиск по блогу салона «Пион»: «%s».', get_search_query());
    } else {
        $text = (string) get_bloginfo('description');
    }

    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)) ?? '');

    // 160 символов — столько показывает поисковик; режем по слову, чтобы
    // описание не обрывалось на середине.
    if (mb_strlen($text) > 160) {
        $text = mb_substr($text, 0, 160);
        $cut = mb_strrpos($text, ' ');
        if ($cut !== false && $cut > 100) {
            $text = mb_substr($text, 0, $cut);
        }
        $text = rtrim($text, " ,.;:—-") . '…';
    }

    return $text;
}

/**
 * Канонический адрес. WordPress ставит его сам только на одиночных записях —
 * у главной, рубрик и архивов его не было, и они выглядели как возможные
 * дубли друг друга.
 */
function pion_canonical_url(): string
{
    if (is_singular()) {
        return (string) get_permalink();
    }
    if (is_category() || is_tag() || is_tax()) {
        $link = get_term_link(get_queried_object());
        return is_wp_error($link) ? home_url('/') : (string) $link;
    }
    if (is_home() || is_front_page()) {
        return home_url('/');
    }
    return home_url(add_query_arg([], $GLOBALS['wp']->request ? '/' . $GLOBALS['wp']->request . '/' : '/'));
}

/** Разметка для поисковика: сам блог и, на странице записи, сама запись. */
function pion_json_ld(): array
{
    $blog = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url' => home_url('/'),
        'inLanguage' => 'ru-RU',
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Салон цветов и подарков «Пион»',
            'url' => PION_SITE_URL . '/',
        ],
    ];

    if (!is_singular('post')) {
        return [$blog];
    }

    $post = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => get_the_title(),
        'description' => pion_meta_description(),
        'datePublished' => get_the_date(DATE_W3C),
        'dateModified' => get_the_modified_date(DATE_W3C),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink()],
        'url' => get_permalink(),
        'inLanguage' => 'ru-RU',
        'author' => ['@type' => 'Organization', 'name' => 'Салон цветов «Пион»'],
        'publisher' => $blog['publisher'],
    ];

    if (has_post_thumbnail()) {
        $post['image'] = get_the_post_thumbnail_url(get_the_ID(), 'pion-cover');
    }

    $cat = pion_primary_category();
    if ($cat) {
        $post['articleSection'] = $cat->name;
    }

    return [$blog, $post];
}

/**
 * Архив автора закрыт от поиска и убран из карты сайта: автор один, и такая
 * страница — просто ещё одна копия ленты записей.
 */
add_filter('wp_robots', static function (array $robots): array {
    if (is_author() || is_date() || is_search() || is_paged()) {
        $robots['noindex'] = true;
        $robots['follow'] = true;
    }
    return $robots;
});

add_filter('wp_sitemaps_add_provider', static function ($provider, string $name) {
    return $name === 'users' ? false : $provider;
}, 10, 2);

// Свой canonical ставится в header.php для всех типов страниц. Штатный
// rel_canonical WordPress работает только на одиночных записях — если его
// оставить, там окажется два тега подряд.
remove_action('wp_head', 'rel_canonical');
