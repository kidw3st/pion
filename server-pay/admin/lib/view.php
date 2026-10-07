<?php
/**
 * Разметка админки: каркас страницы, экранирование, деньги и даты по-русски.
 * Страницы собирают HTML строками — шаблонизатор ради десятка экранов не
 * нужен. Всё, что пришло из базы или от пользователя, проходит через h().
 */

declare(strict_types=1);

require_once __DIR__ . '/http.php';

const ADMIN_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

const ADMIN_STATUS_LABELS = ['draft' => 'Черновик', 'active' => 'В продаже', 'hidden' => 'Снят с продажи', 'deleted' => 'Удалён'];

/** Сообщения после перехода. В адресе — только ключ: текст из адресной строки на страницу не попадает. */
const ADMIN_NOTICES = [
    'password' => 'Пароль сменён.',
    'saved' => 'Сохранено.',
    'created' => 'Черновик создан. Добавьте фото и опубликуйте, когда букет будет готов.',
    'published' => 'Букет опубликован.',
    'hidden' => 'Букет снят с продажи.',
    'unhidden' => 'Букет снова в продаже.',
    'deleted' => 'Букет удалён. Восстановить его можно в течение 90 дней.',
    'restored' => 'Букет восстановлен.',
    'removed' => 'Черновик удалён.',
    'section-created' => 'Раздел создан. Заполните его; пока не готов — снимите галочку «Показывать в каталоге».',
];

/** Текст для HTML: кавычки и угловые скобки — сущности. */
function h(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 4400 → «4 400 ₽» (неразрывные пробелы: цена не разрывается на две строки). */
function admin_rub(int $amount): string
{
    return number_format($amount, 0, '', "\u{00A0}") . "\u{00A0}₽";
}

function admin_perm(string $iso): DateTimeImmutable
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Asia/Yekaterinburg'));
}

/** 2026-03-12T14:32:00+05:00 → «12 марта» (по Перми). */
function admin_day(string $iso): string
{
    $at = admin_perm($iso);
    return $at->format('j') . ' ' . ADMIN_MONTHS[(int)$at->format('n') - 1];
}

/** → «12 марта, 14:32» (по Перми). */
function admin_date(string $iso): string
{
    return admin_day($iso) . ', ' . admin_perm($iso)->format('H:i');
}

/** Адрес файла оформления с меткой версии: после выкладки браузер берёт новый, а не из кэша. */
function admin_asset(string $file): string
{
    return ADMIN_BASE . 'assets/' . $file . '?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/' . $file);
}

/** Скрытое поле с CSRF-токеном сессии — в каждой форме с POST. */
function admin_csrf_field(array $user): string
{
    return '<input type="hidden" name="csrf" value="' . h($user['csrf']) . '">';
}

function admin_notice(array $req): string
{
    return ADMIN_NOTICES[admin_str($req['query'], 'notice')] ?? '';
}

function admin_error(string $message): string
{
    return $message === '' ? '' : '<p class="error">' . h($message) . '</p>';
}

/**
 * Полная страница: шапка с меню, строка статуса выкладки, сообщение.
 * $user — сессия (имя для меню и CSRF для выхода), null — без меню (вход).
 */
function admin_layout(string $title, string $content, ?array $user = null, string $status = '', string $notice = ''): string
{
    $nav = '';
    if ($user !== null) {
        $nav = '<nav class="nav">'
            . '<a href="' . ADMIN_BASE . '">Букеты</a>'
            . '<a href="' . ADMIN_BASE . 'sections.php">Разделы</a>'
            . '<a href="' . ADMIN_BASE . 'log.php">Журнал</a>'
            . '<a href="' . ADMIN_BASE . 'password.php">Пароль</a>'
            . '<form method="post" action="' . ADMIN_BASE . 'logout.php">' . admin_csrf_field($user)
            . '<button class="link" type="submit">Выйти (' . h($user['name']) . ')</button></form>'
            . '</nav>';
    }
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . ' — админка «Пиона»</title>'
        . '<link rel="stylesheet" href="' . h(admin_asset('admin.css')) . '">'
        . '</head><body>'
        . '<header class="top"><a class="brand" href="' . ADMIN_BASE . '">Пион · админка</a>' . $nav . '</header>'
        . $status
        . '<main>' . ($notice !== '' ? '<p class="notice">' . h($notice) . '</p>' : '') . $content . '</main>'
        . '<script src="' . h(admin_asset('photo.js')) . '" defer></script>'
        . '</body></html>';
}

/** Страница 404: понятный текст и ссылка назад вместо ошибки. */
function admin_not_found(array $ctx, string $what = 'Такого букета нет', string $back = ''): array
{
    $html = '<h1>' . h($what) . '</h1><p>Возможно, его уже удалили или адрес неполный.</p>'
        . '<p><a href="' . ADMIN_BASE . h($back) . '">Назад к списку</a></p>';
    return admin_html(admin_layout($what, $html, $ctx['user'], $ctx['status']), 404);
}
