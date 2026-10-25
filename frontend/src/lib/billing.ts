import { describeWait, postJson } from './api';

/** The account's billing details (THREAT-MODEL-COMMERCE.md CD-21). Values are rendered as text only. */
export const BILLING_FIELDS = ['full_name', 'company', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'phone', 'tin'] as const;
export type BillingField = (typeof BILLING_FIELDS)[number];
export type BillingDetails = Record<BillingField, string>;

export interface BillingState {
  details: BillingDetails | null;
  currency: 'NGN' | 'USD' | null;
  currencyLocked: boolean;
  tax: { name: string; rateBp: number } | null;
  countries: { code: string; name: string }[];
}

export type BillingResult = { kind: 'ok'; billing: BillingState } | { kind: 'invalid'; errors: Partial<Record<BillingField | 'form', string>> } | { kind: 'error'; message: string };

export const EMPTY_DETAILS: BillingDetails = Object.fromEntries(BILLING_FIELDS.map((f) => [f, ''])) as BillingDetails;

/** Keeps only well-formed values from the API. */
export function readBilling(data: unknown): BillingState {
  const body = typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
  const raw = typeof body.details === 'object' && body.details !== null ? (body.details as Record<string, unknown>) : null;
  const details = raw === null ? null : (Object.fromEntries(BILLING_FIELDS.map((f) => [f, typeof raw[f] === 'string' ? raw[f] : ''])) as BillingDetails);
  const tax = typeof body.tax === 'object' && body.tax !== null ? (body.tax as Record<string, unknown>) : null;
  const countries = Array.isArray(body.countries)
    ? body.countries.flatMap((c) => {
        const r = typeof c === 'object' && c !== null ? (c as Record<string, unknown>) : {};
        return typeof r.code === 'string' && /^[A-Z]{2}$/.test(r.code) && typeof r.name === 'string' ? [{ code: r.code, name: r.name }] : [];
      })
    : [];
  return {
    details,
    currency: body.currency === 'NGN' || body.currency === 'USD' ? body.currency : null,
    currencyLocked: body.currency_locked === true,
    tax: tax !== null && typeof tax.name === 'string' && typeof tax.rate_bp === 'number' ? { name: tax.name, rateBp: tax.rate_bp } : null,
    countries,
  };
}

export async function fetchBilling(fetchImpl: typeof fetch = fetch): Promise<BillingResult> {
  try {
    const response = await fetchImpl('/api/v1/account/billing', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) return { kind: 'error', message: 'Your billing details could not be loaded. Please try again.' };
    return { kind: 'ok', billing: readBilling(await response.json()) };
  } catch {
    return { kind: 'error', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
}

export async function saveBilling(details: BillingDetails, csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<BillingResult> {
  const result = await postJson('/api/v1/account/billing', details, fetchImpl, { 'X-CSRF-Token': csrfToken });
  if (result.kind === 'ok') return { kind: 'ok', billing: readBilling(result.data) };
  if (result.kind === 'invalid') return { kind: 'invalid', errors: result.errors };
  if (result.kind === 'throttled') return { kind: 'error', message: `Too many changes. Try again ${describeWait(result.retryAfterSeconds)}.` };
  if (result.kind === 'rejected' && result.code === 'not_account_owner') return { kind: 'error', message: 'Only the account owner can change billing details.' };
  return { kind: 'error', message: 'Your billing details could not be saved. Please try again.' };
}

/** "7.5%" from basis points, without floats leaking odd decimals. */
export function formatRate(bp: number): string {
  const whole = Math.trunc(bp / 100);
  const rest = bp % 100;
  return rest === 0 ? `${whole}%` : `${whole}.${String(rest).padStart(2, '0').replace(/0$/, '')}%`;
}
