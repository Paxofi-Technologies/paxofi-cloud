import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DomainSearchScreen } from './DomainSearchScreen';

function api(status: number, body: unknown, headers: Record<string, string> = {}) {
  return vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } }))) as unknown as typeof fetch &
    ReturnType<typeof vi.fn>;
}

const result = (domain: string, status: string, overrides: Record<string, unknown> = {}) => ({
  domain,
  tld: domain.slice(domain.indexOf('.') + 1),
  status,
  featured: true,
  label: 'none',
  register: { usd: 1250, ngn: 1875000 },
  renew: { usd: 1375, ngn: 2065000 },
  ...overrides,
});

describe('DomainSearchScreen', () => {
  it('searches the real API and shows availability and naira/dollar prices as text', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(200, {
      name: 'acme',
      results: [
        result('acme.ng', 'available', { label: 'hot' }),
        result('acme.com', 'taken'),
        result('acme.org', 'unknown'),
        result('<img src=x>.com', 'available'),
        { domain: 'bad.com', status: 'available', register: { usd: 1.5, ngn: 1 } },
      ],
    });
    render(<DomainSearchScreen fetchImpl={fetchImpl} />);

    await user.type(screen.getByLabelText('Domain name'), ' Acme ');
    await user.click(screen.getByRole('button', { name: /Search/ }));

    expect(fetchImpl).toHaveBeenCalledWith('/api/v1/domains/search?name=acme', expect.anything());
    const items = await screen.findAllByRole('listitem');
    expect(items).toHaveLength(4);
    const ng = within(items[0] as HTMLElement);
    expect(ng.getByText('acme.ng')).toBeInTheDocument();
    expect(ng.getByText('Available')).toBeInTheDocument();
    expect(ng.getByText('HOT')).toBeInTheDocument();
    expect(ng.getByText('Nigeria')).toBeInTheDocument();
    expect(ng.getByText(/18,750\.00/)).toBeInTheDocument();
    expect(ng.getByText(/renews at/)).toBeInTheDocument();
    expect(within(items[1] as HTMLElement).getByText('Already registered')).toBeInTheDocument();
    expect(within(items[2] as HTMLElement).getByText("Couldn't check")).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Choose acme.ng' }));
    expect(screen.getByRole('status')).toHaveTextContent('1 domain chosen');
    expect(screen.getByRole('status')).toHaveTextContent('Checkout opens soon.');
  });

  it('explains bad names, unknown extensions and the rate limit', async () => {
    const user = userEvent.setup();
    const cases: [number, unknown, Record<string, string>, RegExp][] = [
      [422, { code: 'invalid_name' }, {}, /letters, numbers and hyphens/],
      [422, { code: 'unknown_extension' }, {}, /don't sell that extension yet/],
      [429, { code: 'too_many_requests' }, { 'retry-after': '30' }, /Too many searches/],
      [503, {}, {}, /not available right now/],
    ];
    for (const [status, body, headers, message] of cases) {
      const { unmount } = render(<DomainSearchScreen fetchImpl={api(status, body, headers)} />);
      await user.type(screen.getByLabelText('Domain name'), 'acme');
      await user.click(screen.getByRole('button', { name: /Search/ }));
      expect(await screen.findByText(message)).toBeInTheDocument();
      unmount();
    }
  });

  it('asks for a name before searching', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(200, { results: [] });
    render(<DomainSearchScreen fetchImpl={fetchImpl} />);
    await user.click(screen.getByRole('button', { name: /Search/ }));
    expect(screen.getByText('Type the name you want, like yourbusiness.')).toBeInTheDocument();
    expect(fetchImpl).not.toHaveBeenCalled();
  });
});
