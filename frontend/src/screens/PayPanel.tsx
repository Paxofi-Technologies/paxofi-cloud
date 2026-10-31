import { useEffect, useRef, useState } from 'react';
import { Alert, Button } from '../components';
import { formatMoney } from '../lib/money';
import {
  fetchPayment,
  fetchPaymentOptions,
  FAILURE_TEXT,
  GATEWAY_LABELS,
  isCheckoutUrl,
  newIdempotencyKey,
  startPayment,
  type Gateway,
  type PaymentOptions,
} from '../lib/payments';

export interface PayPanelProps {
  orderId: string;
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  /** Full-page navigation to the gateway; tests replace it. */
  navigate?: (url: string) => void;
  /** Milliseconds between checks while the gateway page is prepared. */
  pollMs?: number;
}

const POLL_LIMIT = 30;
const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Pay an unpaid invoice (PY-5, PY-6, PY-17). Offers only the gateways the
 * server lists for this invoice, starts an attempt with a fresh
 * Idempotency-Key, then waits for the server to have the gateway's checkout
 * page and goes there. Card details are entered on the gateway's page only.
 */
export function PayPanel({ orderId, csrfToken, fetchImpl, navigate = (url) => window.location.assign(url), pollMs = 1000 }: PayPanelProps) {
  const [options, setOptions] = useState<PaymentOptions | null | 'error'>(null);
  const [busy, setBusy] = useState<Gateway | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const active = useRef(true);

  useEffect(() => {
    active.current = true;
    void fetchPaymentOptions(orderId, fetchImpl).then((o) => {
      if (active.current) setOptions(o ?? 'error');
    });
    return () => {
      active.current = false;
    };
  }, [orderId, fetchImpl]);

  async function pay(gateway: Gateway) {
    if (busy !== null) return;
    setBusy(gateway);
    setProblem(null);
    const started = await startPayment(csrfToken, orderId, gateway, newIdempotencyKey(), fetchImpl);
    if (started.kind === 'error') {
      if (active.current) {
        setProblem(started.message);
        setBusy(null);
      }
      return;
    }
    for (let i = 0; i < POLL_LIMIT && active.current; i++) {
      const payment = await fetchPayment(started.paymentId, fetchImpl);
      if (payment !== null && payment !== 'not_found') {
        if (payment.state === 'pending' && payment.checkoutUrl !== null && isCheckoutUrl(payment.checkoutUrl)) {
          navigate(payment.checkoutUrl);
          return;
        }
        if (payment.state === 'failed') {
          const reason = payment.failureReason !== null ? FAILURE_TEXT[payment.failureReason] : undefined;
          setProblem(`${GATEWAY_LABELS[gateway]} could not open its payment page.${reason ? ` ${reason}` : ''} Try again or choose another payment method.`);
          setBusy(null);
          return;
        }
      }
      await wait(pollMs);
    }
    if (active.current) {
      setProblem('The payment page is taking longer than usual. Please try again in a minute.');
      setBusy(null);
    }
  }

  if (options === null)
    return (
      <p className="pc-muted" role="status">
        Loading payment options…
      </p>
    );
  if (options === 'error') return <Alert tone="danger">Payment options could not be loaded. Please try again.</Alert>;
  if (!options.payable) return null;

  return (
    <section className="pc-pay" aria-label="Pay this invoice">
      <h4>Pay {formatMoney({ minor: options.amount, currency: options.currency })}</h4>
      {problem && <Alert tone="danger">{problem}</Alert>}
      {options.gateways.length === 0 ? (
        <p className="pc-muted">Online payment is not available for this invoice yet. Contact support to pay.</p>
      ) : (
        <>
          <p className="pc-muted pc-small">You will pay on the payment provider’s secure page. We never see your card details.</p>
          <div className="pc-pay__actions">
            {options.gateways.map((gateway) => (
              <Button key={gateway} onClick={() => void pay(gateway)} disabled={busy !== null && busy !== gateway} loading={busy === gateway}>
                {busy === gateway ? 'Opening payment page…' : `Pay with ${GATEWAY_LABELS[gateway]}`}
              </Button>
            ))}
          </div>
        </>
      )}
    </section>
  );
}
