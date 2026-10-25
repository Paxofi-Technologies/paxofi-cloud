import { describeWait, postJson } from './api';
import type { Currency } from './money';

/** Checkout and orders (THREAT-MODEL-COMMERCE.md CD-23…CD-26). Only server figures are shown; nothing here sends a price. */
export type LineStatus = 'checking' | 'available' | 'taken' | 'unknown' | 'premium' | 'not_for_sale';

export interface ReviewLine {
  domain: string;
  status: LineStatus;
  price: number | null;
  tax: number | null;
  total: number | null;
  renewalPrice: number | null;
}

export interface Review {
  id: string;
  status: 'pending' | 'ready';
  currency: Currency;
  lines: ReviewLine[];
  subtotal: number;
  tax: { name: string; rateBp: number; amount: number };
  total: number;
  blocked: boolean;
  confirmBillingCountry: boolean;
  billingCountry: string;
  termsVersion: string;
  expiresAt: string;
}

export interface OrderLine {
  domain: string;
  years: number;
  price: number;
  tax: number;
  total: number;
  renewalPrice: number;
}

export interface Order {
  id: string;
  number: string;
  status: string;
  currency: Currency;
  subtotal: number;
  tax: number;
  total: number;
  taxName: string;
  taxRateBp: number;
  placedAt: string;
  invoiceNumber: string;
  invoiceStatus: string;
  lines: OrderLine[];
}

export type ReviewResult = { kind: 'ok'; review: Review } | { kind: 'error'; code: string; message: string };
export type PlaceResult = { kind: 'ok'; orderId: string; orderNumber: string; invoiceNumber: string } | { kind: 'error'; code: string; message: string };

const STATUSES: readonly LineStatus[] = ['checking', 'available', 'taken', 'unknown', 'premium', 'not_for_sale'];
const int = (v: unknown): number | null => (typeof v === 'number' && Number.isSafeInteger(v) ? v : null);
const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const rec = (v: unknown): Record<string, unknown> => (typeof v === 'object' && v !== null ? (v as Record<string, unknown>) : {});

export function readReview(data: unknown): Review | null {
  const b = rec(data);
  const tax = rec(b.tax);
  if (typeof b.id !== 'string' || !/^[a-f0-9]{32}$/.test(b.id) || (b.currency !== 'NGN' && b.currency !== 'USD')) return null;
  return {
    id: b.id,
    status: b.status === 'ready' ? 'ready' : 'pending',
    currency: b.currency,
    lines: (Array.isArray(b.lines) ? b.lines : []).map((raw) => {
      const l = rec(raw);
      const status = STATUSES.includes(l.status as LineStatus) ? (l.status as LineStatus) : 'unknown';
      return { domain: str(l.domain), status, price: int(l.price), tax: int(l.tax), total: int(l.total), renewalPrice: int(l.renewal_price) };
    }),
    subtotal: int(b.subtotal) ?? 0,
    tax: { name: str(tax.name), rateBp: int(tax.rate_bp) ?? 0, amount: int(tax.amount) ?? 0 },
    total: int(b.total) ?? 0,
    blocked: b.blocked === true,
    confirmBillingCountry: b.confirm_billing_country === true,
    billingCountry: str(b.billing_country),
    termsVersion: str(b.terms_version),
    expiresAt: str(b.expires_at),
  };
}

export function readOrder(data: unknown): Order | null {
  const o = rec(data);
  if (typeof o.id !== 'string' || !/^[a-f0-9]{32}$/.test(o.id) || (o.currency !== 'NGN' && o.currency !== 'USD')) return null;
  return {
    id: o.id,
    number: str(o.number),
    status: str(o.status),
    currency: o.currency,
    subtotal: int(o.subtotal) ?? 0,
    tax: int(o.tax) ?? 0,
    total: int(o.total) ?? 0,
    taxName: str(o.tax_name),
    taxRateBp: int(o.tax_rate_bp) ?? 0,
    placedAt: str(o.placed_at),
    invoiceNumber: str(o.invoice_number),
    invoiceStatus: str(o.invoice_status),
    lines: (Array.isArray(o.lines) ? o.lines : []).map((raw) => {
      const l = rec(raw);
      return { domain: str(l.domain), years: int(l.years) ?? 1, price: int(l.price) ?? 0, tax: int(l.tax) ?? 0, total: int(l.total) ?? 0, renewalPrice: int(l.renewal_price) ?? 0 };
    }),
  };
}

