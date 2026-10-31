import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { availableIdeas, confirmWithRegistrar, DomainSearchScreen, MAX_IDEAS_SHOWN, mergeAnswers, type NameIdea, type SearchResult } from './DomainSearchScreen';

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
    const search = {
      name: 'acme',
      results: [
        result('acme.ng', 'available', { label: 'hot' }),
        result('acme.com', 'taken'),
        result('acme.org', 'unknown'),
        result('<img src=x>.com', 'available'),
        { domain: 'bad.com', status: 'available', register: { usd: 1.5, ngn: 1 } },
      ],
    };
    const cartAfterAdd = { lines: [{ id: '0123456789abcdef', domain: 'acme.ng', available: true, register: { usd: 1250, ngn: 1_875_000 } }], total: { usd: 1250, ngn: 1_875_000 } };
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      const json = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } }));
      if (path === '/api/v1/cart') return json({ lines: [], total: { usd: 0, ngn: 0 } });
      if (path === '/api/v1/cart/domains') {
        expect(JSON.parse(String(init?.body))).toEqual({ domain: 'acme.ng' });
        expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf-1');
        return json(cartAfterAdd);
      }
      if (path.startsWith('/api/v1/domains/check')) return json({ code: 'too_many_requests' }, 429);
      return json(search);
    }) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
    render(<DomainSearchScreen csrfToken="csrf-1" fetchImpl={fetchImpl} />);

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
    const bar = await screen.findByText(/in your cart/);
    expect(bar.closest('[role="status"]')).toHaveTextContent('1 domain in your cart');
    expect(bar.closest('[role="status"]')).toHaveTextContent('18,750.00');
    expect(screen.getByRole('button', { name: 'Remove acme.ng' })).toBeInTheDocument();
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
    expect(fetchImpl).not.toHaveBeenCalledWith(expect.stringContaining('/api/v1/domains/search'), expect.anything());
  });
});

describe('registrar confirmation (DS-13)', () => {
  const base = (domain: string, status: 'available' | 'taken' | 'unknown'): SearchResult => ({
    domain,
    tld: domain.split('.').slice(1).join('.'),
    status,
    featured: false,
    label: 'none',
    register: { usd: 1199, ngn: 1_800_000 },
    renew: { usd: 1299, ngn: 1_950_000 },
  });

  it('lets the registrar answer win, shows premium prices, and keeps RDAP when the registrar is unsure', () => {
    const merged = mergeAnswers(
      [base('a.ng', 'unknown'), base('b.com', 'available'), base('c.com', 'unknown'), base('d.com', 'available')],
      [
        { domain: 'a.ng', status: 'available', premium: false, register: null },
        { domain: 'b.com', status: 'available', premium: true, register: { usd: 300000, ngn: 450_000_000 } },
        { domain: 'c.com', status: 'unknown', premium: false, register: null },
        { domain: 'd.com', status: 'available', premium: true, register: null },
      ],
    );
    expect(merged.map((r) => [r.domain, r.status, r.premium ?? false])).toEqual([
      ['a.ng', 'available', false],
      ['b.com', 'available', true],
      ['c.com', 'unknown', false],
      ['d.com', 'unavailable', true],
    ]);
    expect(merged[1]?.register).toEqual({ usd: 300000, ngn: 450_000_000 });
    expect(merged[1]?.renew).toBeNull();
  });

  it('queues the names, polls until done and drops malformed answers', async () => {
    const id = 'a'.repeat(32);
    let polls = 0;
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (init?.method === 'POST') {
        expect(JSON.parse(String(init.body))).toEqual({ domains: ['a.ng'] });
        return Promise.resolve(new Response(JSON.stringify({ id }), { status: 202, headers: { 'content-type': 'application/json' } }));
      }
      expect(path).toBe(`/api/v1/domains/check?id=${id}`);
      polls++;
      const body = polls < 2 ? { status: 'pending', results: [] } : { status: 'done', results: [{ domain: 'a.ng', status: 'available', premium: false, register: null }, { domain: 1, status: 'available' }] };
      return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'content-type': 'application/json' } }));
    }) as unknown as typeof fetch;

    const answers = await confirmWithRegistrar(['a.ng'], fetchImpl, () => Promise.resolve());
    expect(answers).toEqual([{ domain: 'a.ng', status: 'available', premium: false, register: null }]);
    expect(polls).toBe(2);
  });
});

