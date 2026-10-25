import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DomainPriceListView, fetchPriceList } from './DomainPriceList';

const row = (tld: string, extra: Record<string, unknown> = {}) => ({
  tld,
  featured: false,
  label: 'none',
  register: { usd: 1100, ngn: 1650000 },
  transfer: { usd: 1100, ngn: 1650000 },
  renew: { usd: 1200, ngn: 1800000 },
  ...extra,
});

function api(body: unknown, status = 200) {
  return vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } }))) as unknown as typeof fetch &
    ReturnType<typeof vi.fn>;
}

describe('fetchPriceList', () => {
  it('keeps only well-formed rows and refuses odd extensions or float prices', async () => {
    const list = await fetchPriceList(
      { q: '', page: 1 },
      api({ featured: [], total: 3, page: 1, page_size: 25, rows: [row('com'), row('<b>x'), row('org', { register: { usd: 10.5, ngn: 1 } })] }),
    );
    expect(list?.rows.map((r) => r.tld)).toEqual(['com']);
  });

  it('returns null when the list cannot be loaded', async () => {
    expect(await fetchPriceList({ q: '', page: 1 }, api({}, 500))).toBeNull();
  });
});

describe('DomainPriceListView', () => {
  it('shows featured cards and the price list, and a card picks its extension', async () => {
    const user = userEvent.setup();
    const onPick = vi.fn();
    const fetchImpl = api({ featured: [row('ng', { featured: true }), row('com', { featured: true, label: 'sale' })], total: 2, page: 1, page_size: 25, rows: [row('com', { label: 'sale' }), row('org', { transfer: null })] });
    render(<DomainPriceListView fetchImpl={fetchImpl} onPick={onPick} />);

    const cards = await screen.findByRole('region', { name: 'Featured extensions' });
    expect(within(cards).getAllByRole('button').map((b) => b.textContent)).toEqual([expect.stringContaining('.ng'), expect.stringContaining('.com')]);
    const table = screen.getByRole('table', { name: 'Domain prices' });
    expect(within(table).getByText('Not online')).toBeInTheDocument();
    expect(within(table).getAllByText('SALE')).toHaveLength(1);
    await user.click(within(cards).getAllByRole('button')[1] as HTMLElement);
    expect(onPick).toHaveBeenCalledWith('com');
    expect(String(fetchImpl.mock.calls[0]?.[0])).toBe('/api/v1/domains/prices');
  });
});
