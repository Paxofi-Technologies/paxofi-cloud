import { useEffect, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, Checkbox } from '../components';
import type { Tone } from '../components/Badge';
import { formatRate } from '../lib/billing';
import { fetchCart, removeFromCart } from '../lib/cart';
import { fetchReview, newIdempotencyKey, placeOrder, startReview, type LineStatus, type Review } from '../lib/checkout';
import { formatMoney } from '../lib/money';

export interface CheckoutScreenProps {
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  onBack: () => void;
  onBilling: () => void;
  /** Called when the cart changed here (a removal, or ordered names leaving it). */
  onCartChange?: () => void;
  /** Polling interval; tests shorten it. */
  pollMs?: number;
}

const STATUS: Record<LineStatus, { tone: Tone; label: string; note?: string }> = {
  checking: { tone: 'info', label: 'Checking…' },
  available: { tone: 'success', label: 'Available' },
  taken: { tone: 'danger', label: 'Taken', note: 'Someone registered this name. Remove it to continue.' },
  unknown: { tone: 'warning', label: "Couldn't confirm", note: "The registry didn't answer. Remove it or try again in a few minutes." },
  premium: { tone: 'warning', label: 'Premium', note: 'Premium names are not sold yet. Remove it to continue.' },
  not_for_sale: { tone: 'neutral', label: 'Not for sale', note: "We don't sell this extension right now. Remove it to continue." },
};

/**
 * Checkout (CRT-003, CRT-005, CMP-010): the server builds the review from the
 * cart, checks every name with the registry and holds the price for 30
 * minutes. The customer sees renewal prices and that auto-renew is off, then
 * places the order once (one idempotency key per attempt).
 */
