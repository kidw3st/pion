import { describe, it, expect } from 'vitest';
import { isEveningDiscountActive, isShowcaseUid } from './promo';

/**
 * Время задаём в UTC, а проверяем пермское: Пермь — UTC+5 круглый год,
 * перевода часов в России нет. 15:00 UTC — это 20:00 в салоне.
 */
const utc = (iso: string) => new Date(iso);

describe('isEveningDiscountActive', () => {
  it('включается ровно в 20:00 по Перми', () => {
    expect(isEveningDiscountActive(utc('2026-09-16T14:59:59Z'))).toBe(false);
    expect(isEveningDiscountActive(utc('2026-09-16T15:00:00Z'))).toBe(true);
  });

  it('выключается в 22:00, когда салон закрывается', () => {
    expect(isEveningDiscountActive(utc('2026-09-16T16:59:59Z'))).toBe(true);
    expect(isEveningDiscountActive(utc('2026-09-16T17:00:00Z'))).toBe(false);
  });

  it('днём и ночью не действует', () => {
    expect(isEveningDiscountActive(utc('2026-09-16T09:00:00Z'))).toBe(false); // 14:00 в Перми
    expect(isEveningDiscountActive(utc('2026-09-16T19:00:00Z'))).toBe(false); // 00:00 в Перми
  });

  it('считает по Перми, а не по часам покупателя', () => {
    // 15:30 UTC — это 20:30 в Перми и 18:30 в Москве: скидка уже идёт.
    expect(isEveningDiscountActive(utc('2026-09-16T15:30:00Z'))).toBe(true);
    // 18:30 UTC — 21:30 в Москве, но в Перми уже 23:30: скидки нет.
    expect(isEveningDiscountActive(utc('2026-09-16T18:30:00Z'))).toBe(false);
  });
});

describe('isShowcaseUid', () => {
  it('узнаёт букеты с витрины по префиксу синхронизатора', () => {
    expect(isShowcaseUid('cs-1f8a2b')).toBe(true);
    expect(isShowcaseUid('tp-14821334')).toBe(false);
    expect(isShowcaseUid('')).toBe(false);
  });
});
