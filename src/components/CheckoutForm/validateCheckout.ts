export interface CheckoutValues {
  name: string;
  email: string;
  phone: string;
  deliveryOption: string;
  address: string;
  paymentMethod: string;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE_RE = /^\+?7\d{10}$/;

export function validateCheckout(values: CheckoutValues): { valid: boolean; errors: Record<string, string> } {
  const errors: Record<string, string> = {};

  if (!values.name.trim()) errors.name = 'Укажите ваше имя';
  if (!EMAIL_RE.test(values.email)) errors.email = 'Укажите корректный email';
  if (!PHONE_RE.test(values.phone.replace(/[\s()-]/g, ''))) errors.phone = 'Укажите корректный телефон';
  if (values.deliveryOption !== 'pickup' && !values.address.trim()) {
    errors.address = 'Укажите адрес доставки';
  }
  if (!values.paymentMethod) errors.paymentMethod = 'Выберите способ оплаты';

  return { valid: Object.keys(errors).length === 0, errors };
}
