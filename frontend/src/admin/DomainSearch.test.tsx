import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { StaffSession } from './adminApi';
import { DomainSearchPage, words } from './DomainSearch';

const session = { id: 's1', csrfToken: 'csrf' } as unknown as StaffSession;

const view = (overrides: Record<string, unknown> = {}) => ({
  can_manage: true,
  chosen: false,
  settings: { search_tlds: ['ng', 'com.ng'], max_ideas: 20, prefixes: ['get'], suffixes: ['hq'] },
  limits: { search_tlds: 20, per_search: 12, max_ideas: 30, words: 12 },
  modes: ['auto', 'rdap', 'whois', 'registrar'],
  extensions: [
    { tld: 'com', in_search: false, featured: true, mode: 'auto', whois_server: null, whois_phrase: null },
    { tld: 'com.ng', in_search: true, featured: false, mode: 'auto', whois_server: null, whois_phrase: null },
    { tld: 'ng', in_search: true, featured: true, mode: 'whois', whois_server: 'whois.nic.net.ng', whois_phrase: '<b>free</b>' },
  ],
  ...overrides,
});

function api(routes: Record<string, (init?: RequestInit) => { status: number; body: unknown }>, calls: { path: string; body: unknown }[] = []) {
  return vi.fn((path: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${path}`;
    const route = routes[key];
    if (init?.method === 'POST') calls.push({ path, body: JSON.parse(String(init.body)) });
    const reply = route ? route(init) : { status: 404, body: {} };
    return Promise.resolve(new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'content-type': 'application/json' } }));
  }) as unknown as typeof fetch;
}

describe('DomainSearchPage', () => {
  it('splits idea words on commas and spaces', () => {
    expect(words(' Get, my  try,,the ')).toEqual(['get', 'my', 'try', 'the']);
  });

  it('shows the search extensions, ideas and each extension lookup as text', async () => {
    render(<DomainSearchPage session={session} fetchImpl={api({ 'GET /admin-api/v1/domain-search': () => ({ status: 200, body: view() }) })} />);

    expect(await screen.findByText(/2/, { selector: 'strong' })).toBeTruthy();
    expect(screen.getByText(/featured extensions plus .ng and .com.ng/)).toBeTruthy();
    const table = screen.getByRole('table', { name: 'Lookup per extension' });
    expect(within(table).getByText('whois.nic.net.ng')).toBeTruthy();
    expect(within(table).getByText('<b>free</b>')).toBeTruthy();
    expect(within(table).getByText('WHOIS only')).toBeTruthy();
  });

  it('saves the ticked extensions in order with the idea settings', async () => {
    const user = userEvent.setup();
    const calls: { path: string; body: unknown }[] = [];
    const fetchImpl = api(
      {
        'GET /admin-api/v1/domain-search': () => ({ status: 200, body: view() }),
        'POST /admin-api/v1/domain-search/settings': () => ({ status: 200, body: { status: 'saved' } }),
      },
      calls,
    );
    render(<DomainSearchPage session={session} fetchImpl={fetchImpl} />);

    await user.click(await screen.findByLabelText('.com'));
    const ideas = screen.getByLabelText('Name ideas shown');
    await user.clear(ideas);
    await user.type(ideas, '10');
    await user.click(screen.getByRole('button', { name: 'Save search settings' }));

    expect(calls).toEqual([
      { path: '/admin-api/v1/domain-search/settings', body: { search_tlds: ['ng', 'com.ng', 'com'], max_ideas: 10, prefixes: ['get'], suffixes: ['hq'] } },
    ]);
  });

  it('asks for an authenticator code only when the WHOIS server changes', async () => {
    const user = userEvent.setup();
    const calls: { path: string; body: unknown }[] = [];
    const fetchImpl = api(
      {
        'GET /admin-api/v1/domain-search': () => ({ status: 200, body: view() }),
        'POST /admin-api/v1/domain-search/lookup': () => ({ status: 200, body: { status: 'saved' } }),
      },
      calls,
    );
    render(<DomainSearchPage session={session} fetchImpl={fetchImpl} />);

    await user.click(await screen.findByRole('button', { name: 'Change how .com is checked' }));
    expect(screen.queryByLabelText('Authenticator code')).toBeNull();
    await user.type(screen.getByLabelText('WHOIS server'), 'whois.verisign-grs.com');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText(/6-digit code/)).toBeTruthy();
    expect(calls).toEqual([]);

    await user.type(screen.getByLabelText('Authenticator code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(calls).toEqual([
      { path: '/admin-api/v1/domain-search/lookup', body: { tld: 'com', mode: 'auto', whois_server: 'whois.verisign-grs.com', whois_phrase: null, code: '123456' } },
    ]);
  });

  it('is read-only without catalogue.manage', async () => {
    render(<DomainSearchPage session={session} fetchImpl={api({ 'GET /admin-api/v1/domain-search': () => ({ status: 200, body: view({ can_manage: false }) }) })} />);

    expect(await screen.findByRole('table', { name: 'Lookup per extension' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Save search settings' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Change how/ })).toBeNull();
  });
});
