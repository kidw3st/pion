import type { Metadata } from 'next';
import { CheckoutForm } from '@/components/CheckoutForm/CheckoutForm';
import { buildMetadata } from '@/lib/seo';

// A cart page has nothing to offer search results, so it is kept out of the
// index while staying reachable for people and agents.
export const metadata: Metadata = {
  ...buildMetadata({
    title: 'Оформление заказа',
    description: 'Оформление заказа в салоне цветов «Пион», Пермь.',
    path: '/checkout/',
  }),
  robots: { index: false, follow: true },
};

export default function CheckoutPage() {
  return (
    <main>
      <CheckoutForm />
    </main>
  );
}
