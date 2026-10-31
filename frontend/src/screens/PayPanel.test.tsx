import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PayPanel } from './PayPanel';

const ORDER = 'c'.repeat(32);
const PAYMENT = 'f'.repeat(32);

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const options = (extra: Record<string, unknown> = {}) => ({ invoice_number: 'PXC-2026-000042', status: 'unpaid', amount: 1773750, currency: 'NGN', payable: true, gateways: ['flutterwave', 'paystack'], ...extra });
const payment = (state: string, extra: Record<string, unknown> = {}) => ({
  payment: { id: PAYMENT, reference: 'PXC-X', gateway: 'flutterwave', state, amount: 1773750, currency: 'NGN', invoice_number: 'PXC-2026-000042', order_id: ORDER, checkout_url: null, failure_reason: null, created_at: '2026-10-31T10:00:00+00:00', ...extra },
});

function api(details: unknown[], opts: unknown = options()) {
  const calls: { path: string; init?: RequestInit | undefined }[] = [];
  let poll = 0;
  const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
    calls.push({ path, init });
    if (path.startsWith('/api/v1/payments/options')) return Promise.resolve(json(200, opts));
    if (path === '/api/v1/payments') return Promise.resolve(json(201, { payment_id: PAYMENT }));
    if (path.startsWith('/api/v1/payments/detail')) return Promise.resolve(json(200, details[Math.min(poll++, details.length - 1)]));
    return Promise.resolve(json(404, {}));
  }) as unknown as typeof fetch;
  return { fetchImpl, calls };
}

describe('PayPanel (PY-5, PY-6, PY-17)', () => {
  it('starts a payment for the order and gateway only, then goes to the gateway page once the server has it', async () => {
    const user = userEvent.setup();
    const navigate = vi.fn();
    const { fetchImpl, calls } = api([payment('initiated'), payment('pending', { checkout_url: 'https://checkout-testing.flutterwave.com/v3/hosted/pay/abc' })]);
    render(<PayPanel orderId={ORDER} csrfToken="csrf-1" fetchImpl={fetchImpl} navigate={navigate} pollMs={1} />);

    expect(await screen.findByRole('heading', { name: /Pay ₦17,737\.50/ })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Pay with Flutterwave' }));

    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('https://checkout-testing.flutterwave.com/v3/hosted/pay/abc'));
    const start = calls.find((c) => c.path === '/api/v1/payments');
    expect(JSON.parse(String(start?.init?.body))).toEqual({ order_id: ORDER, gateway: 'flutterwave' });
    const headers = new Headers(start?.init?.headers);
    expect(headers.get('X-CSRF-Token')).toBe('csrf-1');
    expect(headers.get('Idempotency-Key')).toMatch(/^pay-[0-9a-f]{32}$/);
  });

  it('never leaves the site for a page that is not the gateway’s checkout host', async () => {
    const user = userEvent.setup();
    const navigate = vi.fn();
    const { fetchImpl } = api([payment('pending', { checkout_url: 'https://evil.example/pay' }), payment('failed', { failure_reason: 'unexpected_checkout_url' })]);
    render(<PayPanel orderId={ORDER} csrfToken="csrf-1" fetchImpl={fetchImpl} navigate={navigate} pollMs={1} />);

    await user.click(await screen.findByRole('button', { name: 'Pay with Flutterwave' }));

    expect(await screen.findByText(/Flutterwave could not open its payment page/)).toBeInTheDocument();
    expect(navigate).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Pay with Paystack' })).toBeEnabled();
  });

  it('explains a failed start and lets the customer choose the other gateway', async () => {
    const user = userEvent.setup();
    const { fetchImpl } = api([payment('failed', { failure_reason: 'credential_missing' })]);
    render(<PayPanel orderId={ORDER} csrfToken="csrf-1" fetchImpl={fetchImpl} navigate={vi.fn()} pollMs={1} />);

    await user.click(await screen.findByRole('button', { name: 'Pay with Flutterwave' }));

    expect(await screen.findByText(/This payment method is not set up yet/)).toBeInTheDocument();
  });

  it('shows nothing to pay when the server says the invoice is not payable, and a contact note when no gateway is offered', async () => {
    const notPayable = api([], options({ payable: false, gateways: [] }));
    const { container, unmount } = render(<PayPanel orderId={ORDER} csrfToken="csrf-1" fetchImpl={notPayable.fetchImpl} />);
    await vi.waitFor(() => expect(notPayable.calls).toHaveLength(1));
    await vi.waitFor(() => expect(container).toBeEmptyDOMElement());
    unmount();

    const usd = api([], options({ currency: 'USD', amount: 5000, gateways: [] }));
    render(<PayPanel orderId={ORDER} csrfToken="csrf-1" fetchImpl={usd.fetchImpl} />);
    expect(await screen.findByText(/Online payment is not available for this invoice yet/)).toBeInTheDocument();
    expect(screen.queryByRole('button')).toBeNull();
  });
});
