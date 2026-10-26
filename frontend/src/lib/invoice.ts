import type { Currency } from './money';

export interface InvoiceLine {
  description: string;
  amount: number;
  tax: number;
  total: number;
}

export interface Invoice {
  number: string;
  status: string;
  currency: Currency;
  subtotal: number;
  tax: number;
  total: number;
  taxName: string;
  taxRateBp: number;
  taxCountry: string;
  issuedAt: string;
  dueAt: string;
  expiresAt: string;
  orderNumber: string;
  billTo: { name: string; company: string | null; line1: string; line2: string | null; city: string; region: string; postcode: string | null; country: string; phone: string; tin: string | null };
  lines: InvoiceLine[];
}

export const INVOICE_STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  unpaid: 'Unpaid',
  paid: 'Paid',
  partially_paid: 'Partly paid',
  cancelled: 'Cancelled',
  refunded: 'Refunded',
};

const rec = (v: unknown): Record<string, unknown> => (typeof v === 'object' && v !== null ? (v as Record<string, unknown>) : {});
const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const opt = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
const int = (v: unknown): number => (typeof v === 'number' && Number.isSafeInteger(v) ? v : 0);

export function readInvoice(data: unknown): Invoice | null {
  const i = rec(rec(data).invoice);
  if (typeof i.number !== 'string' || (i.currency !== 'NGN' && i.currency !== 'USD')) return null;
  const b = rec(i.bill_to);
  return {
    number: i.number,
    status: str(i.status),
    currency: i.currency,
    subtotal: int(i.subtotal),
    tax: int(i.tax),
    total: int(i.total),
    taxName: str(i.tax_name),
    taxRateBp: int(i.tax_rate_bp),
    taxCountry: str(i.tax_country),
    issuedAt: str(i.issued_at),
    dueAt: str(i.due_at),
    expiresAt: str(i.expires_at),
    orderNumber: str(i.order_number),
    billTo: {
      name: str(b.name),
      company: opt(b.company),
      line1: str(b.line1),
      line2: opt(b.line2),
      city: str(b.city),
      region: str(b.region),
      postcode: opt(b.postcode),
      country: str(b.country),
      phone: str(b.phone),
      tin: opt(b.tin),
    },
    lines: (Array.isArray(i.lines) ? i.lines : []).map((raw) => {
      const l = rec(raw);
      return { description: str(l.description), amount: int(l.amount), tax: int(l.tax), total: int(l.total) };
    }),
  };
}

export async function fetchInvoice(orderId: string, fetchImpl: typeof fetch = fetch): Promise<{ kind: 'ok'; invoice: Invoice } | { kind: 'error'; message: string }> {
  try {
    const response = await fetchImpl(`/api/v1/orders/invoice?id=${encodeURIComponent(orderId)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (response.status === 404) return { kind: 'error', message: 'This invoice was not found.' };
    if (!response.ok) return { kind: 'error', message: 'The invoice could not be loaded. Please try again.' };
    const invoice = readInvoice(await response.json());
    return invoice ? { kind: 'ok', invoice } : { kind: 'error', message: 'The invoice could not be loaded. Please try again.' };
  } catch {
    return { kind: 'error', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
}
