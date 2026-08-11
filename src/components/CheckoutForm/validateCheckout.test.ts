import { describe, it, expect } from 'vitest';
import { validateCheckout, type CheckoutValues } from './validateCheckout';

const validValues: CheckoutValues = {
  name: 'Анна',
  email: 'anna@example.com',
  phone: '+79001234567',
  deliveryOption: '1',
  address: 'ул. Ленина, 1',
  paymentMethod: 'cash',
};

describe('validateCheckout', () => {
  it('accepts fully filled-in values', () => {
    expect(validateCheckout(validValues)).toEqual({ valid: true, errors: {} });
  });

  it('requires a name', () => {
    const result = validateCheckout({ ...validValues, name: '' });
    expect(result.valid).toBe(false);
    expect(result.errors.name).toBeDefined();
  });

  it('requires a valid email', () => {
    const result = validateCheckout({ ...validValues, email: 'not-an-email' });
    expect(result.valid).toBe(false);
    expect(result.errors.email).toBeDefined();
  });

  it('requires a valid Russian phone number', () => {
    const result = validateCheckout({ ...validValues, phone: '123' });
    expect(result.valid).toBe(false);
    expect(result.errors.phone).toBeDefined();
  });

  it('requires an address unless self-pickup is chosen', () => {
    const result = validateCheckout({ ...validValues, address: '' });
    expect(result.valid).toBe(false);
    expect(result.errors.address).toBeDefined();

    const pickup = validateCheckout({ ...validValues, address: '', deliveryOption: 'pickup' });
    expect(pickup.valid).toBe(true);
  });
});
