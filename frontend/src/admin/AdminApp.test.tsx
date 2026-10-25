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

describe('API providers (THREAT-MODEL-PROVIDER-CONNECTIONS.md)', () => {
  const securityAdmin = { ...manager, staff: { ...manager.staff, permissions: ['staff.manage', 'provider_credentials.manage', 'audit.view'] } };
  const providers = {
    providers: [
      {
        provider: 'domainnameapi',
        label: 'DomainNameAPI (domain registrar)',
        category: 'domains',
        credential_id: 'domainnameapi-staging',
        fields: [
          { name: 'reseller_id', label: 'Reseller ID', secret: false, hint: 'Reseller Settings → Integration Information.' },
          { name: 'api_key', label: 'API key', secret: true, hint: null },
        ],
        configured_at: null,
        review_due_on: null,
        pending_since: null,
      },
      {
        provider: 'hetzner',
        label: 'Hetzner Cloud (servers)',
        category: 'servers',
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
    render(<AdminApp path="/api-providers/domain-registrars" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText('Not connected yet.')).toBeInTheDocument();
    expect(screen.queryByText('Hetzner Cloud (servers)')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Set credential' }));
    expect(screen.getByLabelText('Reseller ID')).toHaveAccessibleDescription('Reseller Settings → Integration Information.');
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
    render(<AdminApp path="/api-providers/server-providers" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/Connected\. Last set 20 Oct 2026, 09:00 WAT; review by 2027-10-20\./)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Replace credential' }));
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
    render(<AdminApp path="/api-providers/domain-registrars" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: 'Set credential' }));
    await user.click(screen.getByRole('button', { name: 'Save credential' }));

    expect(screen.getByText('Enter the reseller id.')).toBeInTheDocument();
    expect(screen.getByText('Enter the 6-digit code from your authenticator app.')).toBeInTheDocument();
    expect(calls(fetchImpl, 'POST /admin-api/v1/provider-connections')).toHaveLength(0);
  });

  it('puts registrars and server providers on their own pages under "API providers", and keeps the old address working', async () => {
    const user = userEvent.setup();
    window.history.replaceState(null, '', '/provider-connections');
    const fetchImpl = adminApi({ ...base, 'GET /admin-api/v1/provider-connections': { status: 200, body: providers } });
    render(<AdminApp path="/provider-connections" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByRole('heading', { name: 'Domain registrars' })).toBeInTheDocument();
    expect(await screen.findByText('DomainNameAPI (domain registrar)')).toBeInTheDocument();
    const menu = screen.getByRole('navigation', { name: 'Main' });
    const group = within(menu).getByRole('list', { name: 'API providers' });
    expect(Array.from(group.querySelectorAll('button')).map((b) => b.textContent)).toEqual(['Domain registrars', 'Server providers']);
    expect(within(group).getByRole('button', { name: 'Domain registrars' })).toHaveAttribute('aria-current', 'page');

    await user.click(within(group).getByRole('button', { name: 'Server providers' }));
    expect(window.location.pathname).toBe('/api-providers/server-providers');
    expect(await screen.findByText('Hetzner Cloud (servers)')).toBeInTheDocument();
    expect(screen.queryByText('DomainNameAPI (domain registrar)')).toBeNull();
  });

  it('is hidden from staff without provider_credentials.manage', async () => {
    const fetchImpl = adminApi({ ...base, 'GET /admin-api/v1/auth/session': { status: 200, body: manager } });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await screen.findByRole('heading', { name: 'Staff console' });
    expect(screen.queryByText('API providers')).toBeNull();
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

  it('switches registrar with tabs and asks that registrar to sync', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': { status: 200, body: costs({ providers: ['domainnameapi', 'namecheap'] }) },
      'GET /admin-api/v1/domain-costs?provider=namecheap': {
        status: 200,
        body: costs({ provider: 'namecheap', providers: ['domainnameapi', 'namecheap'], items: [{ tld: 'site', currency: 'USD', register: 199, renew: 3298, transfer: null, restore: null, min_years: 1, max_years: 10, status: 'promo' }] }),
      },
      'POST /admin-api/v1/domain-costs/sync': { status: 202, body: { status: 'queued' } },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/costs us at DomainNameAPI/)).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'DomainNameAPI' })).toHaveAttribute('aria-selected', 'true');

    await user.click(screen.getByRole('tab', { name: 'Namecheap' }));
    expect(await screen.findByText(/costs us at Namecheap/)).toBeInTheDocument();
    expect(screen.getByText('.site')).toBeInTheDocument();
    expect(screen.queryByText('.com')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Sync now' }));
    await screen.findByText(/Sync requested\./);
    const [init] = calls(fetchImpl, 'POST /admin-api/v1/domain-costs/sync');
    expect(JSON.parse(String(init?.body))).toEqual({ provider: 'namecheap' });
  });

  it('always offers the Spaceship tab beside the registrars with a price list', async () => {
    const fetchImpl = adminApi({ 'GET /admin-api/v1/auth/session': { status: 200, body: catalogue }, 'GET /admin-api/v1/domain-costs': { status: 200, body: costs() } });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/costs us at DomainNameAPI/)).toBeInTheDocument();
    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['DomainNameAPI', 'Spaceship']);
    expect(screen.getByRole('tab', { name: 'Spaceship' })).toHaveAttribute('aria-selected', 'false');
  });

  const probeTlds = ['com', 'net', 'org', 'info', 'biz', 'co', 'io', 'me', 'xyz', 'site', 'online', 'store', 'tech', 'app', 'dev', 'us', 'uk', 'co.uk', 'africa', 'com.ng'];
  const check = (overrides: Record<string, unknown> = {}) => ({
    connected: true,
    can_run: true,
    pending_since: null,
    next_request_at: null,
    probe_tlds: probeTlds,
    latest: null,
    ...overrides,
  });

  it('runs a Spaceship price check with the CSRF token and shows the answer per extension, as text', async () => {
    const user = userEvent.setup();
    const done = {
      checked_at: '2026-10-25T08:30:00+00:00',
      trigger: 'manual',
      outcome: 'done',
      reason: null,
      asked: 20,
      answered: 2,
      priced: 1,
      items: [
        { tld: 'com.ng', result: 'missing', register: null, currency: null, other_priced: [] },
        { tld: 'com', result: 'available', register: 998, currency: 'USD', other_priced: ['renew'] },
        { tld: 'net', result: '<img src=x>', register: null, currency: null, other_priced: [] },
      ],
    };
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': { status: 200, body: costs() },
      'GET /admin-api/v1/spaceship-price-check': [{ status: 200, body: check() }, { status: 200, body: check({ latest: done, next_request_at: '2026-10-25T08:40:00+00:00' }) }],
      'POST /admin-api/v1/spaceship-price-check/run': { status: 202, body: { status: 'queued' } },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('tab', { name: 'Spaceship' }));
    expect(await screen.findByText('No check has run yet.')).toBeInTheDocument();
    expect(screen.getByText(/one made-up, unregistered name in each of 20 common/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Run price check' }));
    expect(await screen.findByText(/Price check requested\./)).toBeInTheDocument();
    const [init] = calls(fetchImpl, 'POST /admin-api/v1/spaceship-price-check/run');
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
    expect(JSON.parse(String(init?.body))).toEqual({});

    expect(await screen.findByText(/Spaceship answered 2 of 20 names and showed a one-year price for 1\. Its prices can be fetched automatically/)).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Spaceship price check' });
    const rows = within(table).getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent));
    expect(rows).toEqual([
      ['.com', 'Available', 'USD 9.98', 'renew'],
      ['.net', '<img src=x>', '—', '—'],
      ['.com.ng', 'No answer', '—', '—'],
    ]);
    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByRole('button', { name: 'Run price check' })).toBeDisabled();
  });

  it('asks to connect Spaceship first, and explains a check that found no ordinary prices or failed', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': { status: 200, body: costs() },
      'GET /admin-api/v1/spaceship-price-check': [
        { status: 200, body: check({ connected: false }) },
        { status: 200, body: check({ latest: { checked_at: '2026-10-25T08:30:00+00:00', trigger: 'manual', outcome: 'done', reason: null, asked: 20, answered: 20, priced: 0, items: [] } }) },
        { status: 200, body: check({ latest: { checked_at: '2026-10-25T08:50:00+00:00', trigger: 'manual', outcome: 'failed', reason: 'unauthorised', asked: 20, answered: 0, priced: 0, items: [] } }) },
      ],
    });
    const { unmount } = render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('tab', { name: 'Spaceship' }));
    expect(await screen.findByText(/Spaceship is not connected yet/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Run price check' })).toBeDisabled();
    unmount();

    const second = render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('tab', { name: 'Spaceship' }));
    expect(await screen.findByText(/It does not show ordinary prices, so Spaceship costs would have to be entered by hand/)).toBeInTheDocument();
    second.unmount();

    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('tab', { name: 'Spaceship' }));
    expect(await screen.findByText(/The check failed: the registrar refused the API key/)).toBeInTheDocument();
    expect(screen.getByText(/Check that the Spaceship key has the domains:read scope\./)).toBeInTheDocument();
  });

  it('lets Finance read the Spaceship check but not run one', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: finance },
      'GET /admin-api/v1/domain-costs': { status: 200, body: costs({ can_sync: false }) },
      'GET /admin-api/v1/spaceship-price-check': { status: 200, body: check({ can_run: false }) },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('tab', { name: 'Spaceship' }));
    expect(await screen.findByText('No check has run yet.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Run price check' })).toBeNull();

    await user.click(screen.getByRole('tab', { name: 'DomainNameAPI' }));
    expect(await screen.findByText(/costs us at DomainNameAPI/)).toBeInTheDocument();
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
            { name: 'co.uk', reason: 'bad_price', detail: 'reg:zero' },
            { name: 'au', reason: 'bad_price', detail: 'renew:format' },
            { name: 'eco', reason: 'unsupported_currency', detail: 'currency:missing' },
            { name: 'moscow', reason: 'bad_period', detail: 'min:1_max:0' },
            { name: 'aaa.pro', reason: 'bad_price', detail: 'reg:negative' },
          ],
        }),
      },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByText('Not on sale yet (10)'));
    expect(screen.getByText('Not offered by this registrar (registration price is negative): 1')).toBeInTheDocument();
    expect(screen.getByText('A price we could not read (registration price is 0): 1')).toBeInTheDocument();
    expect(screen.getByText('A price we could not read (renewal price is not a plain number): 1')).toBeInTheDocument();
    expect(screen.getByText('Priced in a currency we do not accept yet (no currency given): 1')).toBeInTheDocument();
    expect(screen.getByText('Registration periods we could not read (minimum 1, maximum 0 years): 1')).toBeInTheDocument();
    expect(screen.getByText('Priced in a currency we do not accept yet (TRY): 2')).toBeInTheDocument();
    expect(screen.getByText('.com.tr, .net.tr')).toBeInTheDocument();
    expect(screen.getByText('No one-year registration price (shortest term 2 years): 1')).toBeInTheDocument();
    expect(screen.getByText('1 without a readable name')).toBeInTheDocument();
    expect(screen.getByText('.<img src=x>')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('says how the latest availability check went, in plain words (DS-13)', async () => {
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': {
        status: 200,
        body: costs({ availability_check: { outcome: 'malformed', asked: 3, answered: 0, at: '2026-10-25T08:40:00Z' } }),
      },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText(/Last availability check .*failed: the registrar sent an answer we could not read\./)).toBeInTheDocument();
  });

  it('lists the fields the registrar sends, period-like ones first, as text (DR-16)', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
      'GET /admin-api/v1/domain-costs': {
        status: 200,
        body: costs({
          feed_fields: [
            { path: 'name', items: 733, kinds: ['text'], min: null, max: null },
            { path: 'gracePeriod', items: 600, kinds: ['text'], min: 30, max: 45 },
            { path: 'prices[].register.period', items: 733, kinds: ['text'], min: 1, max: 1 },
            { path: '<img src=x>', items: 1, kinds: ['text'], min: null, max: null },
          ],
        }),
      },
    });
    render(<AdminApp path="/domain-costs" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByText('Fields the registrar sends (4)'));
    const table = screen.getByRole('table', { name: 'Fields in the registrar price list' });
    const rows = within(table).getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent));
    expect(rows).toEqual([
      ['gracePeriod', '600', 'text', '30–45'],
      ['prices[].register.period', '733', 'text', '1'],
      ['<img src=x>', '1', 'text', '—'],
      ['name', '733', 'text', '—'],
    ]);
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
    expect(screen.queryByText(/whitelisted/)).toBeNull();
  });

  it('names a refused key fix only on its own registrar tab', async () => {
    const failed = { trigger: 'manual', outcome: 'failed', reason: 'unauthorised', attempts: 1, started_at: '2026-10-24T03:00:00+00:00', finished_at: '2026-10-24T03:00:01+00:00' };
    const at = (provider: string) =>
      adminApi({
        'GET /admin-api/v1/auth/session': { status: 200, body: catalogue },
        'GET /admin-api/v1/domain-costs': { status: 200, body: costs({ provider, runs: [failed] }) },
      });
    const namecheap = render(<AdminApp path="/domain-costs" fetchImpl={at('namecheap')} credentials={authenticator()} />);
    expect(await screen.findByText(/failed: the registrar refused the API key\. Fix it under API providers → Domain registrars\. Also check that the server IP address is whitelisted in Namecheap\./)).toBeInTheDocument();
    namecheap.unmount();

    render(<AdminApp path="/domain-costs" fetchImpl={at('dynadot')} credentials={authenticator()} />);
    expect(await screen.findByText(/failed: the registrar refused the API key\. Fix it under API providers → Domain registrars\. Use the production key and secret, not the sandbox ones\./)).toBeInTheDocument();
    expect(screen.queryByText(/whitelisted/)).toBeNull();
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
      '/api-providers/domain-registrars',
      '/api-providers/server-providers',
      '/domain-extensions',
      '/domain-costs',
    ]);
    expect(adminPageForPath('/domain-extensions', ['catalogue.view'])).toBe('domain-extensions');
    expect(adminPageForPath('/domain-extensions', ['ticket.reply'])).toBe('overview');
    expect(adminPageForPath('/provider-connections', ['provider_credentials.manage'])).toBe('providers-domains');
    expect(adminPageForPath('/provider-connections', ['catalogue.view'])).toBe('overview');
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
    expect(Array.from(menu.querySelectorAll('button')).map((b) => b.textContent)).toEqual(['Overview', 'Your sign-in', 'Staff', 'Audit log']);
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

