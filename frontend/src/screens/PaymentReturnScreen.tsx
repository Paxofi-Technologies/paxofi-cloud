import { useEffect, useState } from 'react';
import { Alert, Button, Card } from '../components';
import { formatMoney } from '../lib/money';
import { fetchPayment, GATEWAY_LABELS, requestPaymentCheck, type Gateway, type Payment } from '../lib/payments';

export interface PaymentReturnScreenProps {
  paymentId: string;
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  onBilling: () => void;
  /** Milliseconds between status checks. */
  pollMs?: number;
}

const POLL_LIMIT = 40;

/**
 * Where the gateway sends the customer back (THREAT-MODEL-PAYMENTS.md PY-10,
 * PT-05). It shows the server's state only: the gateway's query parameters
 * (`status`, `reference`, `tx_ref`, `transaction_id`) are never read. On
 * arrival it asks the server for one early check, then follows the attempt
 * until the payments worker has the gateway's answer.
 */
export function PaymentReturnScreen({ paymentId, csrfToken, fetchImpl, onBilling, pollMs = 3000 }: PaymentReturnScreenProps) {
  const [payment, setPayment] = useState<Payment | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [gaveUp, setGaveUp] = useState(false);

  useEffect(() => {
    let active = true;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let polls = 0;

    const poll = async () => {
      const next = await fetchPayment(paymentId, fetchImpl);
      if (!active) return;
      if (next === 'not_found') {
        setNotFound(true);
        return;
      }
      if (next !== null) setPayment(next);
      polls++;
      if (next !== null && !['initiated', 'pending', 'unknown'].includes(next.state)) return;
      if (polls >= POLL_LIMIT) {
        setGaveUp(true);
        return;
      }
      timer = setTimeout(() => void poll(), pollMs);
    };

    void requestPaymentCheck(csrfToken, paymentId, fetchImpl).finally(() => {
      if (active) void poll();
    });

    return () => {
      active = false;
      if (timer !== undefined) clearTimeout(timer);
    };
  }, [paymentId, csrfToken, fetchImpl, pollMs]);

  const back = (
    <Button variant="secondary" onClick={onBilling}>
      Go to Billing
    </Button>
  );

  if (notFound)
    return (
      <Card title="Payment">
        <Alert tone="danger">We could not find this payment.</Alert>
        {back}
      </Card>
    );
  if (payment === null)
    return (
      <Card title="Payment">
        <p className="pc-muted" role="status">
          Checking your payment…
        </p>
      </Card>
    );

  const amount = formatMoney({ minor: payment.amount, currency: payment.currency });
  const via = GATEWAY_LABELS[payment.gateway as Gateway] ?? payment.gateway;

  return (
    <Card title={`Payment for invoice ${payment.invoiceNumber}`}>
      <div className="pc-stack" role="status" aria-live="polite">
        {payment.state === 'succeeded' && <Alert tone="success">Payment received: {amount} via {via}. Thank you. Your order is now being processed.</Alert>}
        {['initiated', 'pending', 'unknown'].includes(payment.state) &&
          (gaveUp ? (
            <Alert tone="info">
              We have not had confirmation from {via} yet. If you completed the payment, it will show on your invoice as soon as {via} confirms it; you do not need to pay again.
            </Alert>
          ) : (
            <p className="pc-muted">Confirming your payment of {amount} with {via}…</p>
          ))}
        {payment.state === 'failed' && <Alert tone="danger">This payment did not go through. You have not been charged by us; you can try again from your invoice.</Alert>}
        {payment.state === 'abandoned' && <Alert tone="warning">This payment was not completed. You can try again from your invoice.</Alert>}
        {payment.state === 'amount_mismatch' && (
          <Alert tone="warning">We received a payment that does not match this invoice. Our billing team will contact you; please do not pay again.</Alert>
        )}
        {back}
      </div>
    </Card>
  );
}
