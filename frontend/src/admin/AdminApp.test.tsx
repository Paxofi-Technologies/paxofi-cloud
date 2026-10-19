import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { AdminApp, invitationToken } from './AdminApp';
import type { CredentialsApi } from './webauthn';

type Reply = { status: number; body?: unknown; headers?: Record<string, string> };
type Routes = Record<string, Reply | Reply[]>;

/** A fetch stand-in keyed by "METHOD path"; an array answers in turn. */
function adminApi(routes: Routes) {
  const queues = new Map(Object.entries(routes).map(([key, reply]) => [key, Array.isArray(reply) ? [...reply] : [reply]]));
  return vi.fn((path: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${path}`;
    const queue = queues.get(key);
    const reply = queue && queue.length > 1 ? queue.shift() : queue?.[0];
    if (!reply) return Promise.reject(new Error(`unexpected ${key}`));
    return Promise.resolve(
      new Response(reply.status === 204 ? null : JSON.stringify(reply.body ?? {}), {
        status: reply.status,
        headers: { 'content-type': 'application/json', ...reply.headers },
      }),
    );
  }) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
}

function calls(fetchImpl: ReturnType<typeof adminApi>, key: string): RequestInit[] {
  return (fetchImpl.mock.calls as [string, RequestInit | undefined][]).filter(([path, init]) => `${init?.method ?? 'GET'} ${path}` === key).map(([, init]) => init ?? {});
}

const TOKEN = 'A'.repeat(43);
const buffer = (...values: number[]) => new Uint8Array(values).buffer;
const creationOptions = {
  challenge: 'AAAA',
  rp: { id: 'admin.example.test', name: 'PaxofiCloud' },
  user: { id: 'AQ', name: 'ada@example.ng', displayName: 'Ada' },
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
};
const requestOptions = { challenge: 'AAAA', rpId: 'admin.example.test', allowCredentials: [], userVerification: 'required' };

function authenticator(): CredentialsApi & { create: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> } {
  return {
    create: vi.fn(() =>
      Promise.resolve({ id: 'AQ', rawId: buffer(1), type: 'public-key', response: { clientDataJSON: buffer(1), attestationObject: buffer(2) } } as unknown as Credential),
    ),
    get: vi.fn(() =>
      Promise.resolve({
        id: 'AQ',
        rawId: buffer(1),
        type: 'public-key',
        response: { clientDataJSON: buffer(1), authenticatorData: buffer(2), signature: buffer(3), userHandle: buffer(4) },
      } as unknown as Credential),
    ),
  };
}

const manager = {
  staff: {
    id: 's-1',
    full_name: 'Ada Lovelace',
    email: 'ada@example.ng',
    roles: ['security_admin'],
    permissions: ['staff.manage', 'audit.view'],
    sign_in: { password: true, passkeys: 0 },
  },
  csrf_token: 'c'.repeat(43),
};

describe('invitationToken', () => {
  it('accepts only a well-formed 256-bit token', () => {
    expect(invitationToken(`#token=${TOKEN}`)).toBe(TOKEN);
    expect(invitationToken('#token=short')).toBeNull();
    expect(invitationToken('')).toBeNull();
  });
});

const setup = {
  full_name: 'Ada Lovelace',
  email: 'ada@example.ng',
  secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
  uri: 'otpauth://totp/PaxofiCloud%20Admin:ada%40example.ng?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=PaxofiCloud%20Admin',
};
const recoveryCodes = Array.from({ length: 10 }, (_, i) => `AAAA-BBBB-CCCC-DD${String(i).padStart(2, '2')}`);
const PASSWORD = 'correct horse battery staple';

