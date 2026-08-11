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
});
