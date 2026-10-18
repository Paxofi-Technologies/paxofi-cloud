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
  staff: { id: 's-1', full_name: 'Ada Lovelace', email: 'ada@example.ng', roles: ['security_admin'], permissions: ['staff.manage', 'audit.view'] },
  csrf_token: 'c'.repeat(43),
};

describe('invitationToken', () => {
  it('accepts only a well-formed 256-bit token', () => {
    expect(invitationToken(`#token=${TOKEN}`)).toBe(TOKEN);
    expect(invitationToken('#token=short')).toBeNull();
    expect(invitationToken('')).toBeNull();
  });
});

describe('Staff enrolment (D-11)', () => {
  it('registers two passkeys, then the account is active', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'POST /admin-api/v1/enrolment/options': { status: 200, body: { ceremony_id: 'cer-1', options: creationOptions } },
      'POST /admin-api/v1/enrolment': [
        { status: 200, body: { passkeys: 1, active: false } },
        { status: 200, body: { passkeys: 2, active: true } },
      ],
    });
    const credentials = authenticator();
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} credentials={credentials} />);

    await user.type(screen.getByLabelText('Name for this passkey (optional)'), ' YubiKey ');
    await user.click(screen.getByRole('button', { name: 'Register first passkey' }));
    await screen.findByRole('heading', { name: 'Add your second passkey' });
    await user.click(screen.getByRole('button', { name: 'Register second passkey' }));
    await screen.findByRole('heading', { name: 'Your staff account is active' });

    expect(credentials.create).toHaveBeenCalledTimes(2);
    const finishes = calls(fetchImpl, 'POST /admin-api/v1/enrolment');
    const first = JSON.parse(String(finishes[0]?.body)) as Record<string, unknown>;
    expect(first.token).toBe(TOKEN);
    expect(first.ceremony_id).toBe('cer-1');
    expect(first.label).toBe('YubiKey');
    expect((first.credential as Record<string, unknown>).type).toBe('public-key');
    expect(JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/enrolment/options')[0]?.body))).toEqual({ token: TOKEN });
  });

  it('removes the invitation token from the address bar and history (§6.19)', () => {
    window.history.replaceState(null, '', `/enrol#token=${TOKEN}`);
    render(<AdminApp path="/enrol" fetchImpl={adminApi({})} credentials={authenticator()} />);
    expect(window.location.hash).toBe('');
    expect(window.location.pathname).toBe('/enrol');
    expect(screen.getByRole('button', { name: 'Register first passkey' })).toBeInTheDocument();
  });

  it('explains a dead invitation without contacting the server for a malformed link', () => {
    const fetchImpl = adminApi({});
    render(<AdminApp path="/enrol" initialHash="#token=nope" fetchImpl={fetchImpl} credentials={authenticator()} />);
    expect(screen.getByRole('heading', { name: 'This invitation is not valid' })).toBeInTheDocument();
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('shows the invalid-invitation page when the server refuses the token', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ 'POST /admin-api/v1/enrolment/options': { status: 400, body: { code: 'invitation_invalid' } } });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(screen.getByRole('button', { name: 'Register first passkey' }));
    await screen.findByRole('heading', { name: 'This invitation is not valid' });
  });

  it('does not call the server to finish when the passkey prompt is cancelled', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ 'POST /admin-api/v1/enrolment/options': { status: 200, body: { ceremony_id: 'cer-1', options: creationOptions } } });
    const credentials = authenticator();
    credentials.create.mockImplementation(() => Promise.reject(new DOMException('no', 'NotAllowedError')));
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} credentials={credentials} />);
    await user.click(screen.getByRole('button', { name: 'Register first passkey' }));
    await screen.findByText(/cancelled or timed out/);
    expect(calls(fetchImpl, 'POST /admin-api/v1/enrolment')).toHaveLength(0);
  });

  it('says so when the browser cannot use passkeys', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({ 'POST /admin-api/v1/enrolment/options': { status: 200, body: { ceremony_id: 'cer-1', options: creationOptions } } });
    render(<AdminApp path="/enrol" initialHash={`#token=${TOKEN}`} fetchImpl={fetchImpl} credentials={null} />);
    await user.click(screen.getByRole('button', { name: 'Register first passkey' }));
    await screen.findByText(/cannot use passkeys/);
  });
});

describe('Staff sign-in (IAM-008)', () => {
  it('signs in with a passkey, then shows the console', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': [{ status: 401, body: { code: 'unauthenticated' } }, { status: 200, body: manager }],
      'POST /admin-api/v1/auth/passkey/options': { status: 200, body: { ceremony_id: 'cer-2', options: requestOptions } },
      'POST /admin-api/v1/auth/passkey': { status: 200, body: { status: 'signed_in', csrf_token: manager.csrf_token } },
      'GET /admin-api/v1/staff': { status: 200, body: { staff: [], pending_grants: [] } },
    });
    const credentials = authenticator();
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={credentials} />);

    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
    await screen.findByRole('heading', { name: 'Staff console' });
    expect(credentials.get).toHaveBeenCalledOnce();
    const body = JSON.parse(String(calls(fetchImpl, 'POST /admin-api/v1/auth/passkey')[0]?.body)) as Record<string, unknown>;
    expect(body.ceremony_id).toBe('cer-2');
    expect(((body.credential as Record<string, unknown>).response as Record<string, unknown>).userHandle).toBe('BA');
  });

  it('shows one generic message when the server refuses the passkey', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 401 },
      'POST /admin-api/v1/auth/passkey/options': { status: 200, body: { ceremony_id: 'cer-2', options: requestOptions } },
      'POST /admin-api/v1/auth/passkey': { status: 401, body: { code: 'sign_in_failed' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
    await screen.findByText(/Sign-in failed\. Use a passkey registered/);
  });

  it('reports throttling with the wait', async () => {
    const user = userEvent.setup();
    const fetchImpl = adminApi({
      'GET /admin-api/v1/auth/session': { status: 401 },
      'POST /admin-api/v1/auth/passkey/options': { status: 429, body: { code: 'too_many_attempts' }, headers: { 'retry-after': '120' } },
    });
    render(<AdminApp path="/" fetchImpl={fetchImpl} credentials={authenticator()} />);
    await user.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
    await screen.findByText('Too many attempts. Please try again in 2 minutes.');
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
    await screen.findByRole('button', { name: 'Sign in with a passkey' });
    expect((calls(fetchImpl, 'POST /admin-api/v1/auth/sign-out')[0]?.headers as Record<string, string>)['X-CSRF-Token']).toBe(manager.csrf_token);
  });
});