const MESSAGES: Record<string, string> = {
  cart_empty: 'Your cart is empty.',
  billing_details_required: 'Add your billing details first: they go on your invoice and decide your currency and tax.',
  not_account_owner: 'Only the account owner can place orders.',
  review_not_found: 'This checkout has expired. Start again to see current prices.',
  review_pending: 'We are still checking your domains with the registry. One moment.',
  review_blocked: 'Remove the domains that cannot be registered, then review again.',
  terms_not_accepted: 'Please accept the Terms of Service.',
  billing_country_confirmation_required: 'Please confirm your billing country.',
  price_changed: 'A price changed since you started. Review your order again to see the new total.',
  idempotency_key_reused: 'This order was already submitted with different details. Start checkout again.',
  checkout_busy: 'Checkout is busy right now. Please try again in a moment.',
};

function failure(result: Awaited<ReturnType<typeof postJson>>): { kind: 'error'; code: string; message: string } {
  if (result.kind === 'throttled') return { kind: 'error', code: 'too_many_requests', message: `Too many attempts. Try again ${describeWait(result.retryAfterSeconds)}.` };
  if (result.kind === 'rejected') return { kind: 'error', code: result.code, message: MESSAGES[result.code] ?? 'Checkout could not continue. Please try again.' };
  if (result.kind === 'unavailable') return { kind: 'error', code: 'unavailable', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  return { kind: 'error', code: 'invalid', message: 'Checkout could not continue. Please try again.' };
}

export async function startReview(csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<ReviewResult> {
  const result = await postJson('/api/v1/checkout/review', {}, fetchImpl, { 'X-CSRF-Token': csrfToken });
  if (result.kind === 'ok') {
    const review = readReview(result.data);
    return review ? { kind: 'ok', review } : { kind: 'error', code: 'invalid', message: 'Checkout could not continue. Please try again.' };
  }
  return failure(result);
}

export async function fetchReview(id: string, fetchImpl: typeof fetch = fetch): Promise<ReviewResult> {
  try {
    const response = await fetchImpl(`/api/v1/checkout/review?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (response.status === 404) return { kind: 'error', code: 'review_not_found', message: MESSAGES.review_not_found ?? '' };
    if (!response.ok) return { kind: 'error', code: `http_${response.status}`, message: 'Checkout could not continue. Please try again.' };
    const review = readReview(await response.json());
    return review ? { kind: 'ok', review } : { kind: 'error', code: 'invalid', message: 'Checkout could not continue. Please try again.' };
  } catch {
    return { kind: 'error', code: 'unavailable', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
}

export async function placeOrder(
  reviewId: string,
  acceptTerms: boolean,
  confirmBillingCountry: boolean | null,
  idempotencyKey: string,
  csrfToken: string,
  fetchImpl: typeof fetch = fetch,
): Promise<PlaceResult> {
  const body: Record<string, unknown> = { review_id: reviewId, accept_terms: acceptTerms };
  if (confirmBillingCountry !== null) body.confirm_billing_country = confirmBillingCountry;
  const result = await postJson('/api/v1/checkout/orders', body, fetchImpl, { 'X-CSRF-Token': csrfToken, 'Idempotency-Key': idempotencyKey });
  if (result.kind === 'ok') {
    const d = rec(result.data);
    return { kind: 'ok', orderId: str(d.order_id), orderNumber: str(d.order_number), invoiceNumber: str(d.invoice_number) };
  }
  return failure(result);
}

export async function fetchOrders(page = 1, fetchImpl: typeof fetch = fetch): Promise<{ kind: 'ok'; orders: Order[]; hasMore: boolean } | { kind: 'error'; message: string }> {
  try {
    const response = await fetchImpl(`/api/v1/orders?page=${page}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) return { kind: 'error', message: 'Your orders could not be loaded. Please try again.' };
    const body = rec(await response.json());
    const orders = (Array.isArray(body.orders) ? body.orders : []).map(readOrder).filter((o): o is Order => o !== null);
    return { kind: 'ok', orders, hasMore: body.has_more === true };
  } catch {
    return { kind: 'error', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
}

/** A fresh key per checkout attempt; repeated clicks reuse it, so the server makes one order (CRT-005). */
export function newIdempotencyKey(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending_payment: 'Awaiting payment',
  paid: 'Paid',
  cancelled: 'Cancelled',
  expired: 'Expired',
  fraud_review: 'Under review',
  fulfilling: 'Setting up',
  fulfilled: 'Complete',
  partially_fulfilled: 'Partly complete',
  failed: 'Failed',
};
