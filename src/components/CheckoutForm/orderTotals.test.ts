import { describe, it, expect } from 'vitest';
import { orderTotals } from './orderTotals';
import type { DeliveryOption } from '@/lib/types';

const zone5: DeliveryOption = { id: 'zone-5', label: 'До 5 км', priceRub: 500 };
const pickup: DeliveryOption = { id: 'pickup', label: 'Самовывоз', priceRub: 0, discountPercent: 5 };

describe('orderTotals', () => {
  it('adds the delivery price to the goods', () => {
    expect(orderTotals(10000, zone5)).toEqual({
      goods: 10000,
      delivery: 500,
      discount: 0,
      total: 10500,
    });
  });

  it('applies the pickup discount to the goods and charges no delivery', () => {
    expect(orderTotals(10000, pickup)).toEqual({
      goods: 10000,
      delivery: 0,
      discount: 500,
      total: 9500,
    });
  });

  it('rounds the discount to whole roubles', () => {
    // 5% of 4 830 is 241.5
    expect(orderTotals(4830, pickup).discount).toBe(242);
  });

  it('treats no chosen option as goods only, so the total is never understated', () => {
    expect(orderTotals(7700, null)).toEqual({
      goods: 7700,
      delivery: 0,
      discount: 0,
      total: 7700,
    });
  });

  it('never returns a negative total for an empty cart', () => {
    expect(orderTotals(0, pickup)).toEqual({ goods: 0, delivery: 0, discount: 0, total: 0 });
  });
});
