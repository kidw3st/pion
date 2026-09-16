'use client';

import { useEffect, useState } from 'react';
import { isEveningDiscountActive } from './promo';

/**
 * Идёт ли сейчас вечерняя скидка.
 *
 * Начинаем всегда с «нет» и включаем уже после гидратации: страницы собраны
 * заранее, и в готовый HTML попал бы час сборки, а не час покупателя — React
 * ругался бы на расхождение, а человек в 21:00 видел бы дневную цену.
 *
 * Час пересчитывается на таймере: корзину открывают в 19:58, а оформляют в
 * 20:01 — цена на экране должна успеть измениться раньше, чем это сделает
 * сервер при оплате.
 */
export function useEveningDiscount(): boolean {
  const [active, setActive] = useState(false);

  useEffect(() => {
    const tick = () => setActive(isEveningDiscountActive());
    tick();
    const timer = window.setInterval(tick, 30_000);
    return () => window.clearInterval(timer);
  }, []);

  return active;
}
