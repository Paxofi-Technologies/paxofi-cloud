import { useCallback, useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';
import { Alert, Badge, Button, Card, Logo, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import {
  INVITABLE_ROLES,
  approveGrant,
  ceremony,
  enrolmentOptions,
  fetchStaff,
  fetchStaffSession,
  finishEnrolment,
  finishSignIn,
  inviteStaff,
  roleLabel,
  signInOptions,
  signOutStaff,
  type StaffList,
  type StaffSession,
  type StaffSessionState,
} from './adminApi';
import { createPasskey, getPasskey, type CredentialsApi } from './webauthn';

export interface AdminAppProps {
  path?: string;
  initialHash?: string;
  fetchImpl?: typeof fetch | undefined;
  /** navigator.credentials; tests pass a software authenticator. */
  credentials?: CredentialsApi | null | undefined;
}

const TOKEN = /^[A-Za-z0-9_-]{43}$/;

/** Parses `#token=…` from an invitation link; anything malformed is no token. */
export function invitationToken(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  return token !== null && TOKEN.test(token) ? token : null;
}

/**
 * The PaxofiCloud staff console (SRS ACC-005, IAM-008; identity threat model
 * §6.17–§6.19). Served only on the admin host. Staff sign in with a passkey
 * only: there is no password form to phish.
 */
export function AdminApp({ path = window.location.pathname, initialHash, fetchImpl, credentials }: AdminAppProps) {
  if (path === '/enrol') return <EnrolScreen initialHash={initialHash} fetchImpl={fetchImpl} credentials={credentials} />;

  return <Console fetchImpl={fetchImpl} credentials={credentials} />;
}

function StaffAuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="pc-auth">
      <div className="pc-auth__panel">
        <Logo />
        {children}
      </div>
      <div className="pc-auth__aside" aria-hidden="true">
        <div className="pc-auth__aside-inner">
          <p className="pc-auth__eyebrow">PaxofiCloud staff</p>
          <p className="pc-auth__headline">Staff access is by passkey only.</p>
          <ul className="pc-auth__points">
            <li>Every action is recorded in the audit log</li>
            <li>Access follows your role, nothing more</li>
            <li>Never share a passkey or an invitation link</li>
          </ul>
        </div>
      </div>
    </div>
  );
}

