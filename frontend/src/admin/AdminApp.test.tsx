import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { AdminApp, adminPageForPath, adminPagesFor, invitationToken } from './AdminApp';
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
    render(<AdminApp path="/account" fetchImpl={fetchImpl} credentials={credentials} />);

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
    render(<AdminApp path="/account" fetchImpl={fetchImpl} credentials={authenticator()} />);

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
    render(<AdminApp path="/staff" fetchImpl={fetchImpl} credentials={authenticator()} />);

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
    render(<AdminApp path="/staff" fetchImpl={fetchImpl} credentials={authenticator()} />);
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
    render(<AdminApp path="/staff" fetchImpl={fetchImpl} credentials={authenticator()} />);
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

describe('Provider connections (THREAT-MODEL-PROVIDER-CONNECTIONS.md)', () => {
  const securityAdmin = { ...manager, staff: { ...manager.staff, permissions: ['staff.manage', 'provider_credentials.manage', 'audit.view'] } };
  const providers = {
    providers: [
      {
        provider: 'domainnameapi',
        label: 'DomainNameAPI (domain registrar)',
        credential_id: 'domainnameapi-staging',
        fields: [
          { name: 'reseller_id', label: 'Reseller ID', secret: false },
          { name: 'api_key', label: 'API key', secret: true },
        ],
        configured_at: null,
        review_due_on: null,
        pending_since: null,
      },
      {
        provider: 'hetzner',
        label: 'Hetzner Cloud (servers)',
        credential_id: 'hetzner-staging',
        fields: [{ name: 'api_token', label: 'API token (read & write)', secret: true }],
        configured_at: '2026-10-20T08:00:00+00:00',
        review_due_on: '2027-10-20',
        pending_since: null,
      },
    ],
  };
  const base = {
    'GET /admin-api/v1/auth/session': { status: 200, body: securityAdmin },
    'GET /admin-api/v1/staff': { status: 200, body: { staff: [], pending_grants: [] } },
  };

  it('shows status only and saves a credential with a fresh code and the CSRF token, then clears the values', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      ...base,
      'GET /admin-api/v1/provider-connections': { status: 200, body: providers },
      'POST /admin-api/v1/provider-connections': { status: 202, body: { status: 'pending' } },
    });
    render(<AdminApp path="/provider-connections" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText('Not connected yet.')).toBeInTheDocument();
    expect(screen.getByText(/Connected\. Last set 20 Oct 2026, 09:00 WAT; review by 2027-10-20\./)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Replace credential' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Set credential' }));
    const password = screen.getByLabelText('API key');
    expect(password).toHaveAttribute('type', 'password');
    expect(password).toHaveAttribute('autocomplete', 'new-password');
    await user.type(screen.getByLabelText('Reseller ID'), 'reseller-id-1');
    await user.type(password, 'S3cret-key');
    await user.type(screen.getByLabelText('Authenticator code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Save credential' }));

    await screen.findByText(/DomainNameAPI \(domain registrar\): saved\./);
    const [init] = calls(fetchImpl, 'POST /admin-api/v1/provider-connections');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(JSON.parse(String(init?.body))).toEqual({ provider: 'domainnameapi', fields: { reseller_id: 'reseller-id-1', api_key: 'S3cret-key' }, code: '123456' });
    expect(screen.queryByLabelText('API key')).toBeNull();
    expect(document.body.innerHTML).not.toContain('S3cret-key');
  });

  it('explains a wrong code and clears what was typed', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      ...base,
      'GET /admin-api/v1/provider-connections': { status: 200, body: providers },
      'POST /admin-api/v1/provider-connections': { status: 401, body: { code: 'code_invalid' } },
    });
    render(<AdminApp path="/provider-connections" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: 'Replace credential' }));
    await user.type(screen.getByLabelText('API token (read & write)'), 'tok-1');
    await user.type(screen.getByLabelText('Authenticator code'), '000000');
    await user.click(screen.getByRole('button', { name: 'Save credential' }));

    expect(await screen.findByText(/That code is not right/)).toBeInTheDocument();
    expect(screen.getByLabelText('API token (read & write)')).toHaveValue('');
    expect(screen.getByLabelText('Authenticator code')).toHaveValue('');
  });

  it('checks the form before sending anything', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ ...base, 'GET /admin-api/v1/provider-connections': { status: 200, body: providers } });
    render(<AdminApp path="/provider-connections" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: 'Set credential' }));
    await user.click(screen.getByRole('button', { name: 'Save credential' }));

    expect(screen.getByText('Enter the reseller id.')).toBeInTheDocument();
    expect(screen.getByText('Enter the 6-digit code from your authenticator app.')).toBeInTheDocument();
    expect(calls(fetchImpl, 'POST /admin-api/v1/provider-connections')).toHaveLength(0);
  });

  it('is hidden from staff without provider_credentials.manage', async () => {
    const fetchImpl = adminApi({ ...base, 'GET /admin-api/v1/auth/session': { status: 200, body: manager } });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await screen.findByRole('heading', { name: 'Staff console' });
    expect(screen.queryByText('Provider connections')).toBeNull();
    expect(calls(fetchImpl, 'GET /admin-api/v1/provider-connections')).toHaveLength(0);
  });
});

