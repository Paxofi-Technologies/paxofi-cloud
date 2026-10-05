import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignInScreen } from './SignInScreen';

describe('SignInScreen', () => {
  it('shows field errors and does not proceed when empty', async () => {
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} />);
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByLabelText('Email address')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Enter your password.')).toBeInTheDocument();
    expect(onSignedIn).not.toHaveBeenCalled();
  });

  it('requires a second step with a 6-digit code before signing in', async () => {
    const onSignedIn = vi.fn();
    render(<SignInScreen onSignedIn={onSignedIn} />);
    await userEvent.type(screen.getByLabelText('Email address'), 'adaeze@example.com');
    await userEvent.type(screen.getByLabelText('Password'), 'correct horse battery staple');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    const code = await screen.findByLabelText('Verification code');
    expect(onSignedIn).not.toHaveBeenCalled();

    await userEvent.type(code, '12a3');
    expect(code).toHaveValue('123');
    await userEvent.click(screen.getByRole('button', { name: 'Verify and sign in' }));
    expect(screen.getByText('Enter the 6-digit code from your authenticator app.')).toBeInTheDocument();
    expect(onSignedIn).not.toHaveBeenCalled();

    await userEvent.type(code, '456');
    await userEvent.click(screen.getByRole('button', { name: 'Verify and sign in' }));
    expect(onSignedIn).toHaveBeenCalledWith('adaeze@example.com');
  });

  it('uses browser autofill hints for password managers', () => {
    render(<SignInScreen onSignedIn={() => undefined} />);
    expect(screen.getByLabelText('Email address')).toHaveAttribute('autocomplete', 'username');
    expect(screen.getByLabelText('Password')).toHaveAttribute('autocomplete', 'current-password');
  });
});
