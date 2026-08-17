import type { Metadata } from 'next';
import { CheckoutForm } from '@/components/CheckoutForm/CheckoutForm';
import { buildMetadata } from '@/lib/seo';

// A cart page has nothing to offer search results, so it is kept out of the
// index while staying reachable for people and agents.
export const metadata: Metadata = {
  ...buildMetadata({
    title: 'Оформление заказа | Салон цветов «Пион»',
    description:
      'Оформление заказа букета в салоне цветов «Пион», Пермь. Укажите адрес и время доставки — флорист свяжется с вами для подтверждения деталей.',
    path: '/checkout/',
  }),
  title: { absolute: 'Оформление заказа | Салон цветов «Пион»' },
  robots: { index: false, follow: true },
};

export default function CheckoutPage() {
  return (
    <main>
      <CheckoutForm />
    </main>
  );
}
