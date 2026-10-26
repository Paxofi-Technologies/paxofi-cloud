import { useEffect, useId, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Modal, TextField } from '../components';
import { fetchOrders, ORDER_STATUS_LABELS, type Order } from '../lib/checkout';
import { formatMoney } from '../lib/money';
import { InvoiceView } from './InvoiceView';
import { BILLING_FIELDS, EMPTY_DETAILS, fetchBilling, formatRate, saveBilling, type BillingDetails, type BillingField, type BillingState } from '../lib/billing';

export interface BillingScreenProps {
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
}

const LABELS: Record<BillingField, string> = {
  full_name: 'Full name',
  company: 'Company (optional)',
  address_line1: 'Address',
  address_line2: 'Address line 2 (optional)',
  city: 'City',
  region: 'State or region',
  postcode: 'Postcode (optional)',
  country: 'Country',
  phone: 'Phone',
  tin: 'Tax ID (TIN, optional)',
};

/** Billing details (SRS ACC-004): the name and address printed on invoices, and the account's billing currency. */
export function BillingScreen({ csrfToken, fetchImpl }: BillingScreenProps) {
  const [billing, setBilling] = useState<BillingState | null>(null);
  const [form, setForm] = useState<BillingDetails>(EMPTY_DETAILS);
  const [errors, setErrors] = useState<Partial<Record<BillingField | 'form', string>>>({});
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const countryId = useId();

  useEffect(() => {
    let active = true;
    void fetchBilling(fetchImpl).then((result) => {
      if (!active) return;
      if (result.kind === 'ok') {
        setBilling(result.billing);
        setForm(result.billing.details ?? EMPTY_DETAILS);
      } else if (result.kind === 'error') {
        setMessage({ tone: 'danger', text: result.message });
      }
    });
    return () => {
      active = false;
    };
  }, [fetchImpl]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    const result = await saveBilling(form, csrfToken, fetchImpl);
    setSaving(false);
    if (result.kind === 'ok') {
      setBilling(result.billing);
      setForm(result.billing.details ?? form);
      setErrors({});
      setMessage({ tone: 'success', text: 'Billing details saved.' });
    } else if (result.kind === 'invalid') {
      setErrors(result.errors);
      setMessage({ tone: 'danger', text: 'Please correct the highlighted fields.' });
    } else {
      setMessage({ tone: 'danger', text: result.message });
    }
  }

  const set = (field: BillingField) => (value: string) => setForm((f) => ({ ...f, [field]: value }));

  return (
    <div className="pc-stack">
      <div className="pc-page-header">
        <div>
          <h1>Billing</h1>
          <p className="pc-muted">The name and address on your invoices, and the currency you pay in.</p>
        </div>
      </div>

      {message && <Alert tone={message.tone}>{message.text}</Alert>}

      {billing?.currency && (
        <Card>
          <div className="pc-row-between">
            <div>
              <p className="pc-muted">Billing currency</p>
              <p>
                <strong>{billing.currency === 'NGN' ? 'Nigerian naira (NGN)' : 'US dollar (USD)'}</strong>{' '}
                {billing.currencyLocked ? <Badge tone="neutral">Fixed</Badge> : null}
              </p>
            </div>
            {billing.tax && (
              <div>
                <p className="pc-muted">Tax on your orders</p>
                <p>
                  <strong>
                    {billing.tax.name} {formatRate(billing.tax.rateBp)}
                  </strong>
                </p>
              </div>
            )}
          </div>
          <p className="pc-muted">
            {billing.currencyLocked
              ? 'Your currency is fixed by your first invoice. Contact support if you need to change it.'
              : 'Accounts in Nigeria pay in naira; everyone else pays in US dollars. Your first invoice fixes the currency.'}
          </p>
        </Card>
      )}

      <OrdersCard fetchImpl={fetchImpl} />

      <Card title="Billing details">
        {billing === null && message === null ? (
          <p className="pc-muted" role="status">
            Loading…
          </p>
        ) : (
          <form onSubmit={(e) => void submit(e)} noValidate>
            <div className="pc-form-grid">
              {BILLING_FIELDS.filter((f) => f !== 'country').map((field) => (
                <TextField
                  key={field}
                  label={LABELS[field]}
                  value={form[field]}
                  onChange={(e) => set(field)(e.target.value)}
                  error={errors[field]}
                  autoComplete={AUTOCOMPLETE[field]}
                  maxLength={MAX[field]}
                  required={!field.endsWith('2') && !OPTIONAL.has(field)}
                  {...(field === 'phone' ? { type: 'tel', hint: 'International format, e.g. +2348012345678' } : {})}
                  className={field === 'address_line1' || field === 'address_line2' ? 'pc-form-grid__wide' : undefined}
                />
              ))}
              <div className={['pc-field', errors.country ? 'pc-field--invalid' : ''].filter(Boolean).join(' ')}>
                <label className="pc-field__label" htmlFor={countryId}>
                  {LABELS.country}
                </label>
                <select id={countryId} className="pc-input" value={form.country} onChange={(e) => set('country')(e.target.value)} autoComplete="country" required>
                  <option value="">Choose a country</option>
                  {(billing?.countries ?? []).map((c) => (
                    <option key={c.code} value={c.code}>
                      {c.name}
                    </option>
                  ))}
                </select>
                {errors.country && <p className="pc-field__error">{errors.country}</p>}
              </div>
            </div>
            {errors.form && <Alert tone="danger">{errors.form}</Alert>}
            <div className="pc-row pc-form-actions">
              <Button type="submit" disabled={saving}>
                {saving ? 'Saving…' : 'Save billing details'}
              </Button>
            </div>
          </form>
        )}
      </Card>
    </div>
  );
}

