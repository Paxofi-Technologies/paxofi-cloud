import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignInScreen } from './SignInScreen';
import { api, formCalls } from '../test/api';
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
