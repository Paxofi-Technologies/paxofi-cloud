import { useEffect, useState } from 'react';
import { Alert, Badge, Button } from '../components';
import { countryName } from '../lib/activity';
import { formatRate } from '../lib/billing';
import { fetchInvoice, INVOICE_STATUS_LABELS, type Invoice } from '../lib/invoice';
import { formatMoney } from '../lib/money';
import { PayPanel } from './PayPanel';

const day = (iso: string): string => {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('en-NG', { dateStyle: 'medium', timeZone: 'Africa/Lagos' });
};

/**
 * One order's invoice (INV-001): the billing snapshot taken when the order was
 * placed, the lines, tax and totals. Text only; printable. An unpaid invoice
 * shows when it expires and how to pay it (PY-6); the pay buttons never print.
 */
export function InvoiceView({
  orderId,
  csrfToken,
  fetchImpl,
  navigate,
}: {
  orderId: string;
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  navigate?: (url: string) => void;
}) {
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void fetchInvoice(orderId, fetchImpl).then((r) => {
      if (!active) return;
      if (r.kind === 'ok') setInvoice(r.invoice);
      else setError(r.message);
    });
    return () => {
      active = false;
    };
  }, [orderId, fetchImpl]);

  if (error) return <Alert tone="danger">{error}</Alert>;
  if (invoice === null)
    return (
      <p className="pc-muted" role="status">
        Loading invoice…
      </p>
    );

  const money = (minor: number) => formatMoney({ minor, currency: invoice.currency });
  const b = invoice.billTo;

  return (
    <article className="pc-invoice" aria-label={`Invoice ${invoice.number}`}>
      <header className="pc-invoice__head">
        <div>
          <p className="pc-invoice__brand">PaxofiCloud</p>
          <p className="pc-muted">Paxofi Technologies LTD</p>
        </div>
        <div className="pc-invoice__meta">
          <h3>Invoice {invoice.number}</h3>
          <Badge tone={invoice.status === 'paid' ? 'success' : invoice.status === 'unpaid' ? 'warning' : 'neutral'}>{INVOICE_STATUS_LABELS[invoice.status] ?? invoice.status}</Badge>
          <dl>
            <dt>Order</dt>
            <dd>{invoice.orderNumber}</dd>
            <dt>Issued</dt>
            <dd>{day(invoice.issuedAt)}</dd>
            <dt>Due</dt>
            <dd>{day(invoice.dueAt)}</dd>
            {invoice.status === 'unpaid' && (
              <>
                <dt>Expires</dt>
                <dd>{day(invoice.expiresAt)}</dd>
              </>
            )}
          </dl>
        </div>
      </header>

      <section className="pc-invoice__billto" aria-label="Billed to">
        <h4>Billed to</h4>
        <p>
          {b.name}
          {b.company && (
            <>
              <br />
              {b.company}
            </>
          )}
          <br />
          {b.line1}
          {b.line2 && (
            <>
              <br />
              {b.line2}
            </>
          )}
          <br />
          {[b.city, b.region, b.postcode].filter((x) => x).join(', ')}
          <br />
          {countryName(b.country)}
          <br />
          {b.phone}
          {b.tin && (
            <>
              <br />
              TIN: {b.tin}
            </>
          )}
        </p>
      </section>

      <div className="pc-table-scroll">
        <table className="pc-table" aria-label="Invoice lines">
          <thead>
            <tr>
              <th scope="col">Description</th>
              <th scope="col" className="pc-num">
                Amount
              </th>
              <th scope="col" className="pc-num">
                {invoice.taxName}
              </th>
              <th scope="col" className="pc-num">
                Total
              </th>
            </tr>
          </thead>
          <tbody>
            {invoice.lines.map((l, i) => (
              <tr key={i}>
                <td>{l.description}</td>
                <td className="pc-num">{money(l.amount)}</td>
                <td className="pc-num">{money(l.tax)}</td>
                <td className="pc-num">{money(l.total)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <dl className="pc-invoice__totals">
        <dt>Subtotal</dt>
        <dd>{money(invoice.subtotal)}</dd>
        <dt>
          {invoice.taxName} ({formatRate(invoice.taxRateBp)}, {countryName(invoice.taxCountry)})
        </dt>
        <dd>{money(invoice.tax)}</dd>
        <dt>Total</dt>
        <dd>
          <strong>{money(invoice.total)}</strong>
        </dd>
      </dl>

      {invoice.status === 'unpaid' && <PayPanel orderId={orderId} csrfToken={csrfToken} fetchImpl={fetchImpl} {...(navigate ? { navigate } : {})} />}

      <div className="pc-invoice__actions">
        <Button variant="secondary" onClick={() => window.print()}>
          Print or save as PDF
        </Button>
      </div>
    </article>
  );
}