describe('Staff sign-in setup with an invitation (D-11, §6.20)', () => {
  it('sets a password and an authenticator app, shows recovery codes once, then the account is active', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'POST /admin-api/v1/enrolment/start': { status: 200, body: setup },
      'POST /admin-api/v1/enrolment': { status: 200, body: { recovery_codes: recoveryCodes } },
    });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} />);

    await screen.findByRole('heading', { name: 'Set up your staff sign-in' });
    expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeInTheDocument();
    expect(screen.getByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).toBeInTheDocument();
    await user.type(screen.getByLabelText(/^Password/), PASSWORD);
    await user.type(screen.getByLabelText('Confirm password'), PASSWORD);
    await user.type(screen.getByLabelText('Code from your app'), '123456');
    await user.click(screen.getByRole('button', { name: 'Finish setup' }));

    await screen.findByRole('heading', { name: 'Save your recovery codes' });
    expect(screen.getAllByRole('listitem')).toHaveLength(10);
    await user.click(screen.getByLabelText('I have saved these codes somewhere safe'));
    await user.click(screen.getByRole('button', { name: 'Done' }));
    await screen.findByRole('heading', { name: 'Your staff account is active' });

    expect(calls(fetchImpl, 'POST /admin-api/v1/enrolment/start')).toHaveLength(1);
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/enrolment')[0]?.body))).toEqual({ token: TOKEN, password: PASSWORD, code: '123456' });
  });

  it('checks the password length and confirmation before calling the server', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ 'POST /admin-api/v1/enrolment/start': { status: 200, body: setup } });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} />);
    await screen.findByRole('heading', { name: 'Set up your staff sign-in' });

    await user.type(screen.getByLabelText(/^Password/), 'too short');
    await user.type(screen.getByLabelText('Confirm password'), 'different');
    await user.click(screen.getByRole('button', { name: 'Finish setup' }));

    expect(screen.getByText(/Use at least 15 characters/)).toBeInTheDocument();
    expect(screen.getByText('The two passwords do not match.')).toBeInTheDocument();
    expect(screen.getByText('Enter the 6-digit code shown in your app.')).toBeInTheDocument();
    expect(calls(fetchImpl, 'POST /admin-api/v1/enrolment')).toHaveLength(0);
  });

  it('explains server field errors: breached password, wrong code', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'POST /admin-api/v1/enrolment/start': { status: 200, body: setup },
      'POST /admin-api/v1/enrolment': { status: 422, body: { code: 'validation_failed', errors: { password: 'breached', code: 'invalid' } } },
    });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} />);
    await screen.findByRole('heading', { name: 'Set up your staff sign-in' });
    await user.type(screen.getByLabelText(/^Password/), PASSWORD);
    await user.type(screen.getByLabelText('Confirm password'), PASSWORD);
    await user.type(screen.getByLabelText('Code from your app'), '123456');
    await user.click(screen.getByRole('button', { name: 'Finish setup' }));

    await screen.findByText(/appeared in a data breach/);
    expect(screen.getByText(/That code is not right/)).toBeInTheDocument();
  });

  it('removes the invitation token from the address bar and history (§6.19)', async () => {
    window.history.replaceState(null, '', `/enrol#token=${TOKEN}`);
    render(<AdminApp path="/enrol" fetchImpl={adminApi({ 'POST /admin-api/v1/enrolment/start': { status: 200, body: setup } })} />);
    expect(window.location.hash).toBe('');
    expect(window.location.pathname).toBe('/enrol');
    await screen.findByRole('heading', { name: 'Set up your staff sign-in' });
  });

  it('explains a dead invitation without contacting the server for a malformed link', () => {
    const fetchImpl = adminApi({});
    render(<AdminApp path="/enrol" initialHash="#token=nope" fetchImpl={fetchImpl} />);
    expect(screen.getByRole('heading', { name: 'This invitation is not valid' })).toBeInTheDocument();
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('shows the invalid-invitation page when the server refuses the token', async () => {
    const fetchImpl = adminApi({ 'POST /admin-api/v1/enrolment/start': { status: 400, body: { code: 'invitation_invalid' } } });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} />);
    await screen.findByRole('heading', { name: 'This invitation is not valid' });
  });
});

