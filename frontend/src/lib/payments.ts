import { postJson } from './api';
import type { Currency } from './money';

/**
 * Paying an invoice (THREAT-MODEL-PAYMENTS.md PY-5, PY-6, PY-10). The browser
 * never sends an amount: it names the order and a gateway, then follows the
 * server's attempt until the gateway's checkout page is ready. After paying,
 * the return page shows only what the server says, never the gateway's query
 * parameters.
 */
export type Gateway = 'paystack' | 'flutterwave';

export const GATEWAY_LABELS: Record<Gateway, string> = { paystack: 'Paystack', flutterwave: 'Flutterwave' };

/** The gateways' own checkout hosts (PY-6, PT-11). The server checks too; this is a second look before leaving the site. */
export const CHECKOUT_HOSTS: readonly string[] = ['checkout.paystack.com', 'checkout.flutterwave.com', 'checkout-testing.flutterwave.com'];

export interface PaymentOptions {
  invoiceNumber: string;
  status: string;
  amount: number;
  currency: Currency;
  payable: boolean;
  gateways: Gateway[];
}

export interface Payment {
  id: string;
  state: string;
  gateway: string;
  amount: number;
  currency: Currency;
  invoiceNumber: string;
  orderId: string;
  checkoutUrl: string | null;
  failureReason: string | null;
}

const OFFLINE = 'We could not reach PaxofiCloud. Check your connection and try again.';

export const START_ERRORS: Record<string, string> = {
  invoice_not_payable: 'This invoice can no longer be paid.',
  invoice_expired: 'This order has expired. Place it again to see current prices.',
  gateway_unavailable: 'That payment method is not available for this invoice. Choose another one.',
  below_gateway_minimum: 'This amount is below that payment method’s minimum. Choose another one.',
  too_many_open_attempts: 'You have several payments still open for this invoice. Wait a few minutes, then try again.',
  not_account_owner: 'Only the account owner can pay invoices.',
  idempotency_key_reused: 'Please try again.',
};

/** Fixed failure codes from the server, in plain words. Anything else falls back to a general message. */
export const FAILURE_TEXT: Record<string, string> = {
  below_minimum: 'The amount is below this payment method’s minimum.',
  stale: 'The payment page took too long to open.',
  interrupted: 'The payment page could not be opened.',
  key_mode_mismatch: 'This payment method is not set up correctly yet.',
  credential_missing: 'This payment method is not set up yet.',
  credential_invalid: 'This payment method is not set up correctly yet.',
  unauthorised: 'This payment method is not set up correctly yet.',
};

export function isCheckoutUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' && CHECKOUT_HOSTS.includes(parsed.hostname) && parsed.username === '' && parsed.password === '';
  } catch {
    return false;
  }
}

/** A fresh Idempotency-Key per click on a gateway button; a double click reuses it. */
export function newIdempotencyKey(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return 'pay-' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

const rec = (v: unknown): Record<string, unknown> => (typeof v === 'object' && v !== null ? (v as Record<string, unknown>) : {});
const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const int = (v: unknown): number => (typeof v === 'number' && Number.isInteger(v) ? v : 0);
const currency = (v: unknown): Currency | null => (v === 'NGN' || v === 'USD' ? v : null);

function readPayment(data: unknown): Payment | null {
  const p = rec(rec(data).payment);
  const code = currency(p.currency);
  if (typeof p.id !== 'string' || typeof p.state !== 'string' || code === null) return null;
  return {
    id: p.id,
    state: p.state,
    gateway: str(p.gateway),
    amount: int(p.amount),
    currency: code,
    invoiceNumber: str(p.invoice_number),
    orderId: str(p.order_id),
    checkoutUrl: typeof p.checkout_url === 'string' ? p.checkout_url : null,
    failureReason: typeof p.failure_reason === 'string' ? p.failure_reason : null,
  };
}

export async function fetchPaymentOptions(orderId: string, fetchImpl: typeof fetch = fetch): Promise<PaymentOptions | null> {
  try {
    const response = await fetchImpl(`/api/v1/payments/options?order_id=${encodeURIComponent(orderId)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) return null;
    const o = rec(await response.json());
    const gateways = (Array.isArray(o.gateways) ? o.gateways : []).filter((g): g is Gateway => g === 'paystack' || g === 'flutterwave');
    const code = currency(o.currency);
    return code === null ? null : { invoiceNumber: str(o.invoice_number), status: str(o.status), amount: int(o.amount), currency: code, payable: o.payable === true, gateways };
  } catch {
    return null;
  }
}

export async function startPayment(
  csrfToken: string,
  orderId: string,
  gateway: Gateway,
  idempotencyKey: string,
  fetchImpl: typeof fetch = fetch,
): Promise<{ kind: 'ok'; paymentId: string } | { kind: 'error'; message: string }> {
  const result = await postJson('/api/v1/payments', { order_id: orderId, gateway }, fetchImpl, { 'X-CSRF-Token': csrfToken, 'Idempotency-Key': idempotencyKey });
  if (result.kind === 'ok') {
    const id = rec(result.data).payment_id;
    return typeof id === 'string' ? { kind: 'ok', paymentId: id } : { kind: 'error', message: 'The payment could not be started. Please try again.' };
  }
  if (result.kind === 'throttled') return { kind: 'error', message: 'Too many payment attempts. Please wait a few minutes and try again.' };
  if (result.kind === 'rejected') return { kind: 'error', message: START_ERRORS[result.code] ?? 'The payment could not be started. Please try again.' };
  return { kind: 'error', message: result.kind === 'unavailable' ? OFFLINE : 'The payment could not be started. Please try again.' };
}

export async function fetchPayment(id: string, fetchImpl: typeof fetch = fetch): Promise<Payment | 'not_found' | null> {
  try {
    const response = await fetchImpl(`/api/v1/payments/detail?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    if (response.status === 404) return 'not_found';
    if (!response.ok) return null;
    return readPayment(await response.json());
  } catch {
    return null;
  }
}

/** Back from the gateway: ask the server to check now (PY-10). The server limits this to once per 30 seconds. */
export async function requestPaymentCheck(csrfToken: string, id: string, fetchImpl: typeof fetch = fetch): Promise<void> {
  await postJson('/api/v1/payments/check', { payment_id: id }, fetchImpl, { 'X-CSRF-Token': csrfToken });
}

/** The return page's path for one attempt; the server sends the gateway back here (APP_URL/billing/payments/{id}). */
export const PAYMENT_RETURN_PATH = /^\/billing\/payments\/([a-f0-9]{32})$/;

export function paymentIdForPath(path: string): string | null {
  return PAYMENT_RETURN_PATH.exec(path)?.[1] ?? null;
}
