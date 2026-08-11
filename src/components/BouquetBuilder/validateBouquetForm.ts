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

export function validateBouquetStep(step: number, values: BouquetValues): { valid: boolean; error?: string } {
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
      if (!values.name.trim()) return { valid: false, error: 'Укажите ваше имя' };
      if (!PHONE_RE.test(values.phone.replace(/[\s()-]/g, ''))) {
        return { valid: false, error: 'Укажите корректный телефон' };
      }
      if (!EMAIL_RE.test(values.email)) return { valid: false, error: 'Укажите корректный email' };
      return { valid: true };
    }
    default:
      return { valid: true };
  }
}
