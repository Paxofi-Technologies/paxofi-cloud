import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PaymentReturnScreen } from './PaymentReturnScreen';

const PAYMENT = 'f'.repeat(32);

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const payment = (state: string) => ({
  payment: { id: PAYMENT, reference: 'PXC-X', gateway: 'paystack', state, amount: 1773750, currency: 'NGN', invoice_number: 'PXC-2026-000042', order_id: 'c'.repeat(32), checkout_url: null, failure_reason: null, created_at: '2026-10-31T10:00:00+00:00' },
});

function api(states: string[]) {
  const calls: { path: string; init?: RequestInit | undefined }[] = [];
  let poll = 0;
  const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
    calls.push({ path, init });
    if (path === '/api/v1/payments/check') return Promise.resolve(json(202, payment(states[0] ?? 'pending')));
    if (path.startsWith('/api/v1/payments/detail')) return Promise.resolve(json(200, payment(states[Math.min(poll++, states.length - 1)] ?? 'pending')));
    return Promise.resolve(json(404, {}));
  }) as unknown as typeof fetch;
  return { fetchImpl, calls };
}

describe('PaymentReturnScreen (PY-10, PT-05)', () => {
  it('asks for an early check, then shows the server’s answer once it is in', async () => {
    const { fetchImpl, calls } = api(['pending', 'pending', 'succeeded']);
    render(<PaymentReturnScreen paymentId={PAYMENT} csrfToken="csrf-1" fetchImpl={fetchImpl} onBilling={vi.fn()} pollMs={1} />);

    expect(await screen.findByText(/Payment received: ₦17,737\.50 via Paystack/)).toBeInTheDocument();
    const check = calls.find((c) => c.path === '/api/v1/payments/check');
    expect(JSON.parse(String(check?.init?.body))).toEqual({ payment_id: PAYMENT });
    expect(new Headers(check?.init?.headers).get('X-CSRF-Token')).toBe('csrf-1');
  });

  it('never trusts the gateway’s query parameters: a "success" in the address bar changes nothing', async () => {
    window.history.replaceState(null, '', `/billing/payments/${PAYMENT}?status=successful&reference=PXC-X&trxref=PXC-X`);
    const { fetchImpl, calls } = api(['failed']);
    render(<PaymentReturnScreen paymentId={PAYMENT} csrfToken="csrf-1" fetchImpl={fetchImpl} onBilling={vi.fn()} pollMs={1} />);

    expect(await screen.findByText(/This payment did not go through/)).toBeInTheDocument();
    expect(screen.queryByText(/Payment received/)).toBeNull();
    expect(calls.every((c) => !c.path.includes('status=') && !c.path.includes('trxref'))).toBe(true);
    window.history.replaceState(null, '', '/');
  });

  it('stops polling after a while and tells the customer not to pay again', async () => {
    const { fetchImpl } = api(['pending']);
    render(<PaymentReturnScreen paymentId={PAYMENT} csrfToken="csrf-1" fetchImpl={fetchImpl} onBilling={vi.fn()} pollMs={1} />);

    expect(await screen.findByText(/you do not need to pay again/, undefined, { timeout: 3000 })).toBeInTheDocument();
  });

  it('says so when the payment is not this account’s', async () => {
    const fetchImpl = vi.fn((path: string) => Promise.resolve(path === '/api/v1/payments/check' ? json(404, { code: 'payment_not_found' }) : json(404, { code: 'payment_not_found' }))) as unknown as typeof fetch;
    render(<PaymentReturnScreen paymentId={PAYMENT} csrfToken="csrf-1" fetchImpl={fetchImpl} onBilling={vi.fn()} pollMs={1} />);

    expect(await screen.findByText('We could not find this payment.')).toBeInTheDocument();
  });
});
