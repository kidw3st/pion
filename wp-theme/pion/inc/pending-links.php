<?php
/**
 * Ссылки на статьи, которые ещё не вышли.
 *
 * Статьи блога ставятся в отложенные пачкой и выходят по одной в день, а
 * ссылаются друг на друга в обе стороны. Ссылка на отложенную статью до её
 * выхода открывалась бы как 404, поэтому при выводе текста такие ссылки
 * снимаются: в тексте остаётся анкор без ссылки, а пункт «Читайте также»
 * убирается. Когда статья выходит по расписанию, ссылки на неё появляются
 * во всех текстах сами — сами тексты для этого править не нужно.
 *
 * В «Читайте также» после запланированных пунктов стоят запасные — статьи,
 * которые уже вышли. Показываются первые три пункта, поэтому, пока соседние
 * статьи не вышли, их место занимают запасные.
 *
 * @package pion
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Сколько пунктов показывать в «Читайте также». */
const PION_READ_MORE_LIMIT = 3;

/** Адреса (post_name) записей, которые ещё не опубликованы, — ключами массива. */
function pion_pending_slugs(): array
{
    static $slugs = null;

    if ($slugs === null) {
        global $wpdb;
        $names = $wpdb->get_col(
            "SELECT post_name FROM {$wpdb->posts}
             WHERE post_type = 'post'
               AND post_status IN ('future', 'draft', 'pending', 'private')
               AND post_name <> ''"
        );
        $slugs = array_fill_keys($names, true);
    }

    return $slugs;
}

/**
 * @param mixed $content Текст записи после рендера блоков.
 * @return mixed
 */
function pion_hide_pending_links($content)
{
    // Адрес блога без схемы: в текстах ссылки с https, а в настройках может стоять http.
    $base = preg_replace('~^https?:~', '', home_url('/'));
    if (!is_string($content) || strpos($content, $base) === false) {
        return $content;
    }

    $pending = pion_pending_slugs();
    // Ссылка на запись блога: https://pionperm.ru/blog/<slug>/ (можно с #якорем).
    $link = '~<a\s[^>]*href="(?:https?:)?' . preg_quote($base, '~') . '([a-z0-9-]+)/?(?:#[^"]*)?"[^>]*>(.*?)</a>~su';
    $is_pending = static fn(string $html): bool
        => preg_match($link, $html, $m) === 1 && isset($pending[$m[1]]);

    // «Читайте также»: пункты со ссылками на невышедшие статьи убираем, из
    // остальных оставляем первые три. Если не осталось ничего — убираем и заголовок.
    $out = preg_replace_callback(
        '~(<h([2-4])[^>]*>[^<]*Читайте также[^<]*</h\2>\s*)<ul([^>]*)>(.*?)</ul>~su',
        static function (array $m) use ($is_pending): string {
            preg_match_all('~<li[^>]*>.*?</li>~su', $m[4], $items);
            $keep = array_values(array_filter($items[0], static fn(string $li): bool => !$is_pending($li)));
            if (!$keep) {
                return '';
            }
            return $m[1] . '<ul' . $m[3] . '>' . implode("\n", array_slice($keep, 0, PION_READ_MORE_LIMIT)) . '</ul>';
        },
        $content
    );
    if ($out === null) {
        return $content;
    }

    // В остальном тексте — анкор без ссылки.
    if ($pending) {
        $text = preg_replace_callback(
            $link,
            static fn(array $m): string => isset($pending[$m[1]]) ? $m[2] : $m[0],
            $out
        );
        if ($text !== null) {
            $out = $text;
        }
    }

    return $out;
}
// После рендера блоков (приоритет 9) и типографа (10): тут уже готовый HTML.
add_filter('the_content', 'pion_hide_pending_links', 20);
