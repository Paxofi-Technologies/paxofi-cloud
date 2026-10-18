import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { RESEND_COOLDOWN_SECONDS, ResendVerification } from './ResendVerification';
import { api, formCalls } from '../test/api';


afterEach(() => {
  vi.useRealTimers();
});

describe('ResendVerification (IAM-002, T-49)', () => {
  it('starts in cooldown after sign-up, then resends to the registered address', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    const fetchImpl = api(202, { status: 'check_your_email' });
    render(<ResendVerification email="ada@example.ng" initialCooldown={RESEND_COOLDOWN_SECONDS} fetchImpl={fetchImpl} />);

    expect(screen.getByRole('button', { name: /Resend email \(60s\)/ })).toBeDisabled();
    for (let i = 0; i < RESEND_COOLDOWN_SECONDS; i++) {
      await act(() => vi.advanceTimersByTimeAsync(1000));
    }
    const button = screen.getByRole('button', { name: 'Resend email' });
    expect(button).toBeEnabled();

    await user.click(button);
    expect(await screen.findByText(/We have sent a new link/)).toBeInTheDocument();
    const [path, init] = formCalls(fetchImpl)[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/resend-verification');
    expect(JSON.parse(String(init.body))).toEqual({ email: 'ada@example.ng' });
    expect(screen.getByRole('button', { name: /Resend email \(\d+s\)/ })).toBeDisabled();
  });

  it('asks for the address when none is known and never says whether it exists', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(202, { status: 'check_your_email' });
    render(<ResendVerification fetchImpl={fetchImpl} />);

    await user.click(screen.getByRole('button', { name: 'Send a new link' }));
    expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true');
    expect(formCalls(fetchImpl)).toHaveLength(0);

    await user.type(screen.getByLabelText('Email address'), 'ada@example.ng');
    await user.click(screen.getByRole('button', { name: 'Send a new link' }));
    expect(await screen.findByText(/If that address has a sign-up waiting for confirmation/)).toBeInTheDocument();
    expect(screen.queryByText(/not registered|no account/i)).not.toBeInTheDocument();
  });

  it('explains the server limit and waits it out', async () => {
    const user = userEvent.setup();
    render(<ResendVerification email="ada@example.ng" fetchImpl={api(429, {}, { 'retry-after': '1800' })} />);

    await user.click(screen.getByRole('button', { name: 'Resend email' }));
    expect(await screen.findByText('Too many emails requested. Please try again in 30 minutes.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Resend email \(1800s\)/ })).toBeDisabled();
  });

  it('reports an outage without losing the address', async () => {
    const user = userEvent.setup();
    render(<ResendVerification fetchImpl={api(503)} />);
    await user.type(screen.getByLabelText('Email address'), 'ada@example.ng');
    await user.click(screen.getByRole('button', { name: 'Send a new link' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('We could not send the email right now.');
    expect(screen.getByLabelText('Email address')).toHaveValue('ada@example.ng');
  });
});