const signedOut = { status: 401, body: { code: 'unauthenticated' } };
const codeRequired = (next: 'totp' | 'email') => ({ status: 200, body: { status: 'code_required', next, expires_in: 600 } });

describe('Staff sign-in: password, authenticator code, email code (§6.20)', () => {
  it('walks the three steps, then shows the console', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [signedOut, { status: 200, body: manager }],
      'POST /admin-api/v1/auth/password': codeRequired('totp'),
      'POST /admin-api/v1/auth/authenticator-code': codeRequired('email'),
      'POST /admin-api/v1/auth/email-code': { status: 200, body: { status: 'signed_in', csrf_token: manager.csrf_token } },
      'GET /admin-api/v1/staff': { status: 200, body: { staff: [], pending_grants: [] } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.type(await screen.findByLabelText('Email address'), 'ada@example.ng');
    await user.type(screen.getByLabelText('Password'), PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(await screen.findByLabelText('Code from your app'), '123456');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('heading', { name: 'Check your email' });
    expect(screen.getByRole('button', { name: /Send a new code \(\d+ s\)/ })).toBeDisabled();
    await user.type(screen.getByLabelText('Code from the email'), '654321');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    await screen.findByRole('heading', { name: 'Staff console' });

    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/password')[0]?.body))).toEqual({ email: 'ada@example.ng', password: PASSWORD });
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/authenticator-code')[0]?.body))).toEqual({ code: '123456' });
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/email-code')[0]?.body))).toEqual({ code: '654321' });
  });

  it('shows one generic message for a wrong email address or password', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/password': { status: 401, body: { code: 'sign_in_failed' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.type(await screen.findByLabelText('Email address'), 'ada@example.ng');
    await user.type(screen.getByLabelText('Password'), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByText('The email address or password is incorrect.');
    expect(screen.getByLabelText('Password')).toHaveValue('');
  });

  it('accepts a recovery code instead of the app code; a wrong code keeps the step', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/password': codeRequired('totp'),
      'POST /admin-api/v1/auth/authenticator-code': [{ status: 401, body: { code: 'code_invalid' } }, codeRequired('email')],
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.type(await screen.findByLabelText('Email address'), 'ada@example.ng');
    await user.type(screen.getByLabelText('Password'), PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    await user.click(await screen.findByRole('button', { name: 'Lost your phone? Use a recovery code' }));
    await user.type(screen.getByLabelText('Recovery code'), 'aaaa-bbbb-cccc-dddd');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByText(/That recovery code is not right/);
    await user.type(screen.getByLabelText('Recovery code'), 'AAAA-BBBB-CCCC-DDDE');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('heading', { name: 'Check your email' });
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/authenticator-code')[1]?.body))).toEqual({ code: 'AAAA-BBBB-CCCC-DDDE' });
  });

  it('starts again from the password when the ticket has ended', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/password': codeRequired('totp'),
      'POST /admin-api/v1/auth/authenticator-code': codeRequired('email'),
      'POST /admin-api/v1/auth/email-code': { status: 401, body: { code: 'sign_in_expired' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.type(await screen.findByLabelText('Email address'), 'ada@example.ng');
    await user.type(screen.getByLabelText('Password'), PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(await screen.findByLabelText('Code from your app'), '123456');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(await screen.findByLabelText('Code from the email'), '654321');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByText(/Your sign-in timed out or had too many wrong codes/);
    expect(screen.getByRole('heading', { name: 'Staff sign-in' })).toBeInTheDocument();
  });

  it('reports throttling with the wait', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/password': { status: 429, body: { code: 'too_many_attempts' }, headers: { 'retry-after': '120' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.type(await screen.findByLabelText('Email address'), 'ada@example.ng');
    await user.type(screen.getByLabelText('Password'), PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByText('Too many attempts. Please try again in 2 minutes.');
  });
});

describe('Optional passkey sign-in (§6.17, §6.20)', () => {
  it('signs in with a passkey instead of the codes', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [signedOut, { status: 200, body: manager }],
      'POST /admin-api/v1/auth/passkey/options': { status: 200, body: { ceremony_id: 'cer-2', options: requestOptions } },
      'POST /admin-api/v1/auth/passkey': { status: 200, body: { status: 'signed_in', csrf_token: manager.csrf_token } },
      'GET /admin-api/v1/staff': { status: 200, body: { staff: [], pending_grants: [] } },
    });
    const credentials = authenticator();
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={credentials} />);

    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey instead' }));
    await screen.findByRole('heading', { name: 'Staff console' });
    expect(credentials.get).toHaveBeenCalledOnce();
    const body = JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/passkey')[0]?.body)) as Record<string, unknown>;
    expect(body.ceremony_id).toBe('cer-2');
    expect(((body.credential as Record<string, unknown>).response as Record<string, unknown>).userHandle).toBe('BA');
  });

  it('shows one generic message when the server refuses the passkey', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/passkey/options': { status: 200, body: { ceremony_id: 'cer-2', options: requestOptions } },
      'POST /admin-api/v1/auth/passkey': { status: 401, body: { code: 'sign_in_failed' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey instead' }));
    await screen.findByText(/Passkey sign-in failed/);
  });

  it('says so when the browser cannot use passkeys', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': signedOut,
      'POST /admin-api/v1/auth/passkey/options': { status: 200, body: { ceremony_id: 'cer-2', options: requestOptions } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={null} />);
    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey instead' }));
    await screen.findByText(/cannot use passkeys/);
  });
});