export function CheckoutScreen({ csrfToken, fetchImpl, onBack, onBilling, onCartChange, pollMs = 2000 }: CheckoutScreenProps) {
  const [review, setReview] = useState<Review | null>(null);
  const [error, setError] = useState<{ code: string; message: string } | null>(null);
  const [terms, setTerms] = useState(false);
  const [confirmCountry, setConfirmCountry] = useState(false);
  const [placing, setPlacing] = useState(false);
  const [placed, setPlaced] = useState<{ orderNumber: string; invoiceNumber: string } | null>(null);
  const [attempt, setAttempt] = useState(0);
  const key = useRef(newIdempotencyKey());
  const started = useRef(-1);

  useEffect(() => {
    // StrictMode runs effects twice in development; start each attempt once.
    if (started.current === attempt) return;
    started.current = attempt;
    let active = true;
    let timer: ReturnType<typeof setTimeout> | undefined;
    key.current = newIdempotencyKey();
    setReview(null);
    setError(null);
    void startReview(csrfToken, fetchImpl).then(function follow(result): void {
      if (!active) return;
      if (result.kind === 'error') {
        setError(result);
        return;
      }
      setReview(result.review);
      if (result.review.status === 'pending') {
        timer = setTimeout(() => void fetchReview(result.review.id, fetchImpl).then(follow), pollMs);
      }
    });
    return () => {
      active = false;
      if (timer) clearTimeout(timer);
    };
  }, [attempt, csrfToken, fetchImpl, pollMs]);

  async function remove(domain: string) {
    const cart = await fetchCart(fetchImpl);
    const line = cart.kind === 'ok' ? cart.cart.lines.find((l) => l.domain === domain) : undefined;
    if (line) await removeFromCart(line.id, csrfToken, fetchImpl);
    onCartChange?.();
    setAttempt((n) => n + 1);
  }

  async function submit() {
    if (!review) return;
    setPlacing(true);
    const result = await placeOrder(review.id, terms, review.confirmBillingCountry ? confirmCountry : null, key.current, csrfToken, fetchImpl);
    setPlacing(false);
    if (result.kind === 'ok') {
      setPlaced({ orderNumber: result.orderNumber, invoiceNumber: result.invoiceNumber });
      onCartChange?.();
    } else {
      setError(result);
    }
  }

  const money = (minor: number | null) => (minor === null || !review ? '—' : formatMoney({ minor, currency: review.currency }));

  if (placed) {
    return (
      <div className="pc-stack">
        <div className="pc-page-header">
          <h1>Order placed</h1>
        </div>
        <Alert tone="success" title={`Order ${placed.orderNumber}`}>
          Invoice {placed.invoiceNumber} is waiting for payment. Online payment opens soon: nothing is charged and no domain is registered until you pay. The
          price is held for 72 hours.
        </Alert>
        <div className="pc-row">
          <Button onClick={onBilling}>View your orders</Button>
          <Button variant="secondary" onClick={onBack}>
            Find more domains
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="pc-stack">
      <div className="pc-page-header">
        <div>
          <h1>Checkout</h1>
          <p className="pc-muted">We check every name with the registry and hold these prices for 30 minutes.</p>
        </div>
        <Button variant="secondary" onClick={onBack}>
          Back to domains
        </Button>
      </div>

      {error && (
        <Alert
          tone="danger"
          action={
            error.code === 'billing_details_required' ? (
              <Button size="sm" variant="secondary" onClick={onBilling}>
                Add billing details
              </Button>
            ) : error.code === 'cart_empty' ? undefined : (
              <Button size="sm" variant="secondary" onClick={() => setAttempt((n) => n + 1)}>
                Review again
              </Button>
            )
          }
        >
          {error.message}
        </Alert>
      )}

      {!review && !error && (
        <p className="pc-muted" role="status">
          Preparing your order…
        </p>
      )}

      {review && (
        <Card title="Your order">
          {review.status === 'pending' && (
            <p className="pc-muted" role="status">
              Checking availability with the registry…
            </p>
          )}
          <div className="pc-table-scroll">
          <table className="pc-table">
            <thead>
              <tr>
                <th scope="col">Domain</th>
                <th scope="col">Status</th>
                <th scope="col" className="pc-num">
                  1 year
                </th>
                <th scope="col" className="pc-num">
                  Renews at
                </th>
                <th scope="col">
                  <span className="pc-visually-hidden">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {review.lines.map((line) => {
                const s = STATUS[line.status];
                return (
                  <tr key={line.domain}>
                    <td>
                      <strong>{line.domain}</strong>
                      {s.note && <p className="pc-small pc-muted">{s.note}</p>}
                    </td>
                    <td>
                      <Badge tone={s.tone}>{s.label}</Badge>
                    </td>
                    <td className="pc-num">{money(line.price)}</td>
                    <td className="pc-num">{line.renewalPrice === null ? '—' : `${money(line.renewalPrice)}/yr`}</td>
                    <td className="pc-num">
                      {line.status !== 'available' && line.status !== 'checking' && (
                        <Button size="sm" variant="secondary" onClick={() => void remove(line.domain)}>
                          Remove
                        </Button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          </div>

          <dl className="pc-totals">
            <div>
              <dt>Subtotal</dt>
              <dd>{money(review.subtotal)}</dd>
            </div>
            <div>
              <dt>
                {review.tax.name} ({formatRate(review.tax.rateBp)})
              </dt>
              <dd>{money(review.tax.amount)}</dd>
            </div>
            <div className="pc-totals__total">
              <dt>Total due</dt>
              <dd>{money(review.total)}</dd>
            </div>
          </dl>
          <p className="pc-small pc-muted">
            Renewal prices are before tax and may change; we tell you before any renewal. Auto-renew is off: you renew each domain yourself.
          </p>

          {review.status === 'ready' && !review.blocked && (
            <div className="pc-stack">
              {review.confirmBillingCountry && (
                <Checkbox
                  label={`I confirm my billing address is in ${review.billingCountry}. Orders like this are reviewed before they are set up.`}
                  checked={confirmCountry}
                  onChange={(e) => setConfirmCountry(e.target.checked)}
                />
              )}
              <Checkbox
                label={`I agree to the PaxofiCloud Terms of Service (version ${review.termsVersion}).`}
                checked={terms}
                onChange={(e) => setTerms(e.target.checked)}
              />
              <div className="pc-row">
                <Button onClick={() => void submit()} disabled={placing || !terms || (review.confirmBillingCountry && !confirmCountry)}>
                  {placing ? 'Placing order…' : `Place order · ${money(review.total)}`}
                </Button>
              </div>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
