<?php
/**
 * Лента для Дзена: https://pionperm.ru/blog/feed/dzen/ (или /blog/?feed=dzen).
 *
 * На сайте статья выходит полной, а в Дзен уходит её короткая версия — пересказ
 * со ссылкой на полную статью в блоге. Короткая версия лежит в поле записи
 * pion_dzen_html и на сайте нигде не выводится. В ленту попадают только
 * опубликованные записи с заполненным полем, поэтому отложенная запись уходит
 * в Дзен ровно тогда, когда выходит на сайте.
 *
 * Требования Дзена к ленте: https://dzen.ru/help/ru/website/rss-modify.html
 * (картинки — только JPEG, PNG или GIF шириной от 700 px; HTML — ограниченный
 * набор тегов; при первом подключении в ленте должно быть не меньше 10 материалов).
 *
 * @package pion
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Меняется, когда нужно заново сбросить правила адресов (новый адрес ленты). */
const PION_DZEN_FEED_VERSION = '1';

function pion_dzen_register(): void
{
    $can_edit = static fn(): bool => current_user_can('edit_posts');

    register_post_meta('post', 'pion_dzen_html', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'pion_dzen_sanitize_html',
        'auth_callback'     => $can_edit,
    ]);
    register_post_meta('post', 'pion_dzen_title', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => $can_edit,
    ]);

    // Переключатель «отправлять в Дзен черновиками» — пригодится при первом
    // подключении, чтобы накопившиеся статьи не вышли в канале разом.
    register_setting('reading', 'pion_dzen_as_draft', [
        'type'         => 'boolean',
        'default'      => false,
        'show_in_rest' => true,
    ]);

    add_feed('dzen', 'pion_dzen_feed');

    // Правило адреса /feed/dzen/ появляется только после сброса правил. Тему
    // обновляют заменой файлов, без переключения, поэтому сбрасываем один раз сами.
    if (get_option('pion_dzen_feed_version') !== PION_DZEN_FEED_VERSION) {
        flush_rewrite_rules(false);
        update_option('pion_dzen_feed_version', PION_DZEN_FEED_VERSION, false);
    }
}
add_action('init', 'pion_dzen_register');

add_filter('feed_content_type', static function (string $type, string $feed): string {
    return $feed === 'dzen' ? 'application/rss+xml' : $type;
}, 10, 2);

/** Теги, которые Дзен принимает в content:encoded. */
function pion_dzen_allowed_tags(): array
{
    return [
        'p'          => [],
        'b'          => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'a'          => ['href' => true],
        'h1'         => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'blockquote' => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'figure'     => [],
        'figcaption' => [],
        'img'        => ['src' => true, 'alt' => true, 'width' => true, 'height' => true],
    ];
}

function pion_dzen_sanitize_html($value): string
{
    return trim(wp_kses((string) $value, pion_dzen_allowed_tags()));
}

/** Текст внутри CDATA: последовательность ]]> разрезаем, иначе XML сломается. */
function pion_dzen_cdata(string $text): string
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]>';
}

/** Картинки из текста — Дзен ждёт каждую отдельным enclosure. WebP он не принимает. */
function pion_dzen_enclosures(string $html): array
{
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $out = [];

    if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            $ext = strtolower((string) pathinfo((string) wp_parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (isset($types[$ext]) && !isset($out[$src])) {
                $out[$src] = $types[$ext];
            }
        }
    }

    return $out;
}

function pion_dzen_feed(): void
{
    $posts = get_posts([
        'post_type'        => 'post',
        'post_status'      => 'publish',
        'numberposts'      => 50,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'meta_query'       => [[
            'key'     => 'pion_dzen_html',
            'value'   => '',
            'compare' => '!=',
        ]],
        'suppress_filters' => false,
    ]);

    $as_draft = (bool) get_option('pion_dzen_as_draft', false);

    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:georss="http://www.georss.org/georss">' . "\n";
    echo "<channel>\n";
    echo '<title>' . esc_html(get_bloginfo('name')) . "</title>\n";
    echo '<link>' . esc_url(home_url('/')) . "</link>\n";
    echo '<description>' . esc_html(get_bloginfo('description')) . "</description>\n";
    echo "<language>ru</language>\n";

    foreach ($posts as $post) {
        $html = (string) get_post_meta($post->ID, 'pion_dzen_html', true);
        if ($html === '') {
            continue;
        }

        $title = trim((string) get_post_meta($post->ID, 'pion_dzen_title', true));
        if ($title === '') {
            // Сырой заголовок: get_the_title() прогоняет его через типограф, а
            // именованные HTML-сущности в XML-ленте недопустимы.
            $title = (string) $post->post_title;
        }

        echo "<item>\n";
        echo '<title>' . esc_html(wp_strip_all_tags($title)) . "</title>\n";
        echo '<link>' . esc_url(get_permalink($post)) . "</link>\n";
        echo '<guid isPermaLink="false">pion-blog-' . (int) $post->ID . "</guid>\n";
        echo '<pubDate>' . esc_html(mysql2date('D, d M Y H:i:s +0000', $post->post_date_gmt, false)) . "</pubDate>\n";
        echo "<media:rating scheme=\"urn:simple\">nonadult</media:rating>\n";
        echo '<author>' . esc_html('Салон цветов «Пион»') . "</author>\n";
        if ($as_draft) {
            echo "<category>native-draft</category>\n";
        }
        echo "<category>format-article</category>\n";
        echo "<category>index</category>\n";
        echo "<category>comment-all</category>\n";
        echo '<description>' . pion_dzen_cdata(wp_strip_all_tags((string) $post->post_excerpt)) . "</description>\n";
        foreach (pion_dzen_enclosures($html) as $url => $type) {
            echo '<enclosure url="' . esc_url($url) . '" type="' . esc_attr($type) . "\"/>\n";
        }
        echo '<content:encoded>' . pion_dzen_cdata($html) . "</content:encoded>\n";
        echo "</item>\n";
    }

    echo "</channel>\n</rss>\n";
}