describe('name ideas (DS-23)', () => {
  const idea = (domain: string): NameIdea => ({
    domain,
    tld: domain.slice(domain.indexOf('.') + 1),
    label: 'none',
    register: { usd: 1250, ngn: 1875000 },
    renew: { usd: 1375, ngn: 2065000 },
  });

  it('shows only ideas confirmed available, never premium ones without a price, at most twenty', () => {
    const ideas = [idea('getacme.com'), idea('acmehq.ng'), idea('acme.xyz'), idea('acme.io'), idea('tryacme.com')];
    const shown = availableIdeas(ideas, [
      {
        domain: 'getacme.com',
        status: 'available',
        premium: false,
        register: null,
      },
      { domain: 'acmehq.ng', status: 'taken', premium: false, register: null },
      { domain: 'acme.xyz', status: 'unknown', premium: false, register: null },
      { domain: 'acme.io', status: 'available', premium: true, register: null },
      {
        domain: 'tryacme.com',
        status: 'available',
        premium: true,
        register: { usd: 99900, ngn: 149_850_000 },
      },
    ]);
    expect(shown.map((r) => r.domain)).toEqual(['getacme.com', 'tryacme.com']);
    expect(shown[1]?.register).toEqual({ usd: 99900, ngn: 149_850_000 });
    expect(shown[1]?.renew).toBeNull();

    const many = Array.from({ length: 30 }, (_, i) => idea(`n${i}.com`));
    const all = many.map((m) => ({ domain: m.domain, status: 'available' as const, premium: false, register: null }));
    expect(availableIdeas(many, all)).toHaveLength(20);
    expect(availableIdeas(many, all, 5)).toHaveLength(5);
    expect(availableIdeas(many, all, 99)).toHaveLength(MAX_IDEAS_SHOWN);
  });

  it('sends the ideas with the searched names in one job', async () => {
    const posted: unknown[] = [];
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)));
        return Promise.resolve(
          new Response(JSON.stringify({ id: 'a'.repeat(32) }), {
            status: 202,
            headers: { 'content-type': 'application/json' },
          }),
        );
      }
      expect(path).toBe(`/api/v1/domains/check?id=${'a'.repeat(32)}`);
      return Promise.resolve(
        new Response(
          JSON.stringify({
            status: 'done',
            results: [
              {
                domain: 'getacme.com',
                status: 'available',
                premium: false,
                register: null,
              },
            ],
          }),
          { status: 200 },
        ),
      );
    }) as unknown as typeof fetch;

    const answers = await confirmWithRegistrar(['acme.ng'], fetchImpl, () => Promise.resolve(), ['getacme.com']);

    expect(posted).toEqual([{ domains: ['acme.ng'], ideas: ['getacme.com'] }]);
    expect(answers).toEqual([
      {
        domain: 'getacme.com',
        status: 'available',
        premium: false,
        register: null,
      },
    ]);
  });

  it('shows a More ideas card with the available ideas after a search', async () => {
    const user = userEvent.setup();
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      const json = (body: unknown, status = 200) =>
        Promise.resolve(
          new Response(JSON.stringify(body), {
            status,
            headers: { 'content-type': 'application/json' },
          }),
        );
      if (path === '/api/v1/cart') return json({ lines: [], total: { usd: 0, ngn: 0 } });
      if (path.startsWith('/api/v1/domains/prices'))
        return json({
          featured: [],
          categories: {},
          total: 0,
          page: 1,
          page_size: 25,
          rows: [],
        });
      if (path.startsWith('/api/v1/domains/search'))
        return json({
          name: 'acme',
          results: [result('acme.ng', 'available')],
          ideas: [idea('getacme.com'), idea('acmehq.ng')],
        });
      if (init?.method === 'POST') return json({ id: 'b'.repeat(32) }, 202);
      return json({
        status: 'done',
        results: [
          {
            domain: 'acme.ng',
            status: 'available',
            premium: false,
            register: null,
          },
          {
            domain: 'getacme.com',
            status: 'available',
            premium: false,
            register: null,
          },
          {
            domain: 'acmehq.ng',
            status: 'taken',
            premium: false,
            register: null,
          },
        ],
      });
    }) as unknown as typeof fetch;

    render(<DomainSearchScreen fetchImpl={fetchImpl} csrfToken="t" />);
    await user.type(screen.getByLabelText('Domain name'), 'acme');
    await user.click(screen.getByRole('button', { name: /search/i }));

    const card = await screen.findByText('More ideas', {}, { timeout: 4000 });
    const section = card.closest('section') ?? card.parentElement?.parentElement ?? document.body;
    expect(within(section as HTMLElement).getByText('getacme.com')).toBeTruthy();
    expect(screen.queryByText('acmehq.ng')).toBeNull();
  });
});
