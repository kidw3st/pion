import { describe, it, expect } from 'vitest';
import { orderTotals, type PricedItem } from './orderTotals';
import type { DeliveryOption } from '@/lib/types';

const zone3: DeliveryOption = { id: 'zone-3', label: 'До 3 км', priceRub: 300, freeFromRub: 5000 };
const zone5: DeliveryOption = { id: 'zone-5', label: 'До 5 км', priceRub: 500 };
const pickup: DeliveryOption = { id: 'pickup', label: 'Самовывоз', priceRub: 0, discountPercent: 5 };

/** Обычный каталожный товар. */
const shop = (price: number, quantity = 1): PricedItem => ({ uid: 'p-' + price, price, quantity });
/** Букет с витрины: только на них действует вечерняя скидка. */
const showcase = (price: number, quantity = 1): PricedItem => ({
  uid: 'cs-' + price,
  price,
  quantity,
});

describe('orderTotals', () => {
  it('adds the delivery price to the goods', () => {
    const t = orderTotals([shop(10000)], zone5);
    expect(t.goods).toBe(10000);
    expect(t.delivery).toBe(500);
    expect(t.discount).toBe(0);
    expect(t.total).toBe(10500);
  });

  it('applies the pickup discount to the goods and charges no delivery', () => {
    const t = orderTotals([shop(10000)], pickup);
    expect(t.delivery).toBe(0);
    expect(t.discount).toBe(500);
    expect(t.total).toBe(9500);
  });

  it('rounds the discount to whole roubles', () => {
    // 5% от 4 830 — это 241,5
    expect(orderTotals([shop(4830)], pickup).discount).toBe(242);
  });

  it('treats no chosen option as goods only, so the total is never understated', () => {
    const t = orderTotals([shop(7700)], null);
    expect(t.delivery).toBe(0);
    expect(t.total).toBe(7700);
  });

  it('never returns a negative total for an empty cart', () => {
    const t = orderTotals([], pickup);
    expect(t.total).toBe(0);
    expect(t.gifts).toEqual([]);
  });

  describe('вечерняя скидка', () => {
    it('снимает 15% только с букетов витрины', () => {
      const t = orderTotals([showcase(4000), shop(4000)], zone5, true);
      expect(t.eveningDiscount).toBe(600);
      expect(t.total).toBe(4000 - 600 + 4000 + 500);
    });

    it('днём не действует', () => {
      expect(orderTotals([showcase(4000)], zone5, false).eveningDiscount).toBe(0);
    });

    it('считается по каждой штуке, а не от суммы позиции', () => {
      // 15% от 999 — 149,85 → 150 с каждой штуки, а не 300 с 1998 разом.
      const t = orderTotals([showcase(999, 2)], null, true);
      expect(t.eveningDiscount).toBe(300);
      expect(t.paidForGoods).toBe(849 * 2);
    });

    it('не складывается со скидкой самовывоза, а идёт до неё', () => {
      const t = orderTotals([showcase(4000)], pickup, true);
      // 4000 − 600 = 3400, затем 5% = 170
      expect(t.eveningDiscount).toBe(600);
      expect(t.pickupDiscount).toBe(170);
      expect(t.total).toBe(3230);
    });
  });

  describe('бесплатная доставка', () => {
    it('включается от 5000 ₽ в ближней зоне', () => {
      expect(orderTotals([shop(5000)], zone3).delivery).toBe(0);
      expect(orderTotals([shop(5000)], zone3).deliveryFree).toBe(true);
    });

    it('не включается, если после скидки сумма упала ниже порога', () => {
      // 5000 с витрины вечером — это 4250 оплаченных, порог не взят.
      const t = orderTotals([showcase(5000)], zone3, true);
      expect(t.paidForGoods).toBe(4250);
      expect(t.delivery).toBe(300);
    });

    it('в дальних зонах не действует', () => {
      expect(orderTotals([shop(20000)], zone5).delivery).toBe(500);
    });
  });

  describe('подарки', () => {
    it('коробка от 5000 ₽', () => {
      expect(orderTotals([shop(4999)], null).gifts).toEqual([]);
      expect(orderTotals([shop(5000)], null).gifts).toEqual([
        'Фирменная транспортировочная коробка',
      ]);
    });

    it('шоппер добавляется от 15 000 ₽', () => {
      expect(orderTotals([shop(15000)], null).gifts).toEqual([
        'Фирменная транспортировочная коробка',
        'Фирменный шоппер «Пион»',
      ]);
    });

    it('порог считается по оплаченной сумме, без доставки', () => {
      // 4800 товара + 300 доставки — это 5100 к оплате, но подарка нет.
      expect(orderTotals([shop(4800)], zone3).gifts).toEqual([]);
    });
  });
});
