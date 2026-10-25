import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { readReview } from '../lib/checkout';
import { CheckoutScreen } from './CheckoutScreen';

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const ID = 'a'.repeat(32);
const review = (status: 'pending' | 'ready', lines: Record<string, unknown>[], extra: Record<string, unknown> = {}) => ({
  id: ID,
  status,
  expires_at: '2026-10-25T12:30:00+00:00',
  currency: 'NGN',
  lines,
  subtotal: 1_650_000,
  tax: { name: 'VAT', rate_bp: 750, amount: 123_750 },
  total: 1_773_750,
  blocked: false,
  confirm_billing_country: false,
  billing_country: 'NG',
  terms_version: '2026-10-01',
  auto_renew: false,
  ...extra,
});
const LINE = { domain: 'paxofi-test.com', years: 1, status: 'available', price: 1_650_000, tax: 123_750, total: 1_773_750, renewal_price: 1_815_000 };

describe('CheckoutScreen (CRT-003, CRT-005, CMP-010)', () => {
  it('polls until the registry answers, shows totals and renewal, and places the order once with one key', async () => {
    const user = userEvent.setup();
    const keys: string[] = [];
    let bodies: unknown[] = [];
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (path === '/api/v1/checkout/review' && init?.method === 'POST') return Promise.resolve(json(201, review('pending', [{ ...LINE, status: 'checking' }])));
      if (path.startsWith('/api/v1/checkout/review?id=')) return Promise.resolve(json(200, review('ready', [LINE])));
      if (path === '/api/v1/checkout/orders') {
        keys.push(new Headers(init?.headers).get('Idempotency-Key') ?? '');
        bodies = [...bodies, JSON.parse(String(init?.body))];
        return Promise.resolve(json(201, { order_id: 'b'.repeat(32), order_number: 'PXO-2026-000001', invoice_number: 'PXC-2026-000001', status: 'pending_payment' }));
      }
      return Promise.resolve(json(404, {}));
    }) as unknown as typeof fetch;

    render(<CheckoutScreen csrfToken="csrf-1" fetchImpl={fetchImpl} onBack={vi.fn()} onBilling={vi.fn()} pollMs={5} />);

    expect(await screen.findByText('Available')).toBeInTheDocument();
    expect(screen.getByText('₦18,150.00/yr')).toBeInTheDocument();
    expect(screen.getByText('VAT (7.5%)')).toBeInTheDocument();
    expect(screen.getByText(/Auto-renew is off/)).toBeInTheDocument();
    const place = screen.getByRole('button', { name: /Place order/ });
    expect(place).toBeDisabled();

    await user.click(screen.getByLabelText(/Terms of Service \(version 2026-10-01\)/));
    await user.click(place);

    expect(await screen.findByText('Order PXO-2026-000001')).toBeInTheDocument();
    expect(screen.getByText(/nothing is charged and no domain is registered until you pay/)).toBeInTheDocument();
    expect(keys).toHaveLength(1);
    expect(keys[0]).toMatch(/^[a-f0-9]{32}$/);
    expect(bodies[0]).toEqual({ review_id: ID, accept_terms: true });
  });

  it('blocks taken and premium names and offers to remove them', async () => {
    const fetchImpl = vi.fn(() =>
      Promise.resolve(
        json(201, review('ready', [{ ...LINE, domain: 'taken-test.com', status: 'taken' }, { ...LINE, domain: 'gold-test.com', status: 'premium' }], { blocked: true })),
      ),
    ) as unknown as typeof fetch;
    render(<CheckoutScreen csrfToken="csrf-1" fetchImpl={fetchImpl} onBack={vi.fn()} onBilling={vi.fn()} />);

    expect(await screen.findByText('Premium names are not sold yet. Remove it to continue.')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Remove' })).toHaveLength(2);
    expect(screen.queryByRole('button', { name: /Place order/ })).toBeNull();
  });

  it('sends people without billing details to the billing page', async () => {
    const user = userEvent.setup();
    const onBilling = vi.fn();
    const fetchImpl = vi.fn(() => Promise.resolve(json(422, { code: 'billing_details_required' }))) as unknown as typeof fetch;
    render(<CheckoutScreen csrfToken="csrf-1" fetchImpl={fetchImpl} onBack={vi.fn()} onBilling={onBilling} />);

    await user.click(await screen.findByRole('button', { name: 'Add billing details' }));
    expect(onBilling).toHaveBeenCalled();
  });

  it('asks to confirm a lower-tax billing country and sends the confirmation', async () => {
    const user = userEvent.setup();
    let body: unknown = null;
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (path === '/api/v1/checkout/orders') {
        body = JSON.parse(String(init?.body));
        return Promise.resolve(json(409, { code: 'price_changed' }));
      }
      return Promise.resolve(json(201, review('ready', [LINE], { confirm_billing_country: true, billing_country: 'GH' })));
    }) as unknown as typeof fetch;
    render(<CheckoutScreen csrfToken="csrf-1" fetchImpl={fetchImpl} onBack={vi.fn()} onBilling={vi.fn()} />);

    await user.click(await screen.findByLabelText(/Terms of Service/));
    expect(screen.getByRole('button', { name: /Place order/ })).toBeDisabled();
    await user.click(screen.getByLabelText(/billing address is in GH/));
    await user.click(screen.getByRole('button', { name: /Place order/ }));

    expect(await screen.findByText(/A price changed since you started/)).toBeInTheDocument();
    expect(body).toEqual({ review_id: ID, accept_terms: true, confirm_billing_country: true });
  });

  it('ignores malformed reviews', () => {
    expect(readReview({ id: 'x', currency: 'NGN' })).toBeNull();
    expect(readReview({ ...review('ready', [{ ...LINE, status: 'weird', price: 1.5 }]) })?.lines[0]).toMatchObject({ status: 'unknown', price: null });
  });
});
