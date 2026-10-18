import { render, screen } from '@testing-library/react';
import { StrictMode } from 'react';
import { describe, expect, it } from 'vitest';
import { VerifyEmailScreen } from './VerifyEmailScreen';
import { api, formCalls } from '../test/api';

const TOKEN = 'A'.repeat(20) + 'b_-'.repeat(7) + '12';

describe('VerifyEmailScreen (IAM-002)', () => {
  it('posts the token from the fragment exactly once, even under StrictMode', async () => {
    expect(TOKEN).toHaveLength(43);
    const fetchImpl = api(200, { status: 'verified' });
    render(
      <StrictMode>
        <VerifyEmailScreen initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} />
      </StrictMode>,
    );

    expect(await screen.findByRole('heading', { name: 'Email confirmed' })).toBeInTheDocument();
    expect(formCalls(fetchImpl)).toHaveLength(1);
    const [path, init] = formCalls(fetchImpl)[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/verify-email');
    expect(JSON.parse(String(init.body))).toEqual({ token: TOKEN });
  });

  it('removes the token from the address bar', async () => {
    window.history.replaceState(null, '', `/verify-email#token=${TOKEN}`);
    render(<VerifyEmailScreen fetchImpl={api(200)} />);
    await screen.findByRole('heading', { name: 'Email confirmed' });
    expect(window.location.hash).toBe('');
    expect(window.location.pathname).toBe('/verify-email');
  });

  it('shows the used-or-expired message when the API refuses the link', async () => {
    render(<VerifyEmailScreen initialHash={`#token=${TOKEN}`} fetchImpl={api(400, { code: 'verification_link_invalid' })} />);
    expect(await screen.findByText(/already been used or has expired/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Register again' })).toHaveAttribute('href', '/register');
    expect(screen.getByRole('button', { name: 'Send a new link' })).toBeEnabled();
  });

  it('does not call the API for a missing or malformed token', async () => {
    const fetchImpl = api(200);
    render(<VerifyEmailScreen initialHash="#token=<script>" fetchImpl={fetchImpl} />);
    expect(await screen.findByRole('heading', { name: 'This link can’t be used' })).toBeInTheDocument();
    expect(formCalls(fetchImpl)).toHaveLength(0);
  });

  it('reports outages separately from bad links', async () => {
    render(<VerifyEmailScreen initialHash={`#token=${TOKEN}`} fetchImpl={api(503)} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('could not confirm your email right now');
  });
});
