import { describe, it, expect } from 'vitest';
import { validateBouquetStep, type BouquetValues } from './validateBouquetForm';

const valid: BouquetValues = {
  bouquetType: 'Авторский букет',
  colorScheme: 'Яркая гамма',
  size: 'M (средний)',
  budget: 5000,
  readyBy: '',
  extras: '',
  comment: '',
  name: 'Ирина',
  phone: '+79001234567',
  email: 'irina@example.com',
};

describe('validateBouquetStep', () => {
  it('step 0 requires a bouquet type', () => {
    expect(validateBouquetStep(0, { ...valid, bouquetType: '' }).valid).toBe(false);
    expect(validateBouquetStep(0, valid).valid).toBe(true);
  });

  it('step 1 requires a color scheme', () => {
    expect(validateBouquetStep(1, { ...valid, colorScheme: '' }).valid).toBe(false);
    expect(validateBouquetStep(1, valid).valid).toBe(true);
  });

  it('step 2 requires a size', () => {
    expect(validateBouquetStep(2, { ...valid, size: '' }).valid).toBe(false);
  });

  it('step 3 requires budget within 3000-50000', () => {
    expect(validateBouquetStep(3, { ...valid, budget: 1000 }).valid).toBe(false);
    expect(validateBouquetStep(3, { ...valid, budget: 3000 }).valid).toBe(true);
    expect(validateBouquetStep(3, { ...valid, budget: 50000 }).valid).toBe(true);
  });

  it('final step requires name, valid phone and email', () => {
    expect(validateBouquetStep(6, { ...valid, name: '' }).valid).toBe(false);
    expect(validateBouquetStep(6, { ...valid, phone: '123' }).valid).toBe(false);
    expect(validateBouquetStep(6, { ...valid, email: 'bad' }).valid).toBe(false);
    expect(validateBouquetStep(6, valid).valid).toBe(true);
  });

  it('final step accepts 8-prefix phone numbers (normalized to 7)', () => {
    // 8-prefix should be accepted and normalized to 7 internally
    const result = validateBouquetStep(6, { ...valid, phone: '89001234567' });
    expect(result.valid).toBe(true);
    expect(Object.keys(result.errors || {}).length).toBe(0);
  });

  it('final step returns per-field errors when multiple validations fail', () => {
    const result = validateBouquetStep(6, { ...valid, name: '', phone: '123', email: 'bad' });
    expect(result.valid).toBe(false);
    expect(result.errors).toBeDefined();
    expect(result.errors?.name).toBe('Укажите ваше имя');
    expect(result.errors?.phone).toBe('Укажите корректный телефон');
    expect(result.errors?.email).toBe('Укажите корректный email');
  });

  it('final step returns individual field errors', () => {
    const nameError = validateBouquetStep(6, { ...valid, name: '' });
    expect(nameError.valid).toBe(false);
    expect(nameError.errors?.name).toBe('Укажите ваше имя');
    expect(nameError.errors?.phone).toBeUndefined();
    expect(nameError.errors?.email).toBeUndefined();

    const phoneError = validateBouquetStep(6, { ...valid, phone: '123' });
    expect(phoneError.valid).toBe(false);
    expect(phoneError.errors?.phone).toBe('Укажите корректный телефон');
    expect(phoneError.errors?.name).toBeUndefined();
    expect(phoneError.errors?.email).toBeUndefined();

    const emailError = validateBouquetStep(6, { ...valid, email: 'bad' });
    expect(emailError.valid).toBe(false);
    expect(emailError.errors?.email).toBe('Укажите корректный email');
    expect(emailError.errors?.name).toBeUndefined();
    expect(emailError.errors?.phone).toBeUndefined();
  });
});
