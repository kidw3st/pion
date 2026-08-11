'use client';

import { useState } from 'react';
import { BouquetBuilderPopup } from './BouquetBuilderPopup';

export function BouquetBuilderCta() {
  const [open, setOpen] = useState(false);
  return (
    <section style={{ padding: '48px 40px', textAlign: 'center', background: 'var(--color-bg-light)' }}>
      <h2>Соберите свой идеальный букет</h2>
      <p>Вы можете заказать букет, цветочную коробку, корзину, композицию по вашим параметрам и пожеланиям</p>
      <button onClick={() => setOpen(true)}>Заказать</button>
      <BouquetBuilderPopup open={open} onClose={() => setOpen(false)} />
    </section>
  );
}
