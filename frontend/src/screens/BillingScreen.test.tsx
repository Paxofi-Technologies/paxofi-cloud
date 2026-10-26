import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { formatRate, readBilling } from '../lib/billing';
import { BillingScreen } from './BillingScreen';

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const COUNTRIES = [
  { code: 'GH', name: 'Ghana' },
  { code: 'NG', name: 'Nigeria' },
];
const SAVED = {
  full_name: 'Ada Test',
  company: null,
  address_line1: '1 Test Street',
  address_line2: null,
  city: 'Ikeja',
  region: 'Lagos',
  postcode: null,
  country: 'NG',
  phone: '+2348000000000',
  tin: null,
};

describe('BillingScreen (ACC-004, PRC-003, CD-21)', () => {
  it('saves the form with the CSRF token and shows the currency and tax that follow', async () => {
    const user = userEvent.setup();
    let body: Record<string, string> | null = null;
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (path === '/api/v1/account/billing' && init?.method === 'POST') {
        body = JSON.parse(String(init.body)) as Record<string, string>;
        expect(new Headers(init.headers).get('X-CSRF-Token')).toBe('csrf-1');
        return Promise.resolve(json(200, { details: SAVED, currency: 'NGN', currency_locked: false, tax: { name: 'VAT', rate_bp: 750 }, countries: COUNTRIES }));
      }
      return Promise.resolve(json(200, { details: null, currency: null, currency_locked: false, tax: null, countries: COUNTRIES }));
    }) as unknown as typeof fetch;

    render(<BillingScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);
    await user.type(await screen.findByLabelText('Full name'), 'Ada Test');
    await user.type(screen.getByLabelText('Address'), '1 Test Street');
    await user.type(screen.getByLabelText('City'), 'Ikeja');
    await user.type(screen.getByLabelText('State or region'), 'Lagos');
    await user.type(screen.getByLabelText(/^Phone/), '+2348000000000');
    await user.selectOptions(screen.getByLabelText('Country'), 'NG');
    await user.click(screen.getByRole('button', { name: 'Save billing details' }));

    expect(await screen.findByText('Billing details saved.')).toBeInTheDocument();
    expect(body).toMatchObject({ full_name: 'Ada Test', country: 'NG', phone: '+2348000000000', company: '' });
    expect(Object.keys(body ?? {})).not.toContain('billing_currency');
    expect(screen.getByText('Nigerian naira (NGN)')).toBeInTheDocument();
    expect(screen.getByText('VAT 7.5%')).toBeInTheDocument();
  });

  it('shows field errors from the API next to their fields', async () => {
    const user = userEvent.setup();
    const fetchImpl = vi.fn((_path: string, init?: RequestInit) =>
      Promise.resolve(
        init?.method === 'POST'
          ? json(422, { code: 'validation_failed', errors: { phone: 'Use the international format, e.g. +2348012345678.' } })
          : json(200, { details: SAVED, currency: 'NGN', currency_locked: true, tax: { name: 'VAT', rate_bp: 750 }, countries: COUNTRIES }),
      ),
    ) as unknown as typeof fetch;

    render(<BillingScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);
    expect(await screen.findByText('Fixed')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Save billing details' }));
    expect(await screen.findByText('Use the international format, e.g. +2348012345678.')).toBeInTheDocument();
  });

  it('renders hostile values as text', async () => {
    const fetchImpl = vi.fn(() =>
      Promise.resolve(json(200, { details: { ...SAVED, full_name: '<img src=x onerror=alert(1)>' }, currency: 'USD', currency_locked: false, tax: null, countries: COUNTRIES })),
    ) as unknown as typeof fetch;
    const { container } = render(<BillingScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);
    expect(await screen.findByDisplayValue('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(container.querySelector('img')).toBeNull();
  });
});

describe('billing helpers', () => {
  it('drops malformed API values', () => {
    const state = readBilling({ details: { full_name: 5 }, currency: 'EUR', tax: { name: 'VAT', rate_bp: '750' }, countries: [{ code: 'ng', name: 'x' }, { code: 'NG', name: 'Nigeria' }] });
    expect(state.details?.full_name).toBe('');
    expect(state.currency).toBeNull();
    expect(state.tax).toBeNull();
    expect(state.countries).toEqual([{ code: 'NG', name: 'Nigeria' }]);
  });

  it('formats basis points', () => {
    expect([formatRate(750), formatRate(0), formatRate(1000), formatRate(1234)]).toEqual(['7.5%', '0%', '10%', '12.34%']);
  });

  it('opens the invoice for an order from the orders list (INV-001)', async () => {
    const user = userEvent.setup();
    const orderId = 'a'.repeat(32);
    const fetchImpl = vi.fn((path: string) => {
      if (path.startsWith('/api/v1/orders?')) {
        return Promise.resolve(
          json(200, {
            orders: [{ id: orderId, number: 'PXO-2026-000001', status: 'pending_payment', currency: 'NGN', subtotal: 1650000, tax: 123750, total: 1773750, tax_name: 'VAT', tax_rate_bp: 750, placed_at: '2026-10-25T12:00:00+00:00', invoice_number: 'PXC-2026-000001', invoice_status: 'unpaid', lines: [{ domain: 'paxofi-test.com', years: 1, price: 1650000, tax: 123750, total: 1773750, renewal_price: 1815000 }] }],
            has_more: false,
          }),
        );
      }
      if (path === `/api/v1/orders/invoice?id=${orderId}`) {
        return Promise.resolve(
          json(200, {
            invoice: {
              number: 'PXC-2026-000001', status: 'unpaid', currency: 'NGN', subtotal: 1650000, tax: 123750, total: 1773750, tax_name: 'VAT', tax_rate_bp: 750, tax_country: 'NG',
              issued_at: '2026-10-25T12:00:00+00:00', due_at: '2026-10-25T12:00:00+00:00', expires_at: '2026-10-28T12:00:00+00:00', order_id: orderId, order_number: 'PXO-2026-000001',
              bill_to: { ...{ name: 'Ada Test', company: null, line1: '1 Test Street', line2: null, city: 'Ikeja', region: 'Lagos', postcode: null, country: 'NG', phone: '+2348000000000', tin: null } },
              lines: [{ description: 'Domain registration: paxofi-test.com (1 year)', amount: 1650000, tax: 123750, total: 1773750 }],
            },
          }),
        );
      }
      return Promise.resolve(json(200, { details: SAVED, currency: 'NGN', currency_locked: true, tax: { name: 'VAT', rate_bp: 750 }, countries: COUNTRIES }));
    }) as unknown as typeof fetch;

    render(<BillingScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);
    await user.click(await screen.findByRole('button', { name: 'View invoice PXC-2026-000001' }));

    const dialog = await screen.findByRole('dialog');
    const invoice = await within(dialog).findByRole('article', { name: 'Invoice PXC-2026-000001' });
    expect(invoice).toHaveTextContent('Ada Test');
    expect(invoice).toHaveTextContent('Domain registration: paxofi-test.com (1 year)');
    expect(invoice).toHaveTextContent('VAT (7.5%, Nigeria)');
    expect(within(invoice).getByText('Unpaid')).toBeInTheDocument();
    expect(within(invoice).getByRole('button', { name: 'Print or save as PDF' })).toBeInTheDocument();
  });

  it('says so when the invoice cannot be found', async () => {
    const user = userEvent.setup();
    const orderId = 'b'.repeat(32);
    const fetchImpl = vi.fn((path: string) => {
      if (path.startsWith('/api/v1/orders?')) {
        return Promise.resolve(json(200, { orders: [{ id: orderId, number: 'PXO-2026-000002', status: 'pending_payment', currency: 'USD', subtotal: 1000, tax: 0, total: 1000, tax_name: 'Tax', tax_rate_bp: 0, placed_at: '2026-10-25T12:00:00+00:00', invoice_number: 'PXC-2026-000002', invoice_status: 'unpaid', lines: [] }], has_more: false }));
      }
      if (path.startsWith('/api/v1/orders/invoice')) return Promise.resolve(json(404, { code: 'invoice_not_found' }));
      return Promise.resolve(json(200, { details: SAVED, currency: 'USD', currency_locked: true, tax: null, countries: COUNTRIES }));
    }) as unknown as typeof fetch;

    render(<BillingScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);
    await user.click(await screen.findByRole('button', { name: 'View invoice PXC-2026-000002' }));
    expect(await within(await screen.findByRole('dialog')).findByText('This invoice was not found.')).toBeInTheDocument();
  });
});
