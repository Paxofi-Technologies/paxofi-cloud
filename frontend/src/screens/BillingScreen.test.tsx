import { render, screen } from '@testing-library/react';
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
});
