/**
 * Действующие акции салона.
 *
 * Те же числа лежат в server-pay/lib.php: деньги считает сервер, потому что
 * данные из браузера подделываются, а сайт считает то же самое только чтобы
 * показать сумму до нажатия «Оформить заказ». Логика округления в обоих
 * местах одна и та же — скидка снимается с цены за штуку в целых рублях,
 * поэтому показанная сумма всегда совпадает со списанной.
 */

/** Часовой пояс салона: Пермь на два часа впереди Москвы. */
export const SALON_TZ = 'Asia/Yekaterinburg';

/** Вечерняя скидка на букеты с витрины — они собраны сегодня. */
export const EVENING_PERCENT = 15;
export const EVENING_FROM_HOUR = 20;
export const EVENING_TO_HOUR = 22;

/** Пороги подарков — считаются от оплаченной стоимости товара, без доставки. */
export const GIFT_BOX_FROM = 5000;
export const GIFT_SHOPPER_FROM = 15000;

export const GIFT_BOX = 'Фирменная транспортировочная коробка';
export const GIFT_SHOPPER = 'Фирменный шоппер «Пион»';

/** С какой суммы бесплатна доставка в ближней зоне. */
export const FREE_DELIVERY_FROM = 5000;

/**
 * Букет с витрины? Синхронизатор Posiflora помечает такие идентификаторы
 * префиксом cs- (см. server-pay/sync-showcase.php), постоянный каталог его
 * не использует.
 */
export function isShowcaseUid(uid: string): boolean {
  return uid.startsWith('cs-');
}

/**
 * Идёт ли сейчас вечерняя скидка. Час берём по Перми, а не по часам
 * покупателя: иначе у человека из Москвы скидка началась бы в 18:00, а у
 * того, кто в Хабаровске, — в полночь.
 */
export function isEveningDiscountActive(now: Date = new Date()): boolean {
  const hour = Number(
    new Intl.DateTimeFormat('en-GB', {
      timeZone: SALON_TZ,
      hour: '2-digit',
      hourCycle: 'h23',
    }).format(now),
  );

  return hour >= EVENING_FROM_HOUR && hour < EVENING_TO_HOUR;
}
