import { describeWait, postJson, type ApiResult } from './api';

/** One cart line (THREAT-MODEL-COMMERCE.md CD-20). Prices are a preview, re-quoted on every read. */
export interface CartLine {
  id: string;
  domain: string;
  available: boolean;
  register: { usd: number; ngn: number | null } | null;
}

export interface Cart {
  lines: CartLine[];
  total: { usd: number; ngn: number | null };
}

export type CartResult = { kind: 'ok'; cart: Cart } | { kind: 'error'; message: string };

const EMPTY: Cart = { lines: [], total: { usd: 0, ngn: 0 } };
const isMinor = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;

/** Keeps only well-formed lines; values are rendered as text. */
export function readCart(data: unknown): Cart {
  const body = typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
  const lines: CartLine[] = [];
  for (const raw of Array.isArray(body.lines) ? body.lines : []) {
    const l = typeof raw === 'object' && raw !== null ? (raw as Record<string, unknown>) : null;
    if (!l || typeof l.id !== 'string' || !/^[0-9a-f]{16}$/.test(l.id) || typeof l.domain !== 'string') continue;
    const r = typeof l.register === 'object' && l.register !== null ? (l.register as Record<string, unknown>) : null;
    const register = r && isMinor(r.usd) ? { usd: r.usd, ngn: isMinor(r.ngn) ? r.ngn : null } : null;
    lines.push({ id: l.id, domain: l.domain, available: l.available === true && register !== null, register });
  }
  const t = typeof body.total === 'object' && body.total !== null ? (body.total as Record<string, unknown>) : {};
  return { lines, total: { usd: isMinor(t.usd) ? t.usd : 0, ngn: isMinor(t.ngn) ? t.ngn : null } };
}

export async function fetchCart(fetchImpl: typeof fetch = fetch): Promise<CartResult> {
  try {
    const response = await fetchImpl('/api/v1/cart', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (response.status === 401) return { kind: 'ok', cart: EMPTY };
    if (!response.ok) return { kind: 'error', message: 'Your cart could not be loaded. Please try again.' };
    return { kind: 'ok', cart: readCart(await response.json()) };
  } catch {
    return { kind: 'error', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
}

const MESSAGES: Record<string, string> = {
  cart_full: 'Your cart is full (20 items). Remove one to add another.',
  not_for_sale: "We don't sell that extension right now.",
  invalid_name: 'That name cannot be registered.',
  line_not_found: 'That item was already removed.',
};

function outcome(result: ApiResult): CartResult {
  if (result.kind === 'ok') return { kind: 'ok', cart: readCart(result.data) };
  if (result.kind === 'throttled') return { kind: 'error', message: `Too many changes to your cart. Try again ${describeWait(result.retryAfterSeconds)}.` };
  if (result.kind === 'rejected') return { kind: 'error', message: MESSAGES[result.code] ?? 'Your cart could not be changed. Please try again.' };
  return { kind: 'error', message: 'Your cart could not be changed. Please try again.' };
}

export async function addDomainToCart(domain: string, csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<CartResult> {
  return outcome(await postJson('/api/v1/cart/domains', { domain }, fetchImpl, { 'X-CSRF-Token': csrfToken }));
}

export async function removeFromCart(id: string, csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<CartResult> {
  return outcome(await postJson('/api/v1/cart/remove', { id }, fetchImpl, { 'X-CSRF-Token': csrfToken }));
}