/** The account's orders (ORD-004): snapshot totals, newest first. */
function OrdersCard({ fetchImpl }: { fetchImpl?: typeof fetch | undefined }) {
  const [orders, setOrders] = useState<Order[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [viewing, setViewing] = useState<Order | null>(null);

  useEffect(() => {
    let active = true;
    void fetchOrders(1, fetchImpl).then((r) => {
      if (!active) return;
      if (r.kind === 'ok') setOrders(r.orders);
      else setError(r.message);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl]);

  return (
    <Card title="Orders">
      {error && <Alert tone="danger">{error}</Alert>}
      {orders === null && !error && (
        <p className="pc-muted" role="status">
          Loading…
        </p>
      )}
      {orders?.length === 0 && <p className="pc-muted">No orders yet.</p>}
      {orders && orders.length > 0 && (
        <div className="pc-table-scroll">
          <table className="pc-table">
            <thead>
              <tr>
                <th scope="col">Order</th>
                <th scope="col">Placed</th>
                <th scope="col">Items</th>
                <th scope="col">Status</th>
                <th scope="col">Invoice</th>
                <th scope="col" className="pc-num">
                  Total
                </th>
                <th scope="col">
                  <span className="pc-visually-hidden">Invoice</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {orders.map((o) => (
                <tr key={o.id}>
                  <td>
                    <strong>{o.number}</strong>
                  </td>
                  <td>{new Date(o.placedAt).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Lagos' })}</td>
                  <td>{o.lines.map((l) => l.domain).join(', ')}</td>
                  <td>
                    <Badge tone={o.status === 'pending_payment' ? 'warning' : o.status === 'fulfilled' ? 'success' : 'neutral'}>{ORDER_STATUS_LABELS[o.status] ?? o.status}</Badge>
                  </td>
                  <td>{o.invoiceNumber}</td>
                  <td className="pc-num">{formatMoney({ minor: o.total, currency: o.currency })}</td>
                  <td>
                    <Button variant="secondary" onClick={() => setViewing(o)} aria-haspopup="dialog" aria-label={`View invoice ${o.invoiceNumber}`}>
                      View invoice
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Modal open={viewing !== null} title={viewing ? `Invoice ${viewing.invoiceNumber}` : 'Invoice'} size="lg" onClose={() => setViewing(null)}>
        {viewing && <InvoiceView key={viewing.id} orderId={viewing.id} fetchImpl={fetchImpl} />}
      </Modal>
    </Card>
  );
}

const OPTIONAL = new Set<BillingField>(['company', 'address_line2', 'postcode', 'tin']);
const MAX: Record<BillingField, number> = { full_name: 100, company: 100, address_line1: 120, address_line2: 120, city: 80, region: 80, postcode: 20, country: 2, phone: 16, tin: 32 };
const AUTOCOMPLETE: Record<BillingField, string> = {
  full_name: 'name',
  company: 'organization',
  address_line1: 'address-line1',
  address_line2: 'address-line2',
  city: 'address-level2',
  region: 'address-level1',
  postcode: 'postal-code',
  country: 'country',
  phone: 'tel',
  tin: 'off',
};