describe('Your sign-in (§6.20)', () => {
  it('adds a passkey after a current authenticator code', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [
        { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: [] } } },
        { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: [], sign_in: { password: true, passkeys: 1 } } } },
      ],
      'POST /admin-api/v1/account/passkeys/options': [{ status: 401, body: { code: 'code_invalid' } }, { status: 200, body: { ceremony_id: 'cer-3', options: creationOptions } }],
      'POST /admin-api/v1/account/passkeys': { status: 200, body: { passkeys: 1 } },
    });
    const credentials = authenticator();
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={credentials} />);

    await user.click(await screen.findByRole('button', { name: 'Add a passkey' }));
    await user.type(screen.getByLabelText('Code from your app'), '000000');
    await user.click(screen.getByRole('button', { name: 'Create passkey' }));
    await screen.findByText(/That code is not right/);
    expect(credentials.create).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText('Code from your app'), '123456');
    await user.type(screen.getByLabelText('Name for this passkey (optional)'), ' Laptop ');
    await user.click(screen.getByRole('button', { name: 'Create passkey' }));
    await screen.findByText(/Passkey added/);
    await screen.findByText(/1 passkey added/);

    const options = calls(fetchImpl, 'POST /admin-api/v1/account/passkeys/options');
    expect((options[1]?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(JSON.parse(String(options[1]?.body))).toEqual({ code: '123456' });
    const added = JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/account/passkeys')[0]?.body)) as Record<string, unknown>;
    expect(added.ceremony_id).toBe('cer-3');
    expect(added.label).toBe('Laptop');
  });

  it('lets a passkey-only member set up password sign-in once', async () => {
    const user = userEvent.setup();
    const passkeyOnly = { ...manager, staff: { ...manager.staff, permissions: [], sign_in: { password: false, passkeys: 2 } } };
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [{ status: 200, body: passkeyOnly }, { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: [] } } }],
      'POST /admin-api/v1/account/sign-in-setup/start': { status: 200, body: setup },
      'POST /admin-api/v1/account/sign-in-setup': { status: 200, body: { recovery_codes: recoveryCodes } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: 'Set up password and authenticator app' }));
    await user.type(await screen.findByLabelText(/^Password/), PASSWORD);
    await user.type(screen.getByLabelText('Confirm password'), PASSWORD);
    await user.type(screen.getByLabelText('Code from your app'), '123456');
    await user.click(screen.getByRole('button', { name: 'Finish setup' }));
    await screen.findByRole('heading', { name: 'Save your recovery codes' });
    await user.click(screen.getByLabelText('I have saved these codes somewhere safe'));
    await user.click(screen.getByRole('button', { name: 'Done' }));
    await screen.findByRole('button', { name: 'Add a passkey' });

    const [init] = calls(fetchImpl, 'POST /admin-api/v1/account/sign-in-setup');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(JSON.parse(String(init?.body))).toEqual({ password: PASSWORD, code: '123456' });
  });
});

