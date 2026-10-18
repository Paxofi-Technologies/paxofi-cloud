import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SecurityScreen } from './SecurityScreen';

const CODES = Array.from({ length: 10 }, (_, i) => `AAAA-BBBB-CCCC-DDD${String.fromCharCode(65 + i)}`);

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

/** Routes by path and method; records every call. */
function server(routes: Record<string, () => Response>) {
  return vi.fn((path: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${path}`;
    const route = routes[key];
    return Promise.resolve(route ? route() : json(404, { code: 'route_not_found' }));
  }) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

function calls(fetchImpl: ReturnType<typeof vi.fn>, path: string): RequestInit[] {
  return (fetchImpl.mock.calls as [string, RequestInit][]).filter(([p, i]) => p === path && i.method === 'POST').map(([, i]) => i);
}

describe('SecurityScreen (IAM-007, IAM-013, IAM-014, §6.13)', () => {
  it('turns two-step verification on: password, scan, code, then recovery codes once', async () => {
    const user = userEvent.setup();
    let enabled = false;
    const fetchImpl = server({
      'GET /api/v1/account/mfa': () => json(200, { totp_enabled: enabled, recovery_codes_left: enabled ? 10 : 0 }),
      // RFC 6238 public test secret ("12345678901234567890" in base32), not a credential.
      'POST /api/v1/account/mfa/totp/setup': () =>
        json(200, { secret: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', otpauth_uri: 'otpauth://totp/PaxofiCloud:ada%40example.ng?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' }), // gitleaks:allow
      'POST /api/v1/account/mfa/totp/confirm': () => {
        enabled = true;
        return json(200, { status: 'enabled', recovery_codes: CODES });
      },
    });
    const onStatusChange = vi.fn();
    render(<SecurityScreen csrfToken="csrf-1" fetchImpl={fetchImpl} onStatusChange={onStatusChange} />);

    await user.click(await screen.findByRole('button', { name: 'Turn on two-step verification' }));
    await user.type(screen.getByLabelText('Password'), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    expect(await screen.findByRole('img', { name: 'QR code for your authenticator app' })).toBeInTheDocument();
    expect(screen.getByText('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ')).toBeInTheDocument();
    await user.type(screen.getByLabelText('Verification code'), '287082');
    await user.click(screen.getByRole('button', { name: 'Turn on' }));

    expect(await screen.findByRole('heading', { name: 'Save your recovery codes' })).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(10);
    const done = screen.getByRole('button', { name: 'Done' });
    expect(done).toBeDisabled();
    await user.click(screen.getByRole('checkbox'));
    await user.click(done);

    expect(await screen.findByText('On')).toBeInTheDocument();
    expect(screen.queryByText(CODES[0] as string)).not.toBeInTheDocument();
    expect(onStatusChange).toHaveBeenLastCalledWith({ totpEnabled: true, recoveryCodesLeft: 10 });

    const [setup] = calls(fetchImpl, '/api/v1/account/mfa/totp/setup');
    expect((setup?.headers as Record<string, string>)['X-CSRF-Token']).toBe('csrf-1');
    expect(JSON.parse(String(setup?.body))).toEqual({ password: 'correct horse battery' });
    const [confirm] = calls(fetchImpl, '/api/v1/account/mfa/totp/confirm');
    expect(JSON.parse(String(confirm?.body))).toEqual({ code: '287082' });
  });

  it('says so when the password is wrong and never shows a secret', async () => {
    const user = userEvent.setup();
    const fetchImpl = server({
      'GET /api/v1/account/mfa': () => json(200, { totp_enabled: false, recovery_codes_left: 0 }),
      'POST /api/v1/account/mfa/totp/setup': () => json(422, { code: 'validation_failed', errors: { password: 'incorrect' } }),
    });
    render(<SecurityScreen csrfToken="c" fetchImpl={fetchImpl} />);
    await user.click(await screen.findByRole('button', { name: 'Turn on two-step verification' }));
    await user.type(screen.getByLabelText('Password'), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    expect(await screen.findByText('That password is not right.')).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });

  it('turns it off with the password and a code', async () => {
    const user = userEvent.setup();
    let enabled = true;
    const fetchImpl = server({
      'GET /api/v1/account/mfa': () => json(200, { totp_enabled: enabled, recovery_codes_left: enabled ? 2 : 0 }),
      'POST /api/v1/account/mfa/disable': () => {
        enabled = false;
        return json(200, { status: 'disabled' });
      },
    });
    render(<SecurityScreen csrfToken="c" fetchImpl={fetchImpl} />);

    expect(await screen.findByText(/You have 2 recovery codes left/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Turn off' }));
    await user.type(screen.getByLabelText('Password'), 'correct horse battery');
    await user.type(screen.getByLabelText('Verification code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Turn off' }));

    expect(await screen.findByText('Off')).toBeInTheDocument();
    const [disable] = calls(fetchImpl, '/api/v1/account/mfa/disable');
    expect(JSON.parse(String(disable?.body))).toEqual({ password: 'correct horse battery', code: '123456' });
  });

  it('shows new recovery codes after the password, and explains a lockout', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    const fetchImpl = server({
      'GET /api/v1/account/mfa': () => json(200, { totp_enabled: true, recovery_codes_left: 10 }),
      'POST /api/v1/account/mfa/recovery-codes': () =>
        ++attempt === 1 ? new Response('{"code":"too_many_attempts"}', { status: 429, headers: { 'retry-after': '600' } }) : json(200, { recovery_codes: CODES }),
    });
    render(<SecurityScreen csrfToken="c" fetchImpl={fetchImpl} />);
    await user.click(await screen.findByRole('button', { name: 'Get new recovery codes' }));
    await user.type(screen.getByLabelText('Password'), 'pw');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByText('Too many attempts. Please try again in 10 minutes.')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Password'), 'pw');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByRole('heading', { name: 'Save your recovery codes' })).toBeInTheDocument();
  });
});
