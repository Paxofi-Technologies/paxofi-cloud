import { describe, expect, it, vi } from 'vitest';
import { fetchSession, signOut } from './session';

function respond(status: number, body: unknown) {
  return vi.fn(() =>
    Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })),
  ) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

describe('session client (D-6, T-21)', () => {
  it('reads who is signed in and the CSRF token', async () => {
    const fetchImpl = respond(200, { identity: { full_name: 'Ada Okafor', email: 'ada@example.ng' }, csrf_token: 'tok' });

    await expect(fetchSession(fetchImpl)).resolves.toEqual({
      kind: 'signed_in',
      session: { fullName: 'Ada Okafor', email: 'ada@example.ng', csrfToken: 'tok' },
    });
    const [path, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/session');
    expect(init.credentials).toBe('same-origin');
    expect(init.cache).toBe('no-store');
  });

  it('treats 401 as signed out and anything unexpected as unavailable', async () => {
    await expect(fetchSession(respond(401, { code: 'not_signed_in' }))).resolves.toEqual({ kind: 'signed_out' });
    await expect(fetchSession(respond(500, {}))).resolves.toEqual({ kind: 'unavailable' });
    await expect(fetchSession(respond(200, { identity: 'nope' }))).resolves.toEqual({ kind: 'unavailable' });
    const offline = vi.fn(() => Promise.reject(new TypeError('offline'))) as unknown as typeof fetch;
    await expect(fetchSession(offline)).resolves.toEqual({ kind: 'unavailable' });
  });

  it('sends the CSRF token as a header when signing out', async () => {
    const fetchImpl = vi.fn(() => Promise.resolve(new Response(null, { status: 204 }))) as unknown as typeof fetch & ReturnType<typeof vi.fn>;

    await expect(signOut('tok', fetchImpl)).resolves.toMatchObject({ kind: 'ok', status: 204 });
    const [path, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/sign-out');
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');
  });
});
