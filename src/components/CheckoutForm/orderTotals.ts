import type { DeliveryOption } from '@/lib/types';
import {
  EVENING_PERCENT,
  GIFT_BOX,
  GIFT_BOX_FROM,
  GIFT_SHOPPER,
  GIFT_SHOPPER_FROM,
  isShowcaseUid,
} from '@/lib/promo';

/** Позиция корзины в том виде, в каком её считают: что, почём и сколько. */
export type PricedItem = {
  uid: string;
  price: number;
  quantity: number;
};

export type OrderTotals = {
  /** Стоимость товара по прайсу, до скидок. */
  goods: number;
  /** Сколько человек платит за товар после скидок. */
  paidForGoods: number;
  delivery: number;
  /** Доставка обнулена акцией, а не бесплатна сама по себе (самовывоз). */
  deliveryFree: boolean;
  eveningDiscount: number;
  pickupDiscount: number;
  discount: number;
  gifts: string[];
  total: number;
};

/**
 * Works out what the customer actually pays, so the full sum can be shown
 * before they commit rather than appearing at the end.
 *
 * With no delivery option chosen yet, the goods total is shown on its own —
 * never a guessed delivery price, which would understate the real cost.
 *
 * Скидки снимаются с цены за штуку и округляются до рубля — ровно так же, как
 * в price_order() на сервере. Если считать процент от всей корзины, суммы
 * разойдутся на рубль-другой, и человек увидит одну цену, а спишется другая.
 */
export function orderTotals(
  items: PricedItem[],
  option: DeliveryOption | null,
  eveningActive = false,
): OrderTotals {
  const pickupPercent = option?.discountPercent ?? 0;

  let goods = 0;
  let paidForGoods = 0;
  let eveningDiscount = 0;
  let pickupDiscount = 0;

  for (const item of items) {
    let unit = item.price;

    if (eveningActive && isShowcaseUid(item.uid)) {
      const off = Math.round((unit * EVENING_PERCENT) / 100);
      unit -= off;
      eveningDiscount += off * item.quantity;
    }
    // Скидка самовывоза идёт следом, от уже уценённого: две скидки на одну
    // сумму не складываются.
    if (pickupPercent > 0) {
      const off = Math.round((unit * pickupPercent) / 100);
      unit -= off;
      pickupDiscount += off * item.quantity;
    }

    goods += item.price * item.quantity;
    paidForGoods += unit * item.quantity;
  }

  const listedDelivery = option?.priceRub ?? 0;
  const freeFrom = option?.freeFromRub;
  const deliveryFree =
    freeFrom !== undefined && listedDelivery > 0 && paidForGoods >= freeFrom;
  const delivery = deliveryFree ? 0 : listedDelivery;

  // Подарки считаются от оплаченной стоимости товара: доставка к порогу
  // не относится, иначе дальняя зона «дарила» бы шоппер за чужие деньги.
  const gifts: string[] = [];
  if (paidForGoods >= GIFT_BOX_FROM) gifts.push(GIFT_BOX);
  if (paidForGoods >= GIFT_SHOPPER_FROM) gifts.push(GIFT_SHOPPER);

  return {
    goods,
    paidForGoods,
    delivery,
    deliveryFree,
    eveningDiscount,
    pickupDiscount,
    discount: eveningDiscount + pickupDiscount,
    gifts,
    total: paidForGoods + delivery,
  };
}
