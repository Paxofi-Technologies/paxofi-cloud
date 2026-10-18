import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignInScreen } from './SignInScreen';
import { api, formCalls, type FetchMock } from '../test/api';
import { fakeTurnstile } from '../test/turnstile';


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
    expect(formCalls(fetchImpl)).toHaveLength(0);
    expect(onSignedIn).not.toHaveBeenCalled();
  });

  it('posts exactly email and password, then hands over to the portal', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(200, { status: 'signed_in', csrf_token: 'x'.repeat(43) });
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} fetchImpl={fetchImpl} />);
    await submit(user);

    expect(onSignedIn).toHaveBeenCalledOnce();
    const [path, init] = formCalls(fetchImpl)[0] as [string, RequestInit];
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

describe('SignInScreen bot check (§6.12)', () => {
  it('shows the check only after the server asks for it, then sends the token', async () => {
    const fake = fakeTurnstile();
    const user = userEvent.setup();
    const fetchImpl = api(403, { code: 'challenge_required' }, {}, '0x4AAAAAAAsite');
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} fetchImpl={fetchImpl} />);
    await submit(user);

    expect(await screen.findByText('Please complete the security check, then try again.')).toBeInTheDocument();
    await vi.waitFor(() => expect(fake.api.render).toHaveBeenCalledOnce());
    expect(fake.rendered[0]?.action).toBe('sign_in');
    expect(JSON.parse(String((formCalls(fetchImpl)[0] as [string, RequestInit])[1].body))).not.toHaveProperty('turnstile_token');

    act(() => fake.solve('tok-9'));
    await user.type(screen.getByLabelText('Password'), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    await vi.waitFor(() => expect(formCalls(fetchImpl)).toHaveLength(2));
    expect(JSON.parse(String((formCalls(fetchImpl)[1] as [string, RequestInit])[1].body))).toMatchObject({ turnstile_token: 'tok-9' });
    fake.uninstall();
  });
});

describe('SignInScreen two-step verification (IAM-007, §6.13)', () => {
  function flow(second: Response) {
    return vi.fn((path: string) =>
      Promise.resolve(
        path === '/api/v1/auth/challenge'
          ? new Response(JSON.stringify({ turnstile_site_key: null }), { status: 200, headers: { 'content-type': 'application/json' } })
          : path === '/api/v1/auth/sign-in'
            ? new Response('{"status":"mfa_required"}', { status: 200, headers: { 'content-type': 'application/json' } })
            : second,
      ),
    ) as unknown as FetchMock;
  }

  it('asks for a code after the password and signs in with it', async () => {
    const user = userEvent.setup();
    const onSignedIn = vi.fn();
    const fetchImpl = flow(new Response('{"status":"signed_in","csrf_token":"x"}', { status: 200, headers: { 'content-type': 'application/json' } }));
    render(<SignInScreen onSignedIn={onSignedIn} fetchImpl={fetchImpl} />);
    await submit(user);

    expect(await screen.findByRole('heading', { name: 'Two-step verification' })).toBeInTheDocument();
    expect(onSignedIn).not.toHaveBeenCalled();
    await user.type(screen.getByLabelText('Verification code'), '12a3456');
    expect(screen.getByLabelText('Verification code')).toHaveValue('123456');
    await user.click(screen.getByRole('button', { name: 'Verify and sign in' }));

    await vi.waitFor(() => expect(onSignedIn).toHaveBeenCalledOnce());
    const call = formCalls(fetchImpl).find(([path]) => path === '/api/v1/auth/sign-in/mfa');
    expect(JSON.parse(String(call?.[1].body))).toEqual({ code: '123456' });
  });

  it('accepts a recovery code instead', async () => {
    const user = userEvent.setup();
    const fetchImpl = flow(new Response('{"status":"signed_in","csrf_token":"x"}', { status: 200, headers: { 'content-type': 'application/json' } }));
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={fetchImpl} />);
    await submit(user);
    await user.click(await screen.findByRole('button', { name: 'Use a recovery code instead' }));
    await user.type(screen.getByLabelText('Recovery code'), 'abcd-efgh-ijkl-mnop');
    await user.click(screen.getByRole('button', { name: 'Verify and sign in' }));

    await vi.waitFor(() => expect(formCalls(fetchImpl).some(([path]) => path === '/api/v1/auth/sign-in/mfa')).toBe(true));
    const call = formCalls(fetchImpl).find(([path]) => path === '/api/v1/auth/sign-in/mfa');
    expect(JSON.parse(String(call?.[1].body))).toEqual({ recovery_code: 'abcd-efgh-ijkl-mnop' });
  });

  it('explains a wrong code and lets the customer retry', async () => {
    const user = userEvent.setup();
    const fetchImpl = flow(new Response('{"code":"validation_failed","errors":{"code":"incorrect"}}', { status: 422, headers: { 'content-type': 'application/problem+json' } }));
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={fetchImpl} />);
    await submit(user);
    await user.type(await screen.findByLabelText('Verification code'), '000000');
    await user.click(screen.getByRole('button', { name: 'Verify and sign in' }));

    expect(await screen.findByText(/That code is not right/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Verify and sign in' })).toBeInTheDocument();
  });

  it('sends the customer back to the password after a lockout', async () => {
    const user = userEvent.setup();
    const fetchImpl = flow(new Response('{"code":"too_many_attempts"}', { status: 429, headers: { 'content-type': 'application/problem+json', 'retry-after': '900' } }));
    render(<SignInScreen onSignedIn={vi.fn()} fetchImpl={fetchImpl} />);
    await submit(user);
    await user.type(await screen.findByLabelText('Verification code'), '000000');
    await user.click(screen.getByRole('button', { name: 'Verify and sign in' }));

    expect(await screen.findByText(/paused for 15 minutes/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Sign in again' }));
    expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
  });
});
