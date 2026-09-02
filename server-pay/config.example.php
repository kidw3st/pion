<?php
/**
 * Шаблон конфига. Боевой config.php лежит ТОЛЬКО на сервере — в репозиторий
 * он не попадает никогда (репозиторий публичный).
 */

declare(strict_types=1);

// Терминал Т-Банка (интернет-эквайринг). Значения — из настроек магазина
// в личном кабинете Т-Банк Бизнес.
const TBANK_TERMINAL = 'PUT_TERMINAL_ID_HERE';
const TBANK_PASSWORD = 'PUT_TERMINAL_PASSWORD_HERE';
const TBANK_API = 'https://securepay.tinkoff.ru/v2/';

// Куда слать письма о заказах.
const SALON_EMAIL = 'pion-perm59@yandex.ru';

// Публичный адрес сайта — для ссылок возврата с оплаты.
const SITE_ORIGIN = 'https://pionperm.ru';

// Posiflora: интеграционный пользователь (создаётся владельцем в CRM).
// Пока пусто — заказы идут только письмом, CRM пропускается без ошибок.
const POSIFLORA_BASE = 'https://floweranddecorshoppion.posiflora.com/api/v1/';
const POSIFLORA_USER = '';
const POSIFLORA_PASSWORD = '';
// id магазина и источника «Сайт» из справочников Posiflora
// (GET /api/v1/stores и /api/v1/order-sources).
const POSIFLORA_STORE_ID = '';
const POSIFLORA_SOURCE_ID = '';

// Товары главной страницы («Новинки»), которых нет в JSON-каталоге.
// Цены — из data/site.json, uid — как их кладёт в корзину фронт.
const EXTRA_ITEMS = [
    'new-Пион' => ['title' => 'Пион (Новинки)', 'price' => 1600],
    'new-ChocoMe' => ['title' => 'ChocoMe', 'price' => 1900],
    'new-Новинка!' => ['title' => 'Композиция «Новинка!»', 'price' => 3000],
];
