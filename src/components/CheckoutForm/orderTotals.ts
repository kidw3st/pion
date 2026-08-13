import type { DeliveryOption } from '@/lib/types';

export type OrderTotals = {
  goods: number;
  delivery: number;
  discount: number;
  total: number;
};

/**
 * Works out what the customer actually pays, so the full sum can be shown
 * before they commit rather than appearing at the end.
 *
 * With no delivery option chosen yet, the goods total is shown on its own —
 * never a guessed delivery price, which would understate the real cost.
 */
export function orderTotals(goods: number, option: DeliveryOption | null): OrderTotals {
  if (!option) {
    return { goods, delivery: 0, discount: 0, total: goods };
  }

  const delivery = option.priceRub;
  const discount = option.discountPercent
    ? Math.round((goods * option.discountPercent) / 100)
    : 0;

  return {
    goods,
    delivery,
    discount,
    total: Math.max(0, goods + delivery - discount),
  };
}
