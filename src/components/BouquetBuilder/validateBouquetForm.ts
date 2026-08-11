export interface BouquetValues {
  bouquetType: string;
  colorScheme: string;
  size: string;
  budget: number;
  readyBy: string;
  extras: string;
  comment: string;
  name: string;
  phone: string;
  email: string;
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

export function validateBouquetStep(
  step: number,
  values: BouquetValues,
): { valid: boolean; error?: string; errors?: Record<string, string> } {
  switch (step) {
    case 0:
      return values.bouquetType ? { valid: true } : { valid: false, error: 'Выберите тип букета' };
    case 1:
      return values.colorScheme ? { valid: true } : { valid: false, error: 'Выберите цветовую гамму' };
    case 2:
      return values.size ? { valid: true } : { valid: false, error: 'Выберите размер букета' };
    case 3:
      return values.budget >= 3000 && values.budget <= 50000
        ? { valid: true }
        : { valid: false, error: 'Бюджет должен быть от 3 000 до 50 000 рублей' };
    case 4:
    case 5:
      return { valid: true };
    case 6: {
      const errors: Record<string, string> = {};
      if (!values.name.trim()) errors.name = 'Укажите ваше имя';
      if (!PHONE_RE.test(normalizePhone(values.phone))) {
        errors.phone = 'Укажите корректный телефон';
      }
      if (!EMAIL_RE.test(values.email)) errors.email = 'Укажите корректный email';
      return { valid: Object.keys(errors).length === 0, errors };
    }
    default:
      return { valid: true };
  }
}
