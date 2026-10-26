import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { formatRate, TaxRatesPage } from './TaxRates';
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

const overview = (canManage = true) => ({
  can_manage: canManage,
  countries: [
    {
      country: 'NG',
      country_name: 'Nigeria',
      current: { tax_name: 'VAT', rate_bp: 750, starts: '2026-01-01T00:00:00+00:00' },
      scheduled: [{ tax_name: 'VAT', rate_bp: 1000, starts: '2027-01-01T00:00:00+00:00' }],
    },
    { country: 'ZZ', country_name: 'Everyone else', current: { tax_name: 'Tax', rate_bp: 0, starts: '2026-01-01T00:00:00+00:00' }, scheduled: [] },
  ],
  history: [
    { country: 'NG', tax_name: 'VAT', rate_bp: 1000, starts: '2027-01-01T00:00:00+00:00', added_by: 'c0000000000000000000000000000000' },
    { country: 'NG', tax_name: 'VAT', rate_bp: 750, starts: '2026-01-01T00:00:00+00:00', added_by: 'migration' },
  ],
  country_options: [
    { code: 'ZZ', name: 'Everyone else' },
    { code: 'GH', name: 'Ghana' },
    { code: 'NG', name: 'Nigeria' },
  ],
});

describe('formatRate', () => {
  it('shows basis points as a percentage without floating point', () => {
    expect(formatRate(750)).toBe('7.5%');
    expect(formatRate(0)).toBe('0%');
    expect(formatRate(1025)).toBe('10.25%');
    expect(formatRate(10000)).toBe('100%');
  });
});

describe('TaxRatesPage', () => {
  it('lists the rate in force, the scheduled change and the history', async () => {
    render(<TaxRatesPage session={session} fetchImpl={api({ 'GET /admin-api/v1/tax-rates': { status: 200, body: overview() } })} />);

    const table = await screen.findByRole('table', { name: 'Tax rates in force' });
    const nigeria = within(table).getByRole('row', { name: /Nigeria/ });
    expect(nigeria).toHaveTextContent('7.5%');
    expect(nigeria).toHaveTextContent('10% from 1 Jan 2027');
    expect(within(screen.getByRole('table', { name: 'Tax rate history' })).getByText('Launch setting')).toBeInTheDocument();
  });

  it('changes a rate with an authenticator code and reloads', async () => {
    const user = userEvent.setup();
    const fetchImpl = api({
      'GET /admin-api/v1/tax-rates': { status: 200, body: overview() },
      'POST /admin-api/v1/tax-rates': { status: 200, body: { status: 'saved' } },
    });
    render(<TaxRatesPage session={session} fetchImpl={fetchImpl} />);

    await user.click(await screen.findByRole('button', { name: 'Change the rate for Nigeria' }));
    const dialog = await screen.findByRole('dialog');
    const rate = within(dialog).getByLabelText('Rate (%)');
    expect(rate).toHaveValue('7.5');
    await user.clear(rate);
    await user.type(rate, '5');
    await user.type(within(dialog).getByLabelText('Authenticator code'), '123456');
    await user.click(within(dialog).getByRole('button', { name: 'Save rate' }));

    const post = fetchImpl.mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST');
    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({ country: 'NG', tax_name: 'VAT', rate_bp: 500, starts: 'now', code: '123456' });
    expect((post?.[1] as RequestInit).headers).toMatchObject({ 'X-CSRF-Token': session.csrfToken });
    expect(fetchImpl.mock.calls.filter(([path]) => String(path).endsWith('/tax-rates')).length).toBeGreaterThanOrEqual(3);
  });

  it('checks the form before sending and explains a refused date', async () => {
    const user = userEvent.setup();
    const fetchImpl = api({
      'GET /admin-api/v1/tax-rates': { status: 200, body: overview() },
      'POST /admin-api/v1/tax-rates': { status: 422, body: { type: 'about:blank', title: 'start_in_past', code: 'start_in_past' } },
    });
    render(<TaxRatesPage session={session} fetchImpl={fetchImpl} />);
    await user.click(await screen.findByRole('button', { name: 'Add or change a rate' }));
    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByLabelText('Rate (%)'), '150');
    await user.type(within(dialog).getByLabelText('Authenticator code'), '123456');
    await user.click(within(dialog).getByRole('button', { name: 'Save rate' }));
    expect(within(dialog).getByText(/percentage from 0 to 100/)).toBeInTheDocument();
    expect(fetchImpl.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'POST')).toBe(false);

    await user.clear(within(dialog).getByLabelText('Rate (%)'));
    await user.type(within(dialog).getByLabelText('Rate (%)'), '7.5');
    await user.selectOptions(within(dialog).getByLabelText('When the new rate starts'), 'date');
    fireEvent.change(within(dialog).getByLabelText('Start date'), { target: { value: '2026-10-01' } });
    expect(within(dialog).getByLabelText('Authenticator code')).toHaveValue('123456'); // kept after a form check fails
    await user.click(within(dialog).getByRole('button', { name: 'Save rate' }));
    expect(await within(dialog).findByText(/must be after today/)).toBeInTheDocument();
  });

  it('shows no change buttons to staff who may only read', async () => {
    render(<TaxRatesPage session={session} fetchImpl={api({ 'GET /admin-api/v1/tax-rates': { status: 200, body: overview(false) } })} />);
    await screen.findByRole('table', { name: 'Tax rates in force' });
    expect(screen.queryByRole('button', { name: 'Add or change a rate' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Change the rate/ })).not.toBeInTheDocument();
  });
});