function ceremonyProblem(result: ApiResult, fallback: string): string {
  if (result.kind === 'throttled') return `Too many attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'unavailable') return 'We could not reach PaxofiCloud. Check your connection and try again.';
  return fallback;
}

const UNSUPPORTED = 'This browser cannot use passkeys. Use a current version of Chrome, Edge, Safari or Firefox.';

type EnrolState =
  | { kind: 'ready'; passkeys: number }
  | { kind: 'active' }
  | { kind: 'invalid' }
  | { kind: 'limit' };

function EnrolScreen({ initialHash, fetchImpl, credentials }: { initialHash?: string | undefined; fetchImpl?: typeof fetch | undefined; credentials?: CredentialsApi | null | undefined }) {
  // Read once (StrictMode may run initialisers twice), then strip from the address bar.
  const [token] = useState(() => invitationToken(initialHash ?? window.location.hash));
  const [state, setState] = useState<EnrolState>(token === null ? { kind: 'invalid' } : { kind: 'ready', passkeys: 0 });
  const [label, setLabel] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (window.location.hash !== '') window.history.replaceState(null, '', window.location.pathname + window.location.search);
  }, []);

  async function addPasskey(event: FormEvent) {
    event.preventDefault();
    if (token === null || state.kind !== 'ready') return;
    setProblem(null);
    setLoading(true);
    try {
      const started = await enrolmentOptions(token, fetchImpl);
      const begun = ceremony(started);
      if (begun === null) {
        if (started.kind === 'rejected' && started.code === 'invitation_invalid') setState({ kind: 'invalid' });
        else if (started.kind === 'rejected' && started.code === 'passkey_limit_reached') setState({ kind: 'limit' });
        else setProblem(ceremonyProblem(started, 'We could not start passkey setup. Please try again.'));
        return;
      }
      const created = await createPasskey(begun.options, credentials === undefined ? undefined : credentials);
      if (created.kind === 'unsupported') {
        setProblem(UNSUPPORTED);
        return;
      }
      if (created.kind === 'cancelled') {
        setProblem('Passkey setup was cancelled or timed out. Please try again.');
        return;
      }
      const finished = await finishEnrolment(token, begun.ceremonyId, created.credential, label.trim(), fetchImpl);
      if (finished.kind !== 'ok') {
        if (finished.kind === 'rejected' && finished.code === 'invitation_invalid') setState({ kind: 'invalid' });
        else if (finished.kind === 'rejected' && finished.code === 'passkey_limit_reached') setState({ kind: 'limit' });
        else setProblem(ceremonyProblem(finished, 'This passkey could not be registered. Use a different authenticator and try again.'));
        return;
      }
      const data = finished.data as Record<string, unknown> | null;
      const count = typeof data?.passkeys === 'number' ? data.passkeys : state.passkeys + 1;
      setLabel('');
      setState(data?.active === true ? { kind: 'active' } : { kind: 'ready', passkeys: count });
    } finally {
      setLoading(false);
    }
  }

  return (
    <StaffAuthLayout>
      <div className="pc-auth__form" aria-live="polite">
        {state.kind === 'invalid' && (
          <div>
            <h1>This invitation is not valid</h1>
            <p className="pc-muted">
              The link has expired, was already used, or was copied incompletely. Ask a security administrator to send you a new invitation.
            </p>
          </div>
        )}
        {state.kind === 'limit' && (
          <div>
            <h1>Passkey limit reached</h1>
            <p className="pc-muted">This account already has the maximum number of passkeys. Ask a security administrator for help.</p>
          </div>
        )}
        {state.kind === 'active' && (
          <>
            <div>
              <h1>Your staff account is active</h1>
              <p className="pc-muted">Both passkeys are registered. Sign in with either of them.</p>
            </div>
            <a className="pc-button pc-button--primary pc-button--md pc-button--block" href="/">
              <span>Sign in</span>
            </a>
          </>
        )}
        {state.kind === 'ready' && (
          <form className="pc-auth__form" onSubmit={addPasskey} noValidate>
            <div>
              <h1>{state.passkeys === 0 ? 'Set up your staff passkeys' : 'Add your second passkey'}</h1>
              <p className="pc-muted">
                {state.passkeys === 0
                  ? 'Staff sign in with passkeys only. Register two (for example a security key and this computer) so losing one does not lock you out.'
                  : 'One passkey is registered. Register a second, different authenticator to activate your account.'}
              </p>
            </div>
            <p className="pc-muted">Passkey {Math.min(state.passkeys + 1, 2)} of 2</p>
            {problem && <Alert tone="danger">{problem}</Alert>}
            <TextField
              label="Name for this passkey (optional)"
              hint="For example: YubiKey, Work laptop"
              maxLength={64}
              value={label}
              onChange={(e) => setLabel(e.target.value)}
            />
            <Button type="submit" block loading={loading}>
              {state.passkeys === 0 ? 'Register first passkey' : 'Register second passkey'}
            </Button>
          </form>
        )}
      </div>
    </StaffAuthLayout>
  );
}

function SignIn({ fetchImpl, credentials, onSignedIn }: { fetchImpl?: typeof fetch | undefined; credentials?: CredentialsApi | null | undefined; onSignedIn: () => void }) {
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function signIn() {
    setProblem(null);
    setLoading(true);
    try {
      const started = await signInOptions(fetchImpl);
      const begun = ceremony(started);
      if (begun === null) {
        setProblem(ceremonyProblem(started, 'We could not start sign-in. Please try again.'));
        return;
      }
      const asserted = await getPasskey(begun.options, credentials === undefined ? undefined : credentials);
      if (asserted.kind === 'unsupported') {
        setProblem(UNSUPPORTED);
        return;
      }
      if (asserted.kind === 'cancelled') {
        setProblem('Sign-in was cancelled or timed out. Please try again.');
        return;
      }
      const finished = await finishSignIn(begun.ceremonyId, asserted.credential, fetchImpl);
      if (finished.kind === 'ok') onSignedIn();
      else setProblem(ceremonyProblem(finished, 'Sign-in failed. Use a passkey registered for your PaxofiCloud staff account.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <StaffAuthLayout>
      <div className="pc-auth__form">
        <div>
          <h1>Staff sign-in</h1>
          <p className="pc-muted">Use one of the passkeys you registered for your staff account.</p>
        </div>
        {problem && <Alert tone="danger">{problem}</Alert>}
        <Button block loading={loading} onClick={() => void signIn()}>
          Sign in with a passkey
        </Button>
      </div>
    </StaffAuthLayout>
  );
}

function Console({ fetchImpl, credentials }: { fetchImpl?: typeof fetch | undefined; credentials?: CredentialsApi | null | undefined }) {
  const [state, setState] = useState<StaffSessionState | 'loading'>('loading');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    void fetchStaffSession(fetchImpl).then((next) => {
      if (active) setState(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, attempt]);

  const reload = useCallback(() => setAttempt((n) => n + 1), []);

  if (state === 'loading') {
    return (
      <p className="pc-loading" role="status">
        Loading…
      </p>
    );
  }
  if (state.kind === 'signed_out') return <SignIn fetchImpl={fetchImpl} credentials={credentials} onSignedIn={reload} />;
  if (state.kind === 'unavailable') {
    return (
      <StaffAuthLayout>
        <div className="pc-auth__form">
          <Alert tone="danger">We could not reach PaxofiCloud. Check your connection and try again.</Alert>
          <Button onClick={reload}>Try again</Button>
        </div>
      </StaffAuthLayout>
    );
  }

  return <Dashboard session={state.session} fetchImpl={fetchImpl} onSignedOut={reload} />;
}

function Dashboard({ session, fetchImpl, onSignedOut }: { session: StaffSession; fetchImpl?: typeof fetch | undefined; onSignedOut: () => void }) {
  const [signingOut, setSigningOut] = useState(false);
  const canManage = session.permissions.includes('staff.manage');

  async function signOut() {
    setSigningOut(true);
    await signOutStaff(session.csrfToken, fetchImpl);
    setSigningOut(false);
    onSignedOut();
  }

  return (
    <div className="pc-admin">
      <header className="pc-topbar">
        <Logo />
        <div className="pc-topbar__user">
          <span className="pc-topbar__name">{session.fullName}</span>
          <button type="button" className="pc-link-button" onClick={() => void signOut()} disabled={signingOut}>
            Sign out
          </button>
        </div>
      </header>
      <main id="main" className="pc-content pc-stack" tabIndex={-1}>
        <div className="pc-page-header">
          <div>
            <h1>Staff console</h1>
            <p className="pc-muted">Signed in as {session.email}. The full admin console arrives in Sprint 9.</p>
          </div>
        </div>
        <Card title="Your access">
          <p>
            {session.roles.length === 0 ? (
              <span className="pc-muted">No roles yet. Ask a security administrator to grant one.</span>
            ) : (
              session.roles.map((role) => (
                <span key={role} className="pc-admin__role">
                  <Badge tone="info">{roleLabel(role)}</Badge>
                </span>
              ))
            )}
          </p>
        </Card>
        {canManage && <StaffManagement session={session} fetchImpl={fetchImpl} />}
      </main>
    </div>
  );
}

function StaffManagement({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [list, setList] = useState<StaffList | 'loading'>('loading');
  const [version, setVersion] = useState(0);
  const [approving, setApproving] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void fetchStaff(fetchImpl).then((next) => {
      if (active) setList(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version]);

  async function approve(grantId: string) {
    setProblem(null);
    setApproving(grantId);
    const result = await approveGrant(session.csrfToken, grantId, fetchImpl);
    setApproving(null);
    if (result.kind !== 'ok') {
      setProblem(
        result.kind === 'rejected' && result.code === 'same_person'
          ? 'A grant must be approved by a different staff manager.'
          : 'The grant could not be approved. Reload and try again.',
      );
    }
    setVersion((n) => n + 1);
  }

  const names = new Map(list !== 'loading' && list.kind === 'ok' ? list.staff.map((m) => [m.id, m.fullName]) : []);

  return (
    <>
      <Card title="Staff">
        {list === 'loading' && <p className="pc-muted">Loading staff…</p>}
        {list !== 'loading' && list.kind === 'failed' && <Alert tone="danger">The staff list could not be loaded.</Alert>}
        {list !== 'loading' && list.kind === 'ok' && (
          <div className="pc-table-scroll">
            <table className="pc-table">
              <thead>
                <tr>
                  <th scope="col">Name</th>
                  <th scope="col">Email</th>
                  <th scope="col">Status</th>
                  <th scope="col">Roles</th>
                </tr>
              </thead>
              <tbody>
                {list.staff.map((member) => (
                  <tr key={member.id}>
                    <td className="pc-table__primary">{member.fullName}</td>
                    <td>{member.email}</td>
                    <td>
                      <Badge tone={member.status === 'active' ? 'success' : member.status === 'invited' ? 'warning' : 'neutral'}>{member.status}</Badge>
                    </td>
                    <td>{member.roles.map(roleLabel).join(', ') || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      {list !== 'loading' && list.kind === 'ok' && list.pendingGrants.length > 0 && (
        <Card title="Grants waiting for a second approver">
          {problem && <Alert tone="danger">{problem}</Alert>}
          <ul className="pc-admin__grants">
            {list.pendingGrants.map((grant) => (
              <li key={grant.grantId}>
                <span>
                  {roleLabel(grant.role)} for {names.get(grant.staffId) ?? grant.staffId}, granted by {names.get(grant.grantedBy) ?? grant.grantedBy}
                </span>
                {grant.grantedBy !== session.id && (
                  <Button size="sm" variant="secondary" loading={approving === grant.grantId} onClick={() => void approve(grant.grantId)}>
                    Approve
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}
      <InviteForm session={session} fetchImpl={fetchImpl} onInvited={() => setVersion((n) => n + 1)} />
    </>
  );
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function InviteForm({ session, fetchImpl, onInvited }: { session: StaffSession; fetchImpl?: typeof fetch | undefined; onInvited: () => void }) {
  const [email, setEmail] = useState('');
  const [fullName, setFullName] = useState('');
  const [role, setRole] = useState<string>('support');
  const [errors, setErrors] = useState<{ email?: string | undefined; fullName?: string | undefined }>({});
  const [result, setResult] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [loading, setLoading] = useState(false);
  const roleId = useId();

  async function submit(event: FormEvent) {
    event.preventDefault();
    const address = email.trim();
    const name = fullName.trim();
    const next: typeof errors = {};
    if (!EMAIL.test(address)) next.email = 'Enter the staff member’s work email address.';
    if (name.length < 2) next.fullName = 'Enter their full name.';
    setErrors(next);
    setResult(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const response = await inviteStaff(session.csrfToken, { email: address, fullName: name, role }, fetchImpl);
    setLoading(false);
    if (response.kind === 'ok') {
      setEmail('');
      setFullName('');
      setResult({ tone: 'success', text: `Invitation sent to ${address}. The link works for 24 hours.` });
      onInvited();
      return;
    }
    if (response.kind === 'rejected' && response.code === 'already_staff') {
      setResult({ tone: 'danger', text: 'This email address already belongs to a staff member.' });
    } else if (response.kind === 'rejected' || response.kind === 'invalid') {
      setResult({ tone: 'danger', text: 'Check the email address, name and role, then try again.' });
    } else {
      setResult({ tone: 'danger', text: ceremonyProblem(response, 'The invitation could not be sent.') });
    }
  }

  return (
    <Card title="Invite a staff member">
      <form className="pc-auth__form" onSubmit={submit} noValidate>
        <p className="pc-muted">They get an email with a link to register two passkeys. Super admin can only be granted later, with a second approver.</p>
        {result && <Alert tone={result.tone}>{result.text}</Alert>}
        <TextField label="Work email address" type="email" autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} />
        <TextField label="Full name" autoComplete="off" value={fullName} onChange={(e) => setFullName(e.target.value)} error={errors.fullName} />
        <div className="pc-field">
          <label className="pc-field__label" htmlFor={roleId}>
            Starting role
          </label>
          <select id={roleId} className="pc-input" value={role} onChange={(e) => setRole(e.target.value)}>
            {INVITABLE_ROLES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <Button type="submit" loading={loading}>
          Send invitation
        </Button>
      </form>
    </Card>
  );
}
