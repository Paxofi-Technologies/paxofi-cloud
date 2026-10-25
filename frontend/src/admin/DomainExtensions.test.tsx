import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { amountToMinor, DomainExtensionsPage, percentToBp } from './DomainExtensions';
import type { StaffSession } from './adminApi';

const session = { csrfToken: 'c'.repeat(43) } as StaffSession;

function api(routes: Record<string, { status: number; body: unknown }>) {
  return vi.fn((path: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${path.split('?')[0]}`;
    const reply = routes[key];
    if (!reply) return Promise.reject(new Error(`unexpected ${key}`));
    return Promise.resolve(new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'content-type': 'application/json' } }));
  }) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

const row = (overrides: Record<string, unknown> = {}) => ({
  tld: 'com',
  featured_order: 3,
  label: 'sale',
  pinned_registrar: null,
  pin_unavailable: false,
  chosen_registrar: 'namecheap',
  offers: [
    { registrar: 'namecheap', register: 1000, renew: 1100, currency: 'USD' },
    { registrar: 'domainnameapi', register: 1081, renew: 1181, currency: 'USD' },
  ],
  usd: { register: 1250, renew: 1375, transfer: null, restore: 10000 },
  ngn: { register: 1875000, renew: 2065000, transfer: null, restore: 15000000 },
  grace_days: 30,
  grace_fee: 0,
  redemption_days: 30,
  redemption_fee: null,
  lifecycle_set: false,
  ...overrides,
});

const page = (overrides: Record<string, unknown> = {}) => ({
  can_manage: true,
  registrars: ['domainnameapi', 'namecheap', 'dynadot'],
  labels: ['none', 'hot', 'new', 'sale'],
  pricing: { markup_bp: 2500, min_margin_bp: 1000, usd_step: 1, ngn_step: 5000 },
  fx: { fetched_at: '2026-10-25T01:00:00+00:00', source: 'exchangerate-api' },
  total: 1,
  page: 1,
  page_size: 25,
  rows: [row()],
  ...overrides,
});

describe('percentToBp and amountToMinor', () => {
  it('parse without floating point and refuse anything else', () => {
    expect(percentToBp('25')).toBe(2500);
    expect(percentToBp('12.5')).toBe(1250);
    expect(percentToBp('0.05')).toBe(5);
    expect(percentToBp('1e3')).toBeNull();
    expect(percentToBp('-5')).toBeNull();
    expect(amountToMinor('80')).toBe(8000);
    expect(amountToMinor('0.01')).toBe(1);
    expect(amountToMinor('80.505')).toBeNull();
  });
});

describe('DomainExtensionsPage', () => {
  it('lists the chosen registrar and USD/NGN prices as text', async () => {
    const fetchImpl = api({ 'GET /admin-api/v1/domain-extensions': { status: 200, body: page({ rows: [row({ tld: '<img src=x>', pin_unavailable: true, pinned_registrar: 'dynadot' })] }) } });
    render(<DomainExtensionsPage session={session} fetchImpl={fetchImpl} />);

    const table = await screen.findByRole('table', { name: 'Domain extensions' });
    const [, first] = within(table).getAllByRole('row');
    if (!first) throw new Error('no row');
    const cells = within(first).getAllByRole('cell').map((c) => c.textContent);
    expect(cells[0]).toContain('.<img src=x>');
    expect(cells[0]).toContain('Featured 3');
    expect(cells[0]).toContain('SALE');
    expect(cells[1]).toContain('Namecheap');
    expect(cells[1]).toContain('Pin unavailable');
    expect(cells[2]).toBe('USD 12.50NGN 18,750.00');
    expect(cells[4]).toBe('—');
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText(/Markup 25% on the registrar cost; no price below cost \+ 10%/)).toBeInTheDocument();
  });

  it('saves an extension with the CSRF token and exact values', async () => {
    const user = userEvent.setup();
    const fetchImpl = api({
      'GET /admin-api/v1/domain-extensions': { status: 200, body: page() },
      'POST /admin-api/v1/domain-extensions/settings': { status: 200, body: { status: 'saved' } },
    });
    render(<DomainExtensionsPage session={session} fetchImpl={fetchImpl} />);

    await user.click(await screen.findByRole('button', { name: 'Edit' }));
    await user.selectOptions(screen.getByLabelText('Registrar for new registrations'), 'dynadot');
    await user.selectOptions(screen.getByLabelText('Label'), 'hot');
    await user.clear(screen.getByLabelText('Redemption fee (USD)'));
    await user.type(screen.getByLabelText('Redemption fee (USD)'), '80.50');
    await user.click(screen.getByRole('button', { name: 'Save .com' }));

    expect(await screen.findByText('.com saved.')).toBeInTheDocument();
    const call = fetchImpl.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST') as [string, RequestInit];
    expect((call[1].headers as Record<string, string>)['X-CSRF-Token']).toBe(session.csrfToken);
    expect(JSON.parse(String(call[1].body))).toEqual({
      tld: 'com',
      pinned_registrar: 'dynadot',
      featured_order: 3,
      label: 'hot',
      grace_days: 30,
      grace_fee: 0,
      redemption_days: 30,
      redemption_fee: 8050,
    });
  });

  it('asks for an authenticator code before saving pricing settings and explains a wrong one', async () => {
    const user = userEvent.setup();
    const fetchImpl = api({
      'GET /admin-api/v1/domain-extensions': { status: 200, body: page({ pricing: null, fx: null }) },
      'POST /admin-api/v1/domain-pricing-settings': { status: 403, body: { code: 'step_up_failed' } },
    });
    render(<DomainExtensionsPage session={session} fetchImpl={fetchImpl} />);

    expect(await screen.findByText(/No pricing settings yet, so nothing is priced/)).toBeInTheDocument();
    expect(screen.getByText(/Waiting for the first exchange rate/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Set pricing settings' }));
    await user.type(screen.getByLabelText('Markup (%)'), '25');
    await user.type(screen.getByLabelText('Minimum margin (%)'), '30');
    await user.click(screen.getByRole('button', { name: 'Save pricing settings' }));
    expect(screen.getByText('The minimum margin cannot be higher than the markup.')).toBeInTheDocument();

    await user.clear(screen.getByLabelText('Minimum margin (%)'));
    await user.type(screen.getByLabelText('Minimum margin (%)'), '10');
    await user.click(screen.getByRole('button', { name: 'Save pricing settings' }));
    expect(screen.getByText('Enter the 6-digit code from your authenticator app.')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Authenticator code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Save pricing settings' }));
    expect(await screen.findByText(/That authenticator code is not right/)).toBeInTheDocument();
    const call = fetchImpl.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST') as [string, RequestInit];
    expect(JSON.parse(String(call[1].body))).toEqual({ markup_bp: 2500, min_margin_bp: 1000, usd_step: 1, ngn_step: 5000, code: '123456' });
  });

  it('shows no edit controls to staff who may only read', async () => {
    const fetchImpl = api({ 'GET /admin-api/v1/domain-extensions': { status: 200, body: page({ can_manage: false }) } });
    render(<DomainExtensionsPage session={session} fetchImpl={fetchImpl} />);
    await screen.findByRole('table', { name: 'Domain extensions' });
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
    expect(screen.queryByRole('button', { name: /pricing settings/ })).toBeNull();
  });
});
