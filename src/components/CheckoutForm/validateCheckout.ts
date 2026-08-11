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

function normalizePhone(phone: string): string {
  // Remove all non-digits except leading +
  const cleaned = phone.replace(/[\s()-]/g, '');
  // If starts with 8 and has 11 digits total, replace 8 with 7
  if (cleaned.match(/^8\d{10}$/)) {
    return '7' + cleaned.slice(1);
  }
  return cleaned;
}

export function validateCheckout(values: CheckoutValues): { valid: boolean; errors: Record<string, string> } {
  const errors: Record<string, string> = {};

  if (!values.name.trim()) errors.name = 'Укажите ваше имя';
  if (!EMAIL_RE.test(values.email)) errors.email = 'Укажите корректный email';
  if (!PHONE_RE.test(normalizePhone(values.phone))) errors.phone = 'Укажите корректный телефон';
  if (values.deliveryOption !== 'pickup' && !values.address.trim()) {
    errors.address = 'Укажите адрес доставки';
  }
  if (!values.paymentMethod) errors.paymentMethod = 'Выберите способ оплаты';

  return { valid: Object.keys(errors).length === 0, errors };
}