describe('Domain costs (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13)', () => {
  const catalogue = { ...manager, staff: { ...manager.staff, roles: ['catalogue'], permissions: ['catalogue.manage', 'catalogue.view'] } };
  const finance = { ...manager, staff: { ...manager.staff, roles: ['finance'], permissions: ['catalogue.view', 'reports.view'] } };
  const costs = (overrides: Record<string, unknown> = {}) => ({
    provider: 'domainnameapi',
    environment: 'live',
    can_sync: true,
    daily_at: '02:30 WAT',
    pending_since: null,
    next_request_at: null,
    latest: { status: 'accepted', reason: null, items: 2, refused: 1, fetched_at: '2026-10-24T01:30:00+00:00' },
    quarantined: null,
    runs: [{ trigger: 'schedule', outcome: 'accepted', reason: null, attempts: 1, started_at: '2026-10-24T01:30:00+00:00', finished_at: '2026-10-24T01:30:05+00:00' }],
    skipped: [],
    items: [
      { tld: 'com', currency: 'USD', register: 1081, renew: 123456, transfer: 1081, restore: null, min_years: 1, max_years: 10, status: 'active' },
      { tld: 'com.ng', currency: 'USD', register: 900, renew: 900, transfer: null, restore: 4500, min_years: 1, max_years: 1, status: 'active' },
    ],
    ...overrides,
  });

  it('lists costs from integer minor units and filters by extension', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ 'GET /admin-api/v1/auth/session': { status: 200, body: catalogue }, 'GET /admin-api/v1/domain-costs': { status: 200, body: costs() } });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/2 extensions, fetched 24 Oct 2026, 02:30 WAT; 1 skipped as unreadable\./)).toBeInTheDocument();
    expect(screen.getByText('.com')).toBeInTheDocument();
    expect(screen.getByText('USD 1,234.56')).toBeInTheDocument();
    expect(screen.getAllByText('USD 10.81')).toHaveLength(2);
    expect(screen.getByText('1–10')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Find an extension'), '.ng');
    expect(screen.queryByText('.com')).toBeNull();
    expect(screen.getByText('.com.ng')).toBeInTheDocument();
    expect(screen.getByText('USD 45.00')).toBeInTheDocument();
  });

  it('requests a sync with the CSRF token and explains the 10-minute limit', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': [{ status: 200, body: costs() }, { status: 200, body: costs({ next_request_at: '2026-10-24T09:10:00+00:00' }) }],
      'POST /admin-api/v1/domain-costs/sync': { status: 429, body: { code: 'too_soon' }, headers: { 'retry-after': '300' } },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: 'Sync now' }));

    expect(await screen.findByText(/Prices were requested recently\. You can ask again/)).toBeInTheDocument();
    const [init] = calls(fetchImpl, 'POST /admin-api/v1/domain-costs/sync');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(await screen.findByText(/Sync now is available again 24 Oct 2026, 10:10 WAT\./)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sync now' })).toBeDisabled();
  });

  it('groups the extensions the last sync skipped by reason, as text', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': {
        status: 200,
        body: costs({
          skipped: [
            { name: 'com.tr', reason: 'unsupported_currency', detail: 'TRY' },
            { name: 'net.tr', reason: 'unsupported_currency', detail: 'TRY' },
            { name: 'ai', reason: 'no_register_price', detail: 'periods:2' },
            { name: null, reason: 'bad_name', detail: null },
            { name: '<img src=x>', reason: 'bad_price', detail: null },
          ],
        }),
      },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByText('Not on sale yet (5)'));
    expect(screen.getByText('Priced in a currency we do not accept yet (TRY): 2')).toBeInTheDocument();
    expect(screen.getByText('.com.tr, .net.tr')).toBeInTheDocument();
    expect(screen.getByText('No one-year registration price (shortest term 2 years): 1')).toBeInTheDocument();
    expect(screen.getByText('1 without a readable name')).toBeInTheDocument();
    expect(screen.getByText('.<img src=x>')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('shows a held-back list and a failed run without listing their prices', async () => {
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': {
        status: 200,
        body: costs({
          quarantined: { status: 'quarantined', reason: 'costs_jumped', items: 2, refused: 0, fetched_at: '2026-10-24T02:00:00+00:00' },
          runs: [{ trigger: 'manual', outcome: 'failed', reason: 'unauthorised', attempts: 1, started_at: '2026-10-24T03:00:00+00:00', finished_at: '2026-10-24T03:00:01+00:00' }],
        }),
      },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/was held back because more than 20% of costs changed by over 50%/)).toBeInTheDocument();
    expect(screen.getByText(/failed: the registrar refused the API key/)).toBeInTheDocument();
  });

  it('lets Finance read but not sync, and hides the card from staff without catalogue.view', async () => {
    const financeFetch = adminApi({ 'GET /admin-api/v1/auth/session': { status: 200, body: finance }, 'GET /admin-api/v1/domain-costs': { status: 200, body: costs({ can_sync: false }) } });
    const { unmount } = render(<AdminApp path="/domain-costs" fetchImpl={financeFetch} credentials={authenticator()} />);
    expect(await screen.findByText('.com.ng')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sync now' })).toBeNull();
    unmount();

    const supportFetch = adminApi({ 'GET /admin-api/v1/auth/session': { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: ['ticket.reply'] } } } });
    render(<AdminApp path="/" fetchImpl={supportFetch} credentials={authenticator()} />);
    await screen.findByRole('heading', { name: 'Staff console' });
    expect(screen.queryByText('Domain costs')).toBeNull();
    expect(calls(supportFetch, 'GET /admin-api/v1/domain-costs')).toHaveLength(0);
  });
});