describe('Audit log (identity threat model §6.24)', () => {
  const security = { ...manager, staff: { ...manager.staff, permissions: ['staff.manage', 'audit.view'] } };
  const finance = { ...manager, staff: { ...manager.staff, roles: ['finance'], permissions: ['catalogue.view', 'audit.view'] } };
  const HEAD = { seq: 120, hash: 'f'.repeat(64) };
  const event = (seq: number, overrides: Record<string, unknown> = {}) => ({
    seq,
    occurred_at: '2026-10-24T09:12:00+00:00',
    type: 'auth.sign_in.failed',
    outcome: 'failure',
    identity_id: 'c'.repeat(32),
    account_id: null,
    details: { reason: 'wrong_password' },
    ip: '203.0.113.45',
    ip_network: '203.0.113.0/24',
    country: 'NG',
    region: 'Lagos',
    city: 'Ikeja',
    user_agent: 'Mozilla/5.0 <script>alert(1)</script>',
    device: { browser: 'Chrome', browser_version: '146', os: 'Windows', os_version: '10', device_type: 'desktop' },
    ...overrides,
  });
  const page = (events: unknown[], extra: Record<string, unknown> = {}) => ({ head: HEAD, full_context: true, groups: ['auth', 'staff'], next_before: null, events, ...extra });

  it('lists events newest first with plain words, the raw type, who, where and the device, as text', async () => {
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: security },
      'GET /admin-api/v1/audit-events': {
        status: 200,
        body: page([
          event(120, { type: 'staff.signed_in', outcome: 'success', identity_id: null, details: { subject: 'a'.repeat(32), method: 'password_codes' } }),
          event(119, { type: '<img src=x onerror=alert(1)>' }),
          event(118, { ip: null, ip_network: null, country: null, region: null, city: null, user_agent: null, device: null }),
        ]),
      },
    });
    render(<AdminApp path="/audit-log" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByRole('heading', { name: 'Audit log' })).toBeInTheDocument();
    const rows = within(await screen.findByRole('table')).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(3);
    const [first, second, third] = rows as [HTMLElement, HTMLElement, HTMLElement];
    expect(within(first).getByTitle('Staff ' + 'a'.repeat(32))).toHaveTextContent('staff aaaaaaaa…');
    expect(within(first).getByText('method: password_codes')).toBeInTheDocument();
    expect(within(second).getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
    expect(within(second).getByText('Failed')).toBeInTheDocument();
    expect(within(second).getByText('203.0.113.45')).toBeInTheDocument();
    expect(within(second).getByText('Ikeja, Lagos, Nigeria')).toBeInTheDocument();
    expect(within(second).getByText('Chrome 146 on Windows (desktop)')).toBeInTheDocument();
    expect(within(third).getByText('Sign-in failed')).toBeInTheDocument();
    expect(within(third).getByText('auth.sign_in.failed')).toBeInTheDocument();
    expect(within(third).getByText('not kept')).toBeInTheDocument();
    expect(screen.getByText(/Chain head #120/)).toBeInTheDocument();
  });

  it('shows the network, not the full address, to staff without staff.manage', async () => {
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: finance },
      'GET /admin-api/v1/audit-events': { status: 200, body: page([event(5, { ip: null, user_agent: null })], { full_context: false }) },
    });
    render(<AdminApp path="/audit-log" fetchImpl={fetchImpl} credentials={authenticator()} />);

    expect(await screen.findByText('203.0.113.0/24')).toBeInTheDocument();
    expect(screen.getByText(/Full IP addresses are shown to security administrators only/)).toBeInTheDocument();
  });

  it('sends the chosen filters, pages older and newer by cursor, and explains a bad ID', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: security },
      'GET /admin-api/v1/audit-events': { status: 200, body: page([event(120)]) },
      [`GET /admin-api/v1/audit-events?outcome=failure&group=staff&subject=${'a'.repeat(32)}`]: { status: 200, body: page([event(90)], { next_before: 90 }) },
      [`GET /admin-api/v1/audit-events?outcome=failure&group=staff&subject=${'a'.repeat(32)}&before=90`]: { status: 200, body: page([event(40)]) },
      'GET /admin-api/v1/audit-events?subject=nope': { status: 422, body: { type: 'invalid_filter' } },
    });
    render(<AdminApp path="/audit-log" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await screen.findByText(/Chain head/);

    await user.selectOptions(screen.getByLabelText('Area'), 'staff');
    await user.selectOptions(screen.getByLabelText('Outcome'), 'failure');
    await user.type(screen.getByLabelText('Customer, account or staff ID'), 'A'.repeat(32));
    await user.click(screen.getByRole('button', { name: 'Search' }));
    expect(await screen.findByRole('cell', { name: '90' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Older' }));
    expect(await screen.findByRole('cell', { name: '40' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Older' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Newer' }));
    expect(await screen.findByRole('cell', { name: '90' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Newer' })).toBeDisabled();

    await user.selectOptions(screen.getByLabelText('Area'), '');
    await user.selectOptions(screen.getByLabelText('Outcome'), '');
    await user.clear(screen.getByLabelText('Customer, account or staff ID'));
    await user.type(screen.getByLabelText('Customer, account or staff ID'), 'nope');
    await user.click(screen.getByRole('button', { name: 'Search' }));
    expect(await screen.findByText(/That ID is not valid/)).toBeInTheDocument();
  });

  it('"Only this" shows every event about that customer or staff member', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: security },
      'GET /admin-api/v1/audit-events': { status: 200, body: page([event(120)]) },
      [`GET /admin-api/v1/audit-events?subject=${'c'.repeat(32)}`]: { status: 200, body: page([event(77)]) },
    });
    render(<AdminApp path="/audit-log" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await user.click(await screen.findByRole('button', { name: `Only events about customer ${'c'.repeat(32)}` }));
    expect(await screen.findByRole('cell', { name: '77' })).toBeInTheDocument();
    expect(screen.getByLabelText('Customer, account or staff ID')).toHaveValue('c'.repeat(32));
  });

  it('is not in the menu without audit.view', async () => {
    expect(adminPagesFor(['catalogue.view']).map((p) => p.id)).not.toContain('audit-log');
    expect(adminPagesFor(['audit.view']).map((p) => p.id)).toContain('audit-log');
    expect(adminPageForPath('/audit-log', ['catalogue.view'])).toBe('overview');
  });
});

