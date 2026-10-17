import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { RegisterScreen } from './RegisterScreen';

function api(status: number, body: unknown = {}, headers: Record<string, string> = {}) {
  return vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers }))) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

async function fill(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Full name'), 'Ada Okafor');
  await user.type(screen.getByLabelText('Email address'), 'ada@example.ng');
  await user.type(screen.getByLabelText('Phone number'), '0803 123 4567');
  await user.type(screen.getByLabelText('Password'), 'correct horse battery');
  await user.click(screen.getByRole('checkbox'));
}

describe('RegisterScreen (IAM-001)', () => {
  it('validates locally and sends nothing when the form is incomplete', async () => {
    const fetchImpl = api(202);
    render(<RegisterScreen fetchImpl={fetchImpl} />);
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));
    expect(screen.getByLabelText('Full name')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText(/accept the Terms of Service/)).toBeInTheDocument();
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('submits the five fields and shows the same confirmation whatever the address', async () => {
    const user = userEvent.setup();
    const fetchImpl = api(202, { status: 'check_your_email' });
    render(<RegisterScreen fetchImpl={fetchImpl} />);
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByRole('heading', { name: 'Check your email' })).toBeInTheDocument();
    expect(screen.getByText('ada@example.ng')).toBeInTheDocument();
    const [, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toEqual({
      full_name: 'Ada Okafor',
      email: 'ada@example.ng',
      phone: '+2348031234567',
      password: 'correct horse battery',
      accept_terms: true,
    });
    expect(screen.queryByText(/already registered/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Resend email \(\d+s\)/ })).toBeDisabled();
  });

  it('shows server field errors next to the right fields', async () => {
    const user = userEvent.setup();
    render(<RegisterScreen fetchImpl={api(422, { code: 'validation_failed', errors: { phone: 'invalid_e164' } })} />);
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByText(/Enter a valid phone number/)).toBeInTheDocument();
    expect(screen.getByLabelText('Phone number')).toHaveAttribute('aria-invalid', 'true');
  });

  it('explains rate limiting without losing the form', async () => {
    const user = userEvent.setup();
    render(<RegisterScreen fetchImpl={api(429, {}, { 'retry-after': '3600' })} />);
    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many sign-up attempts. Please try again in about an hour.');
    expect(screen.getByLabelText('Full name')).toHaveValue('Ada Okafor');
  });

  it('uses the right autofill hints and can reveal the password', async () => {
    render(<RegisterScreen fetchImpl={api(202)} />);
    const password = screen.getByLabelText('Password');
    expect(password).toHaveAttribute('autocomplete', 'new-password');
    expect(password).toHaveAttribute('type', 'password');
    await userEvent.click(screen.getByRole('button', { name: 'Show' }));
    expect(password).toHaveAttribute('type', 'text');
  });
});
