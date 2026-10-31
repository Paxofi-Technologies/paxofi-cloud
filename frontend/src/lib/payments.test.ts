import { describe, expect, it } from 'vitest';
import { isCheckoutUrl, newIdempotencyKey, paymentIdForPath } from './payments';

describe('payments helpers (PY-6, PY-10, PT-11)', () => {
  it('leaves the site only for a gateway checkout host over https', () => {
    expect(isCheckoutUrl('https://checkout.paystack.com/abc123')).toBe(true);
    expect(isCheckoutUrl('https://checkout.flutterwave.com/v3/hosted/pay/abc')).toBe(true);
    expect(isCheckoutUrl('https://checkout-testing.flutterwave.com/v3/hosted/pay/abc')).toBe(true);
    for (const bad of ['http://checkout.paystack.com/x', 'https://checkout.paystack.com.evil.example/x', 'https://evil.example/?checkout.paystack.com', 'https://user:pw@checkout.paystack.com/x', 'javascript:alert(1)', '']) {
      expect(isCheckoutUrl(bad)).toBe(false);
    }
  });

  it('makes idempotency keys the server accepts, different on every click', () => {
    const a = newIdempotencyKey();
    expect(a).toMatch(/^[A-Za-z0-9_-]{16,64}$/);
    expect(newIdempotencyKey()).not.toBe(a);
  });

  it('reads only a 32-hex payment id from the return path', () => {
    expect(paymentIdForPath('/billing/payments/' + 'f'.repeat(32))).toBe('f'.repeat(32));
    expect(paymentIdForPath('/billing/payments/' + 'f'.repeat(31))).toBeNull();
    expect(paymentIdForPath('/billing/payments/' + 'F'.repeat(32))).toBeNull();
    expect(paymentIdForPath('/billing')).toBeNull();
  });
});
