import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { App } from './App';

const SESSION = { identity: { full_name: 'Ada Okafor', email: 'ada@example.ng' }, csrf_token: 'csrf-123' };

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

describe('App routing and session (§6.11)', () => {
  it('sends a visitor without a session to the sign-in page', async () => {
    const navigate = vi.fn();
    const fetchImpl = vi.fn(() => Promise.resolve(json(401, { code: 'not_signed_in' }))) as unknown as typeof fetch;
    render(<App path="/" fetchImpl={fetchImpl} navigate={navigate} />);

    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/sign-in'));
    expect(screen.queryByText(/Welcome back/)).not.toBeInTheDocument();
  });

  it('shows the portal with the real name when signed in, and signs out with the CSRF token', async () => {
    const navigate = vi.fn();
    const fetchImpl = vi.fn((path: string) =>
      Promise.resolve(path === '/api/v1/auth/session' ? json(200, SESSION) : new Response(null, { status: 204 })),
    ) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
    render(<App path="/" fetchImpl={fetchImpl} navigate={navigate} />);

    expect(await screen.findByRole('heading', { name: 'Welcome back, Ada' })).toBeInTheDocument();
    expect(screen.getByText('Ada Okafor')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/sign-in'));
    const signOutCall = (fetchImpl.mock.calls as [string, RequestInit][]).find(([path]) => path === '/api/v1/auth/sign-out');
    expect(signOutCall).toBeDefined();
    const [, init] = signOutCall as [string, RequestInit];
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf-123');
  });

  it('offers a retry when the API cannot be reached', async () => {
    const fetchImpl = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce(json(200, SESSION)) as unknown as typeof fetch;
    render(<App path="/" fetchImpl={fetchImpl} navigate={vi.fn()} />);

    await userEvent.click(await screen.findByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { name: 'Welcome back, Ada' })).toBeInTheDocument();
  });

  it('opens the sign-in page at /sign-in and goes to the portal after signing in', async () => {
    const navigate = vi.fn();
    const fetchImpl = vi.fn(() => Promise.resolve(json(200, { status: 'signed_in', csrf_token: 'x' }))) as unknown as typeof fetch;
    render(<App path="/sign-in" fetchImpl={fetchImpl} navigate={navigate} />);

    await userEvent.type(screen.getByLabelText('Email address'), 'ada@example.ng');
    await userEvent.type(screen.getByLabelText('Password'), 'correct horse battery');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/'));
  });

  it('shows the two-step banner only when it is off, and the button opens Security', async () => {
    const fetchImpl = vi.fn((path: string) =>
      Promise.resolve(
        path === '/api/v1/auth/session'
          ? json(200, SESSION)
          : path === '/api/v1/account/mfa'
            ? json(200, { totp_enabled: false, recovery_codes_left: 0 })
            : json(404, {}),
      ),
    ) as unknown as typeof fetch;
    render(<App path="/" fetchImpl={fetchImpl} navigate={vi.fn()} />);

    await userEvent.click(await screen.findByRole('button', { name: 'Turn on two-step verification' }));
    expect(await screen.findByRole('heading', { name: 'Security' })).toBeInTheDocument();
  });

  it('hides the banner when two-step verification is already on', async () => {
    const fetchImpl = vi.fn((path: string) =>
      Promise.resolve(path === '/api/v1/auth/session' ? json(200, SESSION) : json(200, { totp_enabled: true, recovery_codes_left: 10 })),
    ) as unknown as typeof fetch;
    render(<App path="/" fetchImpl={fetchImpl} navigate={vi.fn()} />);

    expect(await screen.findByRole('heading', { name: 'Welcome back, Ada' })).toBeInTheDocument();
    await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledWith('/api/v1/account/mfa', expect.anything()));
    expect(screen.queryByText('Protect your account')).not.toBeInTheDocument();
  });
});
