import { describe, expect, it } from 'vitest';
import { formatMoney, money } from './money';

describe('money', () => {
  it('formats naira from kobo without floating point', () => {
    expect(formatMoney(money(1_500_000, 'NGN'))).toBe('₦15,000.00');
    expect(formatMoney(money(5, 'NGN'))).toBe('₦0.05');
  });

  it('formats dollars from cents', () => {
    expect(formatMoney(money(1999, 'USD'), 'en-US')).toBe('$19.99');
  });

  it('formats negative amounts (refunds, credit notes)', () => {
    expect(formatMoney(money(-250, 'USD'), 'en-US')).toBe('-$2.50');
  });

  it('stays exact for values where float division would drift', () => {
    // 0.1 + 0.2 style errors cannot occur: 2^53-1 minor units format exactly.
    expect(formatMoney(money(Number.MAX_SAFE_INTEGER, 'USD'), 'en-US')).toBe('$90,071,992,547,409.91');
  });

  it('rejects non-integer or unsafe amounts', () => {
    expect(() => money(10.5, 'NGN')).toThrow(RangeError);
    expect(() => money(Number.MAX_SAFE_INTEGER + 1, 'NGN')).toThrow(RangeError);
    expect(() => formatMoney({ minor: 0.1, currency: 'USD' })).toThrow(RangeError);
  });
});