describe('Admin navigation', () => {
  it('lists only the pages the permissions allow and falls back to the overview', () => {
    expect(adminPagesFor(['ticket.reply']).map((p) => p.path)).toEqual(['/', '/account']);
    expect(adminPagesFor(['staff.manage', 'provider_credentials.manage', 'catalogue.view']).map((p) => p.path)).toEqual([
      '/',
      '/account',
      '/staff',
      '/provider-connections',
      '/domain-costs',
    ]);
    expect(adminPageForPath('/domain-costs', ['catalogue.view'])).toBe('domain-costs');
    expect(adminPageForPath('/domain-costs', ['ticket.reply'])).toBe('overview');
    expect(adminPageForPath('/nope', ['catalogue.view'])).toBe('overview');
  });

  it('shows a sidebar menu, gives each page its own address and follows back/forward', async () => {
    const user = userEvent.setup();
    window.history.replaceState(null, '', '/');
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: ['staff.manage', 'audit.view'] } } },
      'GET /admin-api/v1/staff': { status: 200, body: { staff: [], pending_grants: [] } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    const menu = await screen.findByRole('navigation', { name: 'Main' });
    expect(Array.from(menu.querySelectorAll('button')).map((b) => b.textContent)).toEqual(['Overview', 'Your sign-in', 'Staff']);
    expect(screen.getByText('Staff console', { selector: '.pc-topbar__env' })).toBeInTheDocument();

    await user.click(within(menu).getByRole('button', { name: 'Staff' }));
    expect(window.location.pathname).toBe('/staff');
    expect(await screen.findByRole('heading', { name: 'Invite a staff member' })).toBeInTheDocument();
    expect(within(menu).getByRole('button', { name: 'Staff' })).toHaveAttribute('aria-current', 'page');

    window.history.pushState(null, '', '/');
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(await screen.findByRole('heading', { name: 'Staff console' })).toBeInTheDocument();
  });
});

describe('Domain cost pages', () => {
  it('shows 15 extensions per page with previous/next, and a search starts again at page 1', async () => {
    const user = userEvent.setup();
    const items = Array.from({ length: 40 }, (_, i) => ({
      tld: `ext${String(i + 1).padStart(2, '0')}`,
      currency: 'USD',
      register: 1000 + i,
      renew: 1000,
      transfer: null,
      restore: null,
      min_years: 1,
      max_years: 10,
      status: 'active',
    }));
    const body = {
      provider: 'domainnameapi',
      environment: 'live',
      can_sync: false,
      daily_at: '02:30 WAT',
      pending_since: null,
      next_request_at: null,
      latest: { status: 'accepted', reason: null, items: 40, refused: 0, fetched_at: '2026-10-24T01:30:00+00:00' },
      quarantined: null,
      runs: [],
      items,
    };
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: { ...manager, staff: { ...manager.staff, permissions: ['catalogue.view'] } } },
      'GET /admin-api/v1/domain-costs': { status: 200, body },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText('.ext01')).toBeInTheDocument();
    expect(screen.getByText(/Showing 1–15 of 40\./)).toBeInTheDocument();
    expect(screen.queryByText('.ext16')).toBeNull();
    expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByText('.ext16')).toBeInTheDocument();
    expect(screen.queryByText('.ext01')).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByText(/Showing 31–40 of 40\./)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();

    await user.type(screen.getByLabelText('Find an extension'), 'ext');
    expect(screen.getByText(/Showing 1–15 of 40\./)).toBeInTheDocument();
    await user.type(screen.getByLabelText('Find an extension'), '2');
    expect(screen.getByText(/Showing 1–10 of 10\./)).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Domain cost pages' })).toBeNull();
  });
});
