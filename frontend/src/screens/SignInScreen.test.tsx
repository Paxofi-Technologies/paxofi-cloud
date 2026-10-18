import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignInScreen } from './SignInScreen';

function api(status: number, body: unknown = {}, headers: Record<string, string> = {}) {
  return vi.fn(() =>
    Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } })),
  ) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

async function submit(user: ReturnType<typeof userEvent.setup>, password = 'correct horse battery') {
  await user.type(screen.getByLabelText('Email address'), ' ada@example.ng ');
  await user.type(screen.getByLabelText('Password'), password);
  await user.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('SignInScreen (IAM-005, IAM-006)', () => {
  it('validates locally and sends nothing when the form is incomplete', async () => {
    const fetchImpl = api(200);
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} fetchImpl={fetchImpl} />);
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Enter your password.')).toBeInTheDocument();
    expect(fetchImpl).not.toHaveBeenCalled();
    expect(onSignedIn).not.toHaveBeenCalled();
  });

  it('posts exactly email and password, then hands over to the portal', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(200, { status: 'signed_in', csrf_token: 'x'.repeat(43) });
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} fetchImpl={fetchImpl} />);
    await submit(user);

    expect(onSignedIn).toHaveBeenCalledOnce();
    const [path, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/sign-in');
    expect(init.credentials).toBe('same-origin');
    expect(JSON.parse(String(init.body))).toEqual({ email: 'ada@example.ng', password: 'correct horse battery' });
  });

  it('shows one message for a wrong email or password and clears the password (T-09)', async () => {
    const user = userEvent.setup();
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={api(401, { code: 'invalid_credentials' })} />);
    await submit(user);

    expect(await screen.findByText('The email address or password is incorrect.')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toHaveValue('');
    expect(screen.getByLabelText('Email address')).toHaveValue('ada@example.ng');
  });

  it('offers a new verification link when the email is not confirmed yet', async () => {
    const user = userEvent.setup();
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={api(403, { code: 'email_not_verified' })} />);
    await submit(user);

    expect(await screen.findByText(/Confirm your email address before signing in/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Resend email/ })).toBeInTheDocument();
  });

  it('explains a suspended account without inviting retries', async () => {
    const user = userEvent.setup();
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={api(403, { code: 'account_unavailable' })} />);
    await submit(user);

    expect(await screen.findByText(/This account is not available/)).toBeInTheDocument();
  });

  it('tells the customer how long to wait when locked out', async () => {
    const user = userEvent.setup();
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={api(429, { code: 'too_many_attempts' }, { 'retry-after': '240' })} />);
    await submit(user);

    expect(await screen.findByText('Too many sign-in attempts. Please try again in 4 minutes.')).toBeInTheDocument();
  });

  it('reports an unreachable server without blaming the customer', async () => {
    const user = userEvent.setup();
    const fetchImpl = vi.fn(() => Promise.reject(new TypeError('offline'))) as unknown as typeof fetch;
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={fetchImpl} />);
    await submit(user);

    expect(await screen.findByText(/could not reach PaxofiCloud/)).toBeInTheDocument();
  });
});