describe('Staff sessions (identity threat model §6.25)', () => {
  const security = { ...manager, staff: { ...manager.staff, permissions: ['staff.manage', 'audit.view'] } };
  const device = (id: string, current: boolean, deviceName: string) => ({
    id,
    current,
    device: deviceName,
    location: 'Ikeja, Lagos, Nigeria',
    ip: '203.0.113.45',
    signed_in_at: '2026-10-24T09:00:00+00:00',
    last_active_at: '2026-10-24T09:10:00+00:00',
  });

  it('lists own devices and signs one out with a fresh authenticator code', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: security },
      'GET /admin-api/v1/account/sessions': [
        { status: 200, body: { sessions: [device('a'.repeat(32), true, 'Chrome 146 on Windows (desktop)'), device('b'.repeat(32), false, 'Safari 17 on iOS (mobile)')] } },
        { status: 200, body: { sessions: [device('a'.repeat(32), true, 'Chrome 146 on Windows (desktop)')] } },
      ],
      'POST /admin-api/v1/account/sessions/revoke': [
        { status: 401, body: { code: 'step_up_required' } },
        { status: 204 },
      ],
    });
    render(<AdminApp path="/account" fetchImpl={fetchImpl} credentials={authenticator()} />);

    const table = await screen.findByRole('table', { name: 'Devices signed in to your staff account' });
    expect(within(table).getByText('This device')).toBeInTheDocument();
    await user.click(within(table).getByRole('button', { name: 'Sign out' }));

    const form = screen.getByRole('form', { name: 'Sign out Safari 17 on iOS (mobile)' });
    await user.click(within(form).getByRole('button', { name: 'Sign out device' }));
    expect(within(form).getByText('Enter the 6-digit code from your app.')).toBeInTheDocument();
    expect(calls(fetchImpl, 'POST /admin-api/v1/account/sessions/revoke')).toHaveLength(0);

    await user.type(within(form).getByLabelText('Authenticator code'), '111111');
    await user.click(within(form).getByRole('button', { name: 'Sign out device' }));
    expect(await screen.findByText(/That authenticator code is not right/)).toBeInTheDocument();

    await user.type(within(form).getByLabelText('Authenticator code'), '222222');
    await user.click(within(form).getByRole('button', { name: 'Sign out device' }));
    expect(await screen.findByText('That device is signed out.')).toBeInTheDocument();
    expect(await screen.findByText('You are not signed in anywhere else.')).toBeInTheDocument();

    const [, init] = calls(fetchImpl, 'POST /admin-api/v1/account/sessions/revoke');
    expect(JSON.parse(String(init?.body))).toEqual({ session: 'b'.repeat(32), code: '222222' });
    expect((init?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
  });

  it('lets a staff manager sign another active member out everywhere, never themselves', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 200, body: security },
      'GET /admin-api/v1/staff': {
        status: 200,
        body: {
          staff: [
            { id: manager.staff.id, full_name: 'Ada Lovelace', email: 'ada@example.ng', status: 'active', roles: ['security_admin'] },
            { id: 'c'.repeat(32), full_name: 'Bob Smith', email: 'bob@example.ng', status: 'active', roles: ['catalogue'] },
            { id: 'd'.repeat(32), full_name: 'Cy Invited', email: 'cy@example.ng', status: 'invited', roles: [] },
          ],
          pending_grants: [],
        },
      },
      'POST /admin-api/v1/staff/sessions/end': { status: 200, body: { ended: 2 } },
    });
    render(<AdminApp path="/staff" fetchImpl={fetchImpl} credentials={authenticator()} />);

    await screen.findByText('Bob Smith');
    const buttons = screen.getAllByRole('button', { name: 'Sign out everywhere' });
    expect(buttons).toHaveLength(1);
    await user.click(buttons[0] as HTMLElement);

    const form = screen.getByRole('form', { name: 'Sign Bob Smith out everywhere' });
    expect(within(form).getByText(/Their roles and access stay the same/)).toBeInTheDocument();
    await user.type(within(form).getByLabelText('Authenticator code'), '123456');
    await user.click(within(form).getByRole('button', { name: 'Sign out everywhere' }));

    expect(await screen.findByText('Bob Smith is signed out everywhere (2 sessions ended).')).toBeInTheDocument();
    const [init] = calls(fetchImpl, 'POST /admin-api/v1/staff/sessions/end');
    expect(JSON.parse(String(init?.body))).toEqual({ staff_id: 'c'.repeat(32), code: '123456' });
  });
});