describe('Staff console', () => {
  it('lists staff and invites with the CSRF token; Super admin is not an invitable role', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: manager },
      'GET /admin-api/v1/staff': {
        status: 200,
        body: { staff: [{ id: 's-1', full_name: 'Ada Lovelace', email: 'ada@example.ng', status: 'active', roles: ['security_admin'] }], pending_grants: [] },
      },
      'POST /admin-api/v1/staff/invitations': { status: 201, body: { staff_id: 's-2', status: 'invited' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByRole('cell', { name: 'Ada Lovelace' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Super admin' })).toBeNull();
    await user.type(screen.getByLabelText('Work email address'), 'grace@example.ng');
    await user.type(screen.getByLabelText('Full name'), 'Grace Hopper');
    await user.selectOptions(screen.getByLabelText('Starting role'), 'billing');
    await user.click(screen.getByRole('button', { name: 'Send invitation' }));
    await screen.findByText(/Invitation sent to grace@example\.ng/);

    const [init] = calls(fetchImpl, 'POST /admin-api/v1/staff/invitations');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(JSON.parse(String(init?.body))).toEqual({ email: 'grace@example.ng', full_name: 'Grace Hopper', role: 'billing' });
  });

  it('hides staff management from staff without staff.manage', async () => {
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: { ...manager, staff: { ...manager.staff, roles: ['support'], permissions: ['ticket.reply'] } } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await screen.findByRole('heading', { name: 'Staff console' });
    expect(screen.getByText('Support')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Invite a staff member' })).toBeNull();
    expect(calls(fetchImpl, 'GET /admin-api/v1/staff')).toHaveLength(0);
  });

  it('offers approval only for grants made by someone else', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: manager },
      'GET /admin-api/v1/staff': {
        status: 200,
        body: {
          staff: [
            { id: 's-1', full_name: 'Ada Lovelace', email: 'ada@example.ng', status: 'active', roles: ['security_admin'] },
            { id: 's-2', full_name: 'Grace Hopper', email: 'grace@example.ng', status: 'active', roles: ['security_admin'] },
          ],
          pending_grants: [
            { grant_id: 'g-1', staff_id: 's-2', role: 'super_admin', granted_by: 's-1' },
            { grant_id: 'g-2', staff_id: 's-1', role: 'super_admin', granted_by: 's-2' },
          ],
        },
      },
      'POST /admin-api/v1/staff/roles/approve': { status: 200, body: { status: 'active' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    const approve = await screen.findAllByRole('button', { name: 'Approve' });
    expect(approve).toHaveLength(1);
    await user.click(approve[0] as HTMLElement);
    await waitFor(() => expect(calls(fetchImpl, 'POST /admin-api/v1/staff/roles/approve')).toHaveLength(1));
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/staff/roles/approve')[0]?.body))).toEqual({ grant_id: 'g-2' });
  });

  it('signs out with the CSRF token and returns to sign-in', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [{ status: 200, body: { ...manager, staff: { ...manager.staff, permissions: [] } } }, { status: 401 }],
      'POST /admin-api/v1/auth/sign-out': { status: 204 },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', { name: 'Staff sign-in' });
    expect((calls(fetchImpl, 'POST /admin-api/v1/auth/sign-out')[0]?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
  });
});
