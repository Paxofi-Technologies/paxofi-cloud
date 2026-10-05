export type Currency = 'NGN' | 'USD';

export interface Money {
  /** Amount in integer minor units (kobo for NGN, cents for USD). Never a float. */
  readonly minor: number;
  readonly currency: Currency;
}

const MINOR_DIGITS: Record<Currency, number> = { NGN: 2, USD: 2 };

export function money(minor: number, currency: Currency): Money {
  if (!Number.isSafeInteger(minor)) {
    throw new RangeError(`Money must be a safe integer of minor units, got ${minor}`);
  }
  return { minor, currency };
}

/**
 * Formats integer minor units without floating-point arithmetic: the decimal
 * string is built from integer division and handed to Intl as a string.
 */
export function formatMoney({ minor, currency }: Money, locale = 'en-NG'): string {
  if (!Number.isSafeInteger(minor)) {
    throw new RangeError(`Money must be a safe integer of minor units, got ${minor}`);
  }
  const digits = MINOR_DIGITS[currency];
  const sign = minor < 0 ? '-' : '';
  const abs = Math.abs(minor);
  const base = 10 ** digits;
  const major = Math.trunc(abs / base);
  const fraction = String(abs % base).padStart(digits, '0');
  const decimal = `${sign}${major}.${fraction}` as `${number}`;
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(decimal);
}
