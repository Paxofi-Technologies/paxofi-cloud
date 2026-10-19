import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignedInDevices } from './SignedInDevices';

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const CURRENT = 'a'.repeat(32);
const PHONE = 'b'.repeat(32);

function row(id: string, current: boolean, device: string) {
  return {
    id,
    current,
    signed_in_at: '2026-10-19T08:00:00Z',
    last_seen_at: '2026-10-19T09:30:00Z',
    ip: '102.89.1.2',
    location: 'Ikeja, Lagos, Nigeria',
    device: { label: device, type: 'desktop' },
  };
}

function server(lists: unknown[][], routes: Record<string, () => Response> = {}) {
  let n = 0;
  return vi.fn((path: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${path}`;
    if (key === 'GET /api/v1/account/sessions') {
      const sessions = lists[Math.min(n, lists.length - 1)];
      n++;
      return Promise.resolve(json(200, { sessions }));
    }
    const route = routes[key];
    return Promise.resolve(route ? route() : json(404, { code: 'route_not_found' }));
  }) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

function posts(fetchImpl: ReturnType<typeof vi.fn>, path: string): RequestInit[] {
  return (fetchImpl.mock.calls as [string, RequestInit?][]).filter(([p, i]) => p === path && i?.method === 'POST').map(([, i]) => i ?? {});
}

describe('SignedInDevices (T-23, §6.21)', () => {
  it('lists devices, marks this one, and signs another out with the CSRF token', async () => {
    const user = userEvent.setup();
    const onChanged = vi.fn();
    const fetchImpl = server(
      [
        [row(CURRENT, true, 'Chrome 146 on Windows (desktop)'), row(PHONE, false, 'Safari 18 on iOS (mobile)')],
        [row(CURRENT, true, 'Chrome 146 on Windows (desktop)')],
      ],
      { 'POST /api/v1/account/sessions/revoke': () => json(200, { status: 'revoked' }) },
    );
    render(<SignedInDevices csrfToken="csrf-1" fetchImpl={fetchImpl} onChanged={onChanged} />);

    expect(await screen.findByText('Safari 18 on iOS (mobile)')).toBeInTheDocument();
    expect(screen.getByText('This device')).toBeInTheDocument();
    expect(screen.getAllByText('Ikeja, Lagos, Nigeria')).toHaveLength(2);
    // Only the other device can be signed out from here.
    expect(screen.getAllByRole('button', { name: 'Sign out' })).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Sign out' }));
    await screen.findByText('That device is signed out.');
    expect(await screen.findByText('You are not signed in anywhere else.')).toBeInTheDocument();
    const [init] = posts(fetchImpl, '/api/v1/account/sessions/revoke');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf-1');
    expect(JSON.parse(String(init?.body))).toEqual({ id: PHONE });
    expect(onChanged).toHaveBeenCalledOnce();
  });

  it('signs out all other devices', async () => {
    const user = userEvent.setup();
    const fetchImpl = server(
      [[row(CURRENT, true, 'Chrome'), row(PHONE, false, 'Safari'), row('c'.repeat(32), false, 'Firefox')], [row(CURRENT, true, 'Chrome')]],
      { 'POST /api/v1/account/sessions/revoke-others': () => json(200, { status: 'revoked', count: 2 }) },
    );
    render(<SignedInDevices csrfToken="csrf-1" fetchImpl={fetchImpl} />);

    await user.click(await screen.findByRole('button', { name: 'Sign out all other devices' }));
    await screen.findByText('All other devices are signed out.');
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Sign out all other devices' })).toBeNull());
    expect(posts(fetchImpl, '/api/v1/account/sessions/revoke-others')).toHaveLength(1);
  });

  it('ignores malformed rows and reports a failed load', async () => {
    const bad = server([[{ id: 'not-hex', current: false, signed_in_at: 'x', last_seen_at: 'x', device: {} }, row(CURRENT, true, 'Chrome')]]);
    const { unmount } = render(<SignedInDevices csrfToken="csrf-1" fetchImpl={bad} />);
    expect(await screen.findByText('Chrome')).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(2);
    unmount();

    const down = vi.fn(() => Promise.resolve(json(500, {}))) as unknown as typeof fetch;
    render(<SignedInDevices csrfToken="csrf-1" fetchImpl={down} />);
    expect(await screen.findByText(/could not load your signed-in devices/)).toBeInTheDocument();
  });
});
