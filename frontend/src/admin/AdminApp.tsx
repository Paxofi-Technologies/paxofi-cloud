import { useCallback, useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Alert, AppShell, Badge, Button, Card, Logo, RecoveryCodesView, TextField, type NavItem } from '../components';
import { GlobeIcon, HomeIcon, KeyIcon, ReceiptIcon, ServerIcon, ShieldIcon, TagIcon, UserIcon, UsersIcon } from '../components/icons';
import { QrCode } from '../components/QrCode';
import { describeWait, type ApiResult } from '../lib/api';
import { describeLocation, eventLabel, formatWhen } from '../lib/activity';
import { groupSecret, readRecoveryCodes } from '../lib/mfa';
import {
  INVITABLE_ROLES,
  addPasskey,
  addPasskeyOptions,
  approveGrant,
  ceremony,
  endStaffMemberSessions,
  fetchAuditLog,
  fetchProviderConnections,
  fetchDomainCosts,
  formatMinor,
  requestDomainCostSync,
  fetchSpaceshipCheck,
  requestSpaceshipCheck,
  type SpaceshipCheckResult,
  type DomainCostItem,
  type SkippedExtension,
  type AvailabilityCheck,
  type FeedFieldSummary,
  type DomainCostsResult,
  type AuditLogEvent,
  type AuditLogResult,
  fetchStaff,
  fetchStaffSession,
  finishEnrolment,
  finishMemberSetup,
  finishSignIn,
  inviteStaff,
  readSetup,
  resendEmailCode,
  roleLabel,
  signInOptions,
  signInWithPassword,
  signOutStaff,
  startEnrolment,
  startMemberSetup,
  submitAuthenticatorCode,
  submitEmailCode,
  submitProviderCredential,
  type ProviderConnectionList,
  type ProviderConnectionStatus,
  type SignInSetup,
  type StaffList,
  type StaffSession,
  type StaffSessionState,
} from './adminApi';
import { ConfirmWithCode, StaffDevices } from './StaffDevices';
import { DomainExtensionsPage } from './DomainExtensions';
import { TaxRatesPage } from './TaxRates';
import { createPasskey, getPasskey, type CredentialsApi } from './webauthn';

export interface AdminAppProps {
  path?: string;
  initialHash?: string;
  fetchImpl?: typeof fetch | undefined;
  /** navigator.credentials; tests pass a software authenticator. */
  credentials?: CredentialsApi | null | undefined;
}

const TOKEN = /^[A-Za-z0-9_-]{43}$/;
const PASSWORD_MIN = 15;
const EMAIL_RESEND_SECONDS = 60;

/** Parses `#token=…` from an invitation link; anything malformed is no token. */
export function invitationToken(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  return token !== null && TOKEN.test(token) ? token : null;
}

/**
 * The PaxofiCloud staff console (SRS ACC-005, IAM-008; identity threat model
 * §6.17–§6.20). Served only on the admin host. Staff sign in with password,
 * authenticator code and a code sent by email; a passkey is the optional,
 * stronger way in.
 */
export function AdminApp({ path = window.location.pathname, initialHash, fetchImpl, credentials }: AdminAppProps) {
  if (path === '/enrol') return <EnrolScreen initialHash={initialHash} fetchImpl={fetchImpl} />;

  return <Console path={path} fetchImpl={fetchImpl} credentials={credentials} />;
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
          <p className="pc-auth__headline">Staff sign-in needs your password, your authenticator app and your email.</p>
          <ul className="pc-auth__points">
            <li>Every action is recorded in the audit log</li>
            <li>Access follows your role, nothing more</li>
            <li>Never share a code, a password or an invitation link</li>
          </ul>
        </div>
      </div>
    </div>
  );
}

function problemText(result: ApiResult, fallback: string): string {
  if (result.kind === 'throttled') return `Too many attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'unavailable') return 'We could not reach PaxofiCloud, or sign-in is unavailable right now. Please try again in a few minutes.';
  return fallback;
}

const UNSUPPORTED = 'This browser cannot use passkeys. Use a current version of Chrome, Edge, Safari or Firefox.';

const PASSWORD_ERRORS: Record<string, string> = {
  too_short: `Use at least ${PASSWORD_MIN} characters. A few unrelated words work well.`,
  too_long: 'Use at most 128 characters.',
  invalid_characters: 'Remove control characters such as tabs or line breaks.',
  breached: 'This password has appeared in a data breach. Choose a different one.',
};

const CODE_WRONG = 'That code is not right. Check the time on your phone and try the newest code.';

/**
 * Password plus authenticator app, finished with a code from the app (§6.20).
 * Used with an invitation and, once, by members who enrolled with passkeys only.
 */
function SetupForm({
  setup,
  submit,
  onFinished,
  onInvitationDead,
}: {
  setup: SignInSetup;
  submit: (password: string, code: string) => Promise<ApiResult>;
  onFinished: (codes: string[]) => void;
  onInvitationDead?: () => void;
}) {
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<{ password?: string | undefined; confirm?: string | undefined; code?: string | undefined }>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    const next: typeof errors = {};
    if ([...password].length < PASSWORD_MIN) next.password = PASSWORD_ERRORS.too_short;
    if (confirm !== password) next.confirm = 'The two passwords do not match.';
    if (!/^\d{6}$/.test(code)) next.code = 'Enter the 6-digit code shown in your app.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const result = await submit(password, code);
    setLoading(false);
    setCode('');
    if (result.kind === 'ok') {
      const codes = readRecoveryCodes(result.data);
      if (codes) onFinished(codes);
      else setProblem('Something went wrong. Please try again.');
      return;
    }
    if (result.kind === 'invalid') {
      setErrors({
        password: result.errors.password ? (PASSWORD_ERRORS[result.errors.password] ?? 'Choose a different password.') : undefined,
        code: result.errors.code ? CODE_WRONG : undefined,
      });
      return;
    }
    if (result.kind === 'rejected' && result.code === 'invitation_invalid' && onInvitationDead) {
      onInvitationDead();
      return;
    }
    setProblem(problemText(result, 'Setup could not be finished. Reload the page and try again.'));
  }

  return (
    <form className="pc-stack" onSubmit={onSubmit} noValidate>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <h2>1. Choose a password</h2>
      <p className="pc-muted">Use it only for the PaxofiCloud admin console. A password manager can create and remember one for you.</p>
      <TextField
        label="Password"
        type="password"
        autoComplete="new-password"
        hint={`At least ${PASSWORD_MIN} characters`}
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={errors.password}
        required
      />
      <TextField label="Confirm password" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} error={errors.confirm} required />
      <h2>2. Add your authenticator app</h2>
      <p className="pc-muted">Open Google Authenticator, Microsoft Authenticator or a similar app and scan this code.</p>
      <div className="pc-mfa-setup">
        <QrCode value={setup.uri} label="QR code for your authenticator app" />
        <div className="pc-stack">
          <p className="pc-muted">Can't scan it? Add an account in the app manually with this key (time-based):</p>
          <code className="pc-secret">{groupSecret(setup.secret)}</code>
          <p className="pc-muted">Then enter the 6-digit code the app shows for PaxofiCloud Admin.</p>
        </div>
      </div>
      <TextField
        label="Code from your app"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={errors.code}
        required
      />
      <div>
        <Button type="submit" loading={loading}>
          Finish setup
        </Button>
      </div>
    </form>
  );
}

type EnrolState =
  | { kind: 'starting' }
  | { kind: 'setup'; setup: SignInSetup }
  | { kind: 'codes'; codes: string[] }
  | { kind: 'active' }
  | { kind: 'invalid' }
  | { kind: 'error'; message: string };

function EnrolScreen({ initialHash, fetchImpl }: { initialHash?: string | undefined; fetchImpl?: typeof fetch | undefined }) {
  // Read once (StrictMode may run initialisers twice), then strip from the address bar.
  const [token] = useState(() => invitationToken(initialHash ?? window.location.hash));
  const [state, setState] = useState<EnrolState>(token === null ? { kind: 'invalid' } : { kind: 'starting' });
  const started = useRef(false);

  useEffect(() => {
    if (window.location.hash !== '') window.history.replaceState(null, '', window.location.pathname + window.location.search);
    // Each start makes a new secret; StrictMode runs effects twice, so start once.
    if (token === null || started.current) return;
    started.current = true;
    void startEnrolment(token, fetchImpl).then((result) => {
      const setup = readSetup(result);
      if (setup) setState({ kind: 'setup', setup });
      else if (result.kind === 'rejected' && result.code === 'invitation_invalid') setState({ kind: 'invalid' });
      else setState({ kind: 'error', message: problemText(result, 'Setup could not start. Reload the page to try again.') });
    });
  }, [token, fetchImpl]);

  return (
    <StaffAuthLayout>
      <div className="pc-auth__form" aria-live="polite">
        {state.kind === 'starting' && (
          <p className="pc-loading" role="status">
            Checking your invitation…
          </p>
        )}
        {state.kind === 'invalid' && (
          <div>
            <h1>This invitation is not valid</h1>
            <p className="pc-muted">
              The link has expired, was already used, or was copied incompletely. Ask a security administrator to send you a new invitation.
            </p>
          </div>
        )}
        {state.kind === 'error' && <Alert tone="danger">{state.message}</Alert>}
        {state.kind === 'setup' && token !== null && (
          <>
            <div>
              <h1>Set up your staff sign-in</h1>
              <p className="pc-muted">
                {state.setup.fullName !== '' ? `Welcome, ${state.setup.fullName}. ` : ''}You will sign in with this password, a code from your authenticator app and a
                code we email to {state.setup.email || 'you'}.
              </p>
            </div>
            <SetupForm
              setup={state.setup}
              submit={(password, code) => finishEnrolment(token, password, code, fetchImpl)}
              onFinished={(codes) => setState({ kind: 'codes', codes })}
              onInvitationDead={() => setState({ kind: 'invalid' })}
            />
          </>
        )}
        {state.kind === 'codes' && (
          <RecoveryCodesView codes={state.codes} title="PaxofiCloud Admin recovery codes" fileName="paxoficloud-admin-recovery-codes.txt" onDone={() => setState({ kind: 'active' })} />
        )}
        {state.kind === 'active' && (
          <>
            <div>
              <h1>Your staff account is active</h1>
              <p className="pc-muted">Sign in with your email address, your password and the codes.</p>
            </div>
            <a className="pc-button pc-button--primary pc-button--md pc-button--block" href="/">
              <span>Sign in</span>
            </a>
          </>
        )}
      </div>
    </StaffAuthLayout>
  );
}

type SignInStep = 'password' | 'totp' | 'email';

function SignIn({ fetchImpl, credentials, onSignedIn }: { fetchImpl?: typeof fetch | undefined; credentials?: CredentialsApi | null | undefined; onSignedIn: () => void }) {
  const [step, setStep] = useState<SignInStep>('password');
  const [notice, setNotice] = useState<string | null>(null);

  /** The ticket ended (expired, used up, or too many wrong codes): start again from the password. */
  const restart = useCallback(() => {
    setNotice('Your sign-in timed out or had too many wrong codes. Please start again.');
    setStep('password');
  }, []);

  return (
    <StaffAuthLayout>
      <div className="pc-auth__form" aria-live="polite">
        {step === 'password' && (
          <PasswordStep
            notice={notice}
            fetchImpl={fetchImpl}
            credentials={credentials}
            onNext={() => {
              setNotice(null);
              setStep('totp');
            }}
            onSignedIn={onSignedIn}
          />
        )}
        {step === 'totp' && <AuthenticatorStep fetchImpl={fetchImpl} onNext={() => setStep('email')} onRestart={restart} />}
        {step === 'email' && <EmailCodeStep fetchImpl={fetchImpl} onSignedIn={onSignedIn} onRestart={restart} />}
      </div>
    </StaffAuthLayout>
  );
}

function PasswordStep({
  notice,
  fetchImpl,
  credentials,
  onNext,
  onSignedIn,
}: {
  notice: string | null;
  fetchImpl?: typeof fetch | undefined;
  credentials?: CredentialsApi | null | undefined;
  onNext: () => void;
  onSignedIn: () => void;
}) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [passkeyLoading, setPasskeyLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (email.trim() === '' || password === '') {
      setProblem('Enter your email address and password.');
      return;
    }
    setProblem(null);
    setLoading(true);
    const result = await signInWithPassword(email.trim(), password, fetchImpl);
    setLoading(false);
    if (result.kind === 'ok') {
      setPassword('');
      onNext();
      return;
    }
    setPassword('');
    setProblem(problemText(result, 'The email address or password is incorrect.'));
  }

  async function signInWithPasskey() {
    setProblem(null);
    setPasskeyLoading(true);
    try {
      const started = await signInOptions(fetchImpl);
      const begun = ceremony(started);
      if (begun === null) {
        setProblem(problemText(started, 'We could not start passkey sign-in. Please try again.'));
        return;
      }
      const asserted = await getPasskey(begun.options, credentials === undefined ? undefined : credentials);
      if (asserted.kind === 'unsupported') {
        setProblem(UNSUPPORTED);
        return;
      }
      if (asserted.kind === 'cancelled') {
        setProblem('Passkey sign-in was cancelled or timed out. Please try again.');
        return;
      }
      const finished = await finishSignIn(begun.ceremonyId, asserted.credential, fetchImpl);
      if (finished.kind === 'ok') onSignedIn();
      else setProblem(problemText(finished, 'Passkey sign-in failed. Use a passkey you added to your staff account, or sign in with your password.'));
    } finally {
      setPasskeyLoading(false);
    }
  }

  return (
    <form className="pc-auth__form" onSubmit={submit} noValidate>
      <div>
        <h1>Staff sign-in</h1>
        <p className="pc-muted">Step 1 of 3: your email address and password.</p>
      </div>
      {notice && <Alert tone="warning">{notice}</Alert>}
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField label="Email address" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
      <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
      <Button type="submit" block loading={loading}>
        Continue
      </Button>
      <Button type="button" variant="ghost" block loading={passkeyLoading} onClick={() => void signInWithPasskey()}>
        Sign in with a passkey instead
      </Button>
    </form>
  );
}

function AuthenticatorStep({ fetchImpl, onNext, onRestart }: { fetchImpl?: typeof fetch | undefined; onNext: () => void; onRestart: () => void }) {
  const [code, setCode] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const value = code.trim();
    if (useRecovery ? value === '' : !/^\d{6}$/.test(value)) {
      setError(useRecovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your app.');
      return;
    }
    setError(undefined);
    setProblem(null);
    setLoading(true);
    const result = await submitAuthenticatorCode(value, fetchImpl);
    setLoading(false);
    setCode('');
    if (result.kind === 'ok') onNext();
    else if (result.kind === 'rejected' && result.code === 'code_invalid') setError(useRecovery ? 'That recovery code is not right or was already used.' : CODE_WRONG);
    else if (result.kind === 'rejected') onRestart();
    else setProblem(problemText(result, 'Something went wrong. Please try again.'));
  }

  return (
    <form className="pc-auth__form" onSubmit={submit} noValidate>
      <div>
        <h1>Enter your authenticator code</h1>
        <p className="pc-muted">Step 2 of 3: the 6-digit code your authenticator app shows for PaxofiCloud Admin.</p>
      </div>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField
        label={useRecovery ? 'Recovery code' : 'Code from your app'}
        inputMode={useRecovery ? 'text' : 'numeric'}
        autoComplete="one-time-code"
        maxLength={useRecovery ? 24 : 6}
        value={code}
        onChange={(e) => setCode(useRecovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
        error={error}
        required
      />
      <Button type="submit" block loading={loading}>
        Continue
      </Button>
      <button
        type="button"
        className="pc-link-button"
        onClick={() => {
          setUseRecovery((v) => !v);
          setCode('');
          setError(undefined);
        }}
      >
        {useRecovery ? 'Use a code from my app instead' : 'Lost your phone? Use a recovery code'}
      </button>
    </form>
  );
}

function EmailCodeStep({ fetchImpl, onSignedIn, onRestart }: { fetchImpl?: typeof fetch | undefined; onSignedIn: () => void; onRestart: () => void }) {
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [loading, setLoading] = useState(false);
  const [resending, setResending] = useState(false);
  const [cooldown, setCooldown] = useState(EMAIL_RESEND_SECONDS);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldown]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from the email.');
      return;
    }
    setError(undefined);
    setMessage(null);
    setLoading(true);
    const result = await submitEmailCode(code, fetchImpl);
    setLoading(false);
    setCode('');
    if (result.kind === 'ok') onSignedIn();
    else if (result.kind === 'rejected' && result.code === 'code_invalid') setError('That code is not right. Use the newest email we sent.');
    else if (result.kind === 'rejected') onRestart();
    else setMessage({ tone: 'danger', text: problemText(result, 'Something went wrong. Please try again.') });
  }

  async function resend() {
    setMessage(null);
    setResending(true);
    const result = await resendEmailCode(fetchImpl);
    setResending(false);
    if (result.kind === 'ok') {
      setCooldown(EMAIL_RESEND_SECONDS);
      setMessage({ tone: 'success', text: 'We sent a new code. The old one no longer works.' });
    } else if (result.kind === 'throttled') {
      setMessage({ tone: 'danger', text: `You cannot get another code yet. Try again in ${describeWait(result.retryAfterSeconds)}, or start again.` });
    } else if (result.kind === 'rejected') onRestart();
    else setMessage({ tone: 'danger', text: problemText(result, 'A new code could not be sent.') });
  }

  return (
    <form className="pc-auth__form" onSubmit={submit} noValidate>
      <div>
        <h1>Check your email</h1>
        <p className="pc-muted">
          Step 3 of 3: we sent a 6-digit code to your staff email address. It expires in 10 minutes. Only enter it on this site ({window.location.host}).
        </p>
      </div>
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      <TextField
        label="Code from the email"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={error}
        required
      />
      <Button type="submit" block loading={loading}>
        Sign in
      </Button>
      <Button type="button" variant="ghost" block loading={resending} disabled={cooldown > 0} onClick={() => void resend()}>
        {cooldown > 0 ? `Send a new code (${cooldown} s)` : 'Send a new code'}
      </Button>
    </form>
  );
}

function Console({ path, fetchImpl, credentials }: { path: string; fetchImpl?: typeof fetch | undefined; credentials?: CredentialsApi | null | undefined }) {
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

  return <Dashboard path={path} session={state.session} fetchImpl={fetchImpl} credentials={credentials} onSignedOut={reload} onChanged={reload} />;
}

type AdminPage = 'overview' | 'account' | 'staff' | 'providers-domains' | 'providers-servers' | 'providers-payments' | 'domain-extensions' | 'domain-costs' | 'tax-rates' | 'audit-log';

interface AdminPageSpec {
  id: AdminPage;
  path: string;
  label: string;
  icon: ReactNode;
  /** The permission the page needs; the API enforces it again on every call. */
  permission: string | null;
  /** The menu heading the page sits under, if any. */
  group?: AdminGroup;
}

type AdminGroup = 'api-providers';

/** Menu headings that hold pages (CEO decision 24 Oct 2026: "API providers" with registrars and server providers under it). */
const ADMIN_GROUPS: Record<AdminGroup, { label: string; icon: ReactNode }> = {
  'api-providers': { label: 'API providers', icon: <KeyIcon /> },
};

/** Old addresses that still open the right page (links in earlier staff emails and bookmarks). */
const ADMIN_PATH_ALIASES: Record<string, string> = {
  '/provider-connections': '/api-providers/domain-registrars',
  '/api-providers': '/api-providers/domain-registrars',
};

/** Each page has its own address, so refresh, back/forward and links in staff emails land on it. */
const ADMIN_PAGES: readonly AdminPageSpec[] = [
  { id: 'overview', path: '/', label: 'Overview', icon: <HomeIcon />, permission: null },
  { id: 'account', path: '/account', label: 'Your sign-in', icon: <UserIcon />, permission: null },
  { id: 'staff', path: '/staff', label: 'Staff', icon: <UsersIcon />, permission: 'staff.manage' },
  {
    id: 'providers-domains',
    path: '/api-providers/domain-registrars',
    label: 'Domain registrars',
    icon: <GlobeIcon />,
    permission: 'provider_credentials.manage',
    group: 'api-providers',
  },
  {
    id: 'providers-servers',
    path: '/api-providers/server-providers',
    label: 'Server providers',
    icon: <ServerIcon />,
    permission: 'provider_credentials.manage',
    group: 'api-providers',
  },
  {
    id: 'providers-payments',
    path: '/api-providers/payment-gateways',
    label: 'Payment gateways',
    icon: <ReceiptIcon />,
    permission: 'provider_credentials.manage',
    group: 'api-providers',
  },
  { id: 'domain-extensions', path: '/domain-extensions', label: 'Domain extensions', icon: <GlobeIcon />, permission: 'catalogue.view' },
  { id: 'domain-costs', path: '/domain-costs', label: 'Domain costs', icon: <TagIcon />, permission: 'catalogue.view' },
  { id: 'tax-rates', path: '/tax-rates', label: 'Tax rates', icon: <ReceiptIcon />, permission: 'catalogue.view' },
  { id: 'audit-log', path: '/audit-log', label: 'Audit log', icon: <ShieldIcon />, permission: 'audit.view' },
];

/** The pages this member may open; the menu shows only these. */
export function adminPagesFor(permissions: readonly string[]): AdminPageSpec[] {
  return ADMIN_PAGES.filter((page) => page.permission === null || permissions.includes(page.permission));
}

/** An unknown path, or a page the member may not open, shows the overview. */
export function adminPageForPath(path: string, permissions: readonly string[]): AdminPage {
  const target = ADMIN_PATH_ALIASES[path] ?? path;
  return adminPagesFor(permissions).find((page) => page.path === target)?.id ?? 'overview';
}

/** The sidebar: pages in table order, grouped pages under their heading where the first of them appears. */
export function adminNav(pages: readonly AdminPageSpec[]): NavItem[] {
  const nav: NavItem[] = [];
  const groups = new Map<AdminGroup, NavItem[]>();
  for (const page of pages) {
    const item: NavItem = { id: page.id, label: page.label, icon: page.icon };
    if (page.group === undefined) {
      nav.push(item);
      continue;
    }
    let children = groups.get(page.group);
    if (children === undefined) {
      children = [];
      groups.set(page.group, children);
      nav.push({ id: page.group, ...ADMIN_GROUPS[page.group], children });
    }
    children.push(item);
  }
  return nav;
}

function Dashboard({
  path,
  session,
  fetchImpl,
  credentials,
  onSignedOut,
  onChanged,
}: {
  path: string;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  credentials?: CredentialsApi | null | undefined;
  onSignedOut: () => void;
  onChanged: () => void;
}) {
  const [signingOut, setSigningOut] = useState(false);
  const pages = adminPagesFor(session.permissions);
  const [page, setPage] = useState<AdminPage>(() => adminPageForPath(path, session.permissions));

  useEffect(() => {
    const onPop = () => setPage(adminPageForPath(window.location.pathname, session.permissions));
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [session.permissions]);

  function go(next: AdminPage) {
    setPage(next);
    // The path comes from our own constant table, never from input.
    const target = ADMIN_PAGES.find((p) => p.id === next)?.path ?? '/';
    if (window.location.pathname !== target) window.history.pushState(null, '', target);
  }

  async function signOut() {
    if (signingOut) return;
    setSigningOut(true);
    await signOutStaff(session.csrfToken, fetchImpl);
    setSigningOut(false);
    onSignedOut();
  }

  const nav = adminNav(pages);

  return (
    <AppShell
      nav={nav}
      current={page}
      onNavigate={(id) => go(adminPageForPath(ADMIN_PAGES.find((p) => p.id === id)?.path ?? '/', session.permissions))}
      userName={session.fullName}
      onSignOut={() => void signOut()}
      envLabel="Staff console"
    >
      <div className="pc-stack">
        {page === 'overview' && <Overview session={session} pages={pages} onOpen={go} />}
        {page === 'account' && (
          <>
            <SignInMethods session={session} fetchImpl={fetchImpl} credentials={credentials} onChanged={onChanged} />
            <StaffDevices csrfToken={session.csrfToken} fetchImpl={fetchImpl} />
          </>
        )}
        {page === 'staff' && <StaffManagement session={session} fetchImpl={fetchImpl} />}
        {page === 'providers-domains' && <ProviderConnections category="domains" session={session} fetchImpl={fetchImpl} />}
        {page === 'providers-servers' && <ProviderConnections category="servers" session={session} fetchImpl={fetchImpl} />}
        {page === 'providers-payments' && <ProviderConnections category="payments" session={session} fetchImpl={fetchImpl} />}
        {page === 'domain-extensions' && <DomainExtensionsPage session={session} fetchImpl={fetchImpl} />}
        {page === 'domain-costs' && <DomainCostsCard session={session} fetchImpl={fetchImpl} />}
        {page === 'tax-rates' && <TaxRatesPage session={session} fetchImpl={fetchImpl} />}
        {page === 'audit-log' && <AuditLogCard fetchImpl={fetchImpl} />}
      </div>
    </AppShell>
  );
}

function Overview({ session, pages, onOpen }: { session: StaffSession; pages: AdminPageSpec[]; onOpen: (page: AdminPage) => void }) {
  return (
    <>
      <div className="pc-page-header">
        <div>
          <h1>Staff console</h1>
          <p className="pc-muted">Signed in as {session.email}. The menu shows the areas your roles allow.</p>
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
      <Card title="Go to">
        <ul className="pc-admin__list">
          {pages
            .filter((p) => p.id !== 'overview')
            .map((p) => (
              <li key={p.id}>
                <button type="button" className="pc-link-button" onClick={() => onOpen(p.id)}>
                  {p.group ? `${ADMIN_GROUPS[p.group].label}: ${p.label}` : p.label}
                </button>
              </li>
            ))}
        </ul>
      </Card>
    </>
  );
}

type MethodsState =
  | { kind: 'idle' }
  | { kind: 'setup'; setup: SignInSetup }
  | { kind: 'codes'; codes: string[] }
  | { kind: 'passkey' };

/** §6.20: the member's own sign-in methods: password + authenticator once, and optional passkeys. */
function SignInMethods({
  session,
  fetchImpl,
  credentials,
  onChanged,
}: {
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  credentials?: CredentialsApi | null | undefined;
  onChanged: () => void;
}) {
  const [state, setState] = useState<MethodsState>({ kind: 'idle' });
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [loading, setLoading] = useState(false);

  async function startSetup() {
    setMessage(null);
    setLoading(true);
    const result = await startMemberSetup(session.csrfToken, fetchImpl);
    setLoading(false);
    const setup = readSetup(result);
    if (setup) setState({ kind: 'setup', setup });
    else setMessage({ tone: 'danger', text: problemText(result, 'Setup could not start. Reload the page and try again.') });
  }

  return (
    <Card title="Your sign-in">
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      {state.kind === 'setup' && (
        <SetupForm
          setup={state.setup}
          submit={(password, code) => finishMemberSetup(session.csrfToken, password, code, fetchImpl)}
          onFinished={(codes) => setState({ kind: 'codes', codes })}
        />
      )}
      {state.kind === 'codes' && (
        <RecoveryCodesView
          codes={state.codes}
          title="PaxofiCloud Admin recovery codes"
          fileName="paxoficloud-admin-recovery-codes.txt"
          onDone={() => {
            setState({ kind: 'idle' });
            onChanged();
          }}
        />
      )}
      {state.kind === 'passkey' && (
        <AddPasskeyForm
          session={session}
          fetchImpl={fetchImpl}
          credentials={credentials}
          onCancel={() => setState({ kind: 'idle' })}
          onAdded={() => {
            setState({ kind: 'idle' });
            setMessage({ tone: 'success', text: 'Passkey added. Next time you can sign in with it instead of the codes.' });
            onChanged();
          }}
        />
      )}
      {state.kind === 'idle' && !session.signIn.password && (
        <div className="pc-stack">
          <Alert tone="warning">Set up password sign-in so you can still sign in if you lose your passkey.</Alert>
          <div>
            <Button loading={loading} onClick={() => void startSetup()}>
              Set up password and authenticator app
            </Button>
          </div>
        </div>
      )}
      {state.kind === 'idle' && session.signIn.password && (
        <div className="pc-stack">
          <p>
            Password, authenticator app and email code: <Badge tone="success">set up</Badge>
          </p>
          <p className="pc-muted">
            {session.signIn.passkeys === 0
              ? 'No passkeys yet. A passkey (Windows Hello, Touch ID, your phone or a security key) is optional, faster, and cannot be phished.'
              : `${session.signIn.passkeys} passkey${session.signIn.passkeys === 1 ? '' : 's'} added. You can sign in with a passkey instead of the codes.`}
          </p>
          <div>
            <Button variant="secondary" onClick={() => setState({ kind: 'passkey' })}>
              Add a passkey
            </Button>
          </div>
        </div>
      )}
    </Card>
  );
}

function AddPasskeyForm({
  session,
  fetchImpl,
  credentials,
  onCancel,
  onAdded,
}: {
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  credentials?: CredentialsApi | null | undefined;
  onCancel: () => void;
  onAdded: () => void;
}) {
  const [code, setCode] = useState('');
  const [label, setLabel] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your app.');
      return;
    }
    setError(undefined);
    setProblem(null);
    setLoading(true);
    try {
      const started = await addPasskeyOptions(session.csrfToken, code, fetchImpl);
      setCode('');
      const begun = ceremony(started);
      if (begun === null) {
        if (started.kind === 'rejected' && started.code === 'code_invalid') setError(CODE_WRONG);
        else if (started.kind === 'rejected' && started.code === 'passkey_limit_reached') setProblem('You already have the maximum of 5 passkeys.');
        else setProblem(problemText(started, 'Passkey setup could not start. Please try again.'));
        return;
      }
      const created = await createPasskey(begun.options, credentials === undefined ? undefined : credentials);
      if (created.kind === 'unsupported') {
        setProblem(UNSUPPORTED);
        return;
      }
      if (created.kind === 'cancelled') {
        setProblem('Passkey setup was cancelled or timed out. Enter a new code to try again.');
        return;
      }
      const finished = await addPasskey(session.csrfToken, begun.ceremonyId, created.credential, label.trim(), fetchImpl);
      if (finished.kind === 'ok') onAdded();
      else setProblem(problemText(finished, 'This passkey could not be added. It may already be registered; try a different one.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="pc-stack" onSubmit={submit} noValidate>
      <h2>Add a passkey</h2>
      <p className="pc-muted">To confirm it is you, enter the current code from your authenticator app. Your browser will then ask you to create the passkey.</p>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField
        label="Code from your app"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={error}
        required
      />
      <TextField label="Name for this passkey (optional)" hint="For example: Work laptop, YubiKey" maxLength={64} value={label} onChange={(e) => setLabel(e.target.value)} />
      <div className="pc-row">
        <Button type="submit" loading={loading}>
          Create passkey
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function StaffManagement({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [list, setList] = useState<StaffList | 'loading'>('loading');
  const [version, setVersion] = useState(0);
  const [approving, setApproving] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  // T-58: the member whose sessions are about to be ended everywhere.
  const [ending, setEnding] = useState<{ id: string; name: string } | null>(null);
  const [ended, setEnded] = useState<string | null>(null);

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
        {ended && <Alert tone="success">{ended}</Alert>}
        {ending && (
          <ConfirmWithCode
            key={ending.id}
            title={`Sign ${ending.name} out everywhere`}
            lead={`This ends every session ${ending.name} has in the admin console, on all devices. Their roles and access stay the same; they can sign in again. Confirm with a fresh code from your authenticator app. We will email them.`}
            action="Sign out everywhere"
            submit={(code) => endStaffMemberSessions(session.csrfToken, ending.id, code, fetchImpl)}
            onCancel={() => setEnding(null)}
            onDone={(result) => {
              if (result.kind !== 'ok') return false;
              const count = typeof (result.data as { ended?: unknown } | null)?.ended === 'number' ? (result.data as { ended: number }).ended : 0;
              setEnded(`${ending.name} is signed out everywhere (${count === 1 ? '1 session' : `${count} sessions`} ended).`);
              setEnding(null);
              return true;
            }}
          />
        )}
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
                  <th scope="col">
                    <span className="pc-visually-hidden">Action</span>
                  </th>
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
                    <td>
                      {member.id !== session.id && member.status === 'active' && (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setEnded(null);
                            setEnding({ id: member.id, name: member.fullName });
                          }}
                        >
                          Sign out everywhere
                        </Button>
                      )}
                    </td>
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
      setResult({ tone: 'danger', text: problemText(response, 'The invitation could not be sent.') });
    }
  }

  return (
    <Card title="Invite a staff member">
      <form className="pc-auth__form" onSubmit={submit} noValidate>
        <p className="pc-muted">They get an email with a link to choose a password and add an authenticator app. Super admin can only be granted later, with a second approver.</p>
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

/**
 * Provider connections (THREAT-MODEL-PROVIDER-CONNECTIONS.md). Staff can set
 * or replace a provider's API credential, never read it back (PC-2). Each
 * submission needs a fresh authenticator code (PC-3); values are cleared
 * from page state as soon as they are sent (PC-8).
 */
function ProviderConnections({ category, session, fetchImpl }: { category: ProviderCategory; session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [list, setList] = useState<ProviderConnectionList | 'loading'>('loading');
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    void fetchProviderConnections(fetchImpl).then((next) => {
      if (active) setList(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version]);

  // The worker stores a submission within seconds; look again so "being stored" turns into "connected".
  useEffect(() => {
    if (list === 'loading' || list.kind !== 'ok' || !list.providers.some((p) => p.pendingSince !== null)) return;
    const timer = window.setTimeout(() => setVersion((n) => n + 1), 4000);
    return () => window.clearTimeout(timer);
  }, [list]);

  return (
    <Card title={PROVIDER_PAGES[category].title}>
      <p className="pc-muted">
        {PROVIDER_PAGES[category].intro} Credentials are encrypted as soon as you save them, and nobody can view them again: to change one, enter it again.
      </p>
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      {list === 'loading' && <p className="pc-muted">Loading API providers…</p>}
      {list !== 'loading' && list.kind === 'failed' && <Alert tone="danger">API providers could not be loaded.</Alert>}
      {list !== 'loading' && list.kind === 'ok' && (
        <ul className="pc-admin__list">
          {list.providers
            .filter((provider) => provider.category === category)
            .map((provider) => (
              <li key={provider.provider} className="pc-stack">
                <div className="pc-admin__row">
                  <div>
                    <strong>{provider.label}</strong>
                    <p className="pc-muted">{connectionStatus(provider)}</p>
                  </div>
                  {editing !== provider.provider && (
                    <Button
                      variant="secondary"
                      onClick={() => {
                        setMessage(null);
                        setEditing(provider.provider);
                      }}
                    >
                      {provider.configuredAt ? 'Replace credential' : 'Set credential'}
                    </Button>
                  )}
                </div>
                {editing === provider.provider && (
                  <CredentialForm
                    session={session}
                    provider={provider}
                    fetchImpl={fetchImpl}
                    onCancel={() => setEditing(null)}
                    onSaved={() => {
                      setEditing(null);
                      setMessage({ tone: 'success', text: `${provider.label}: saved. It is encrypted and stored within a few seconds, and every security administrator is emailed.` });
                      setVersion((n) => n + 1);
                    }}
                  />
                )}
              </li>
            ))}
        </ul>
      )}
    </Card>
  );
}

type ProviderCategory = 'domains' | 'servers' | 'payments';

/** The two pages under "API providers" (CEO decision 24 Oct 2026). */
const PROVIDER_PAGES: Record<ProviderCategory, { title: string; intro: string }> = {
  domains: { title: 'Domain registrars', intro: 'API credentials PaxofiCloud uses to read prices from, and later register domains with, each registrar.' },
  servers: { title: 'Server providers', intro: 'API credentials PaxofiCloud uses to create and manage servers.' },
  payments: {
    title: 'Payment gateways',
    intro: 'Secret keys PaxofiCloud uses to take card and transfer payments. They are locked away for the payments service only; staging accepts test keys only, production live keys only.',
  },
};

function connectionStatus(provider: ProviderConnectionStatus): string {
  if (provider.pendingSince) return `Saved ${formatWhen(provider.pendingSince)}, being encrypted and stored…`;
  if (provider.configuredAt) return `Connected. Last set ${formatWhen(provider.configuredAt)}${provider.reviewDueOn ? `; review by ${provider.reviewDueOn}` : ''}.`;

  return 'Not connected yet.';
}

const CREDENTIAL_ERRORS: Record<string, string> = {
  code_invalid: CODE_WRONG,
  unknown_provider: 'This provider is not supported.',
  intake_unavailable: 'Credentials cannot be saved on this server yet. Ask the engineering team to finish the provider worker setup.',
};

function CredentialForm({
  session,
  provider,
  fetchImpl,
  onCancel,
  onSaved,
}: {
  session: StaffSession;
  provider: ProviderConnectionStatus;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onSaved: () => void;
}) {
  const [values, setValues] = useState<Record<string, string>>({});
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const next: Record<string, string> = {};
    for (const field of provider.fields) {
      if ((values[field.name] ?? '') === '') next[field.name] = `Enter the ${field.label.toLowerCase()}.`;
    }
    if (!/^\d{6}$/.test(code)) next.code = 'Enter the 6-digit code from your authenticator app.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const result = await submitProviderCredential(session.csrfToken, provider.provider, values, code, fetchImpl);
    setLoading(false);
    // PC-8: the values leave page state whatever happened; the code is single use anyway.
    setValues({});
    setCode('');
    if (result.kind === 'ok') {
      onSaved();
      return;
    }
    if (result.kind === 'invalid') {
      if (result.errors.secret_key === 'wrong_key_mode') {
        setProblem('That key is for the wrong mode. Staging takes TEST secret keys only; production takes LIVE secret keys only.');
        return;
      }
      const field = Object.keys(result.errors)[0] ?? '';
      const label = provider.fields.find((f) => f.name === field)?.label;
      setProblem(label ? `Check the ${label.toLowerCase()}: it must not be empty, too long, or contain line breaks.` : 'Check the values and try again.');
      return;
    }
    setProblem(result.kind === 'rejected' ? (CREDENTIAL_ERRORS[result.code] ?? 'The credential could not be saved.') : problemText(result, 'The credential could not be saved.'));
  }

  return (
    <form className="pc-auth__form" onSubmit={submit} noValidate autoComplete="off">
      {problem && <Alert tone="danger">{problem}</Alert>}
      {provider.fields.map((field) => (
        <TextField
          key={field.name}
          label={field.label}
          {...(field.hint ? { hint: field.hint } : {})}
          type={field.secret ? 'password' : 'text'}
          autoComplete={field.secret ? 'new-password' : 'off'}
          spellCheck={false}
          value={values[field.name] ?? ''}
          onChange={(e) => setValues((current) => ({ ...current, [field.name]: e.target.value }))}
          error={errors[field.name]}
        />
      ))}
      <TextField
        label="Authenticator code"
        hint="A fresh code from your authenticator app confirms it is you."
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={errors.code}
      />
      <div className="pc-admin__row">
        <Button type="submit" loading={loading}>
          Save credential
        </Button>
        <Button
          variant="secondary"
          onClick={() => {
            setValues({});
            setCode('');
            onCancel();
          }}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}

const SYNC_REASONS: Record<string, string> = {
  unauthorised: 'the registrar refused the API key. Fix it under API providers → Domain registrars',
  credential_missing: 'no API key is stored. Set it under API providers → Domain registrars',
  credential_invalid: 'the stored API key could not be used. Set it again under API providers → Domain registrars',
  rate_limited: 'the registrar asked us to slow down',
  provider_error: 'the registrar had an error',
  transport: 'the registrar could not be reached',
  malformed: 'the registrar sent a reply we could not read',
  too_many_pages: 'the price list was longer than expected',
  empty: 'the list was empty',
  items_disappeared: 'more than 20% of extensions disappeared',
  costs_jumped: 'more than 20% of costs changed by over 50%',
};

/** Registrar-specific hints added to a refused key, so each tab only names its own fix. */
const UNAUTHORISED_HINTS: Record<string, string> = {
  namecheap: ' Also check that the server IP address is whitelisted in Namecheap.',
  dynadot: ' Use the production key and secret, not the sandbox ones.',
  spaceship: ' Check that the Spaceship key has the domains:read scope.',
};

/** Plain words for a sync failure or hold-back reason on one registrar's tab. */
function syncReason(reason: string, provider: string): string | undefined {
  const text = SYNC_REASONS[reason];
  if (text === undefined) return undefined;
  return reason === 'unauthorised' ? `${text}.${UNAUTHORISED_HINTS[provider] ?? ''}`.replace(/\.$/, '') : text;
}

const TRIGGERS: Record<string, string> = { schedule: 'Daily', manual: 'Sync now', cli: 'Operator' };

/** Registrar names as staff know them. */
const REGISTRAR_NAMES: Record<string, string> = { domainnameapi: 'DomainNameAPI', namecheap: 'Namecheap', dynadot: 'Dynadot' };

/** Extensions per page in the cost list. */
export const COST_PAGE_SIZE = 15;

/**
 * Domain costs (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13): what each
 * extension costs us at the registrar, from the newest accepted snapshot.
 * Internal: Catalogue and Finance only. "Sync now" queues a fetch for the
 * provider worker; this page never talks to the registrar.
 */
function DomainCostsCard({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [result, setResult] = useState<DomainCostsResult | 'loading'>('loading');
  const [version, setVersion] = useState(0);
  const [query, setQuery] = useState('');
  const [costPage, setCostPage] = useState(1);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger' | 'warning'; text: string } | null>(null);
  const [requesting, setRequesting] = useState(false);
  const [provider, setProvider] = useState<string | undefined>(undefined);
  const [spaceshipTab, setSpaceshipTab] = useState(false);

  useEffect(() => {
    let active = true;
    void fetchDomainCosts(fetchImpl, provider).then((next) => {
      if (active) setResult(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version, provider]);

  // While a request waits for the worker (or its run is in progress), look again every few seconds.
  const pending = result !== 'loading' && result.kind === 'ok' && result.costs.pendingSince !== null;
  useEffect(() => {
    if (!pending) return;
    const timer = window.setTimeout(() => setVersion((n) => n + 1), 5000);
    return () => window.clearTimeout(timer);
  }, [pending, version]);

  async function syncNow() {
    setRequesting(true);
    setMessage(null);
    const sent = await requestDomainCostSync(session.csrfToken, fetchImpl, provider);
    setRequesting(false);
    if (sent.kind === 'ok') {
      setMessage({ tone: 'success', text: 'Sync requested. New prices usually arrive within a minute; this page updates by itself.' });
    } else if (sent.kind === 'throttled') {
      setMessage({ tone: 'warning', text: `Prices were requested recently. You can ask again ${describeWait(sent.retryAfterSeconds)}.` });
    } else {
      setMessage({ tone: 'danger', text: problemText(sent, 'The sync could not be requested.') });
    }
    setVersion((n) => n + 1);
  }

  if (result === 'loading') {
    return (
      <Card title="Domain costs">
        <p className="pc-muted">Loading domain costs…</p>
      </Card>
    );
  }
  if (result.kind === 'failed') {
    return (
      <Card title="Domain costs">
        <Alert tone="danger">Domain costs could not be loaded.</Alert>
      </Card>
    );
  }

  const costs = result.costs;
  const needle = query.trim().toLowerCase().replace(/^\./, '');
  const matching = needle === '' ? costs.items : costs.items.filter((item) => item.tld.includes(needle));
  const pageCount = Math.max(1, Math.ceil(matching.length / COST_PAGE_SIZE));
  const currentPage = Math.min(costPage, pageCount);
  const firstRow = (currentPage - 1) * COST_PAGE_SIZE;
  const shown = matching.slice(firstRow, firstRow + COST_PAGE_SIZE);
  const lastRun = costs.runs[0];
  const syncBlocked = costs.pendingSince !== null || costs.nextRequestAt !== null;

  const registrar = REGISTRAR_NAMES[costs.provider] ?? costs.provider;
  // Registrars with a price list, then Spaceship, whose API has none: its tab runs a price check instead (SP-3).
  const tabs = (
    <div className="pc-admin__tabs" role="tablist" aria-label="Registrar">
      {costs.providers.map((p) => (
        <Button
          key={p}
          variant={!spaceshipTab && p === costs.provider ? 'primary' : 'secondary'}
          role="tab"
          aria-selected={!spaceshipTab && p === costs.provider}
          onClick={() => {
            setSpaceshipTab(false);
            // Back from the Spaceship tab to the registrar already loaded: nothing to fetch.
            if (p === costs.provider) return;
            setMessage(null);
            setQuery('');
            setCostPage(1);
            setProvider(p);
          }}
        >
          {REGISTRAR_NAMES[p] ?? p}
        </Button>
      ))}
      <Button variant={spaceshipTab ? 'primary' : 'secondary'} role="tab" aria-selected={spaceshipTab} onClick={() => setSpaceshipTab(true)}>
        Spaceship
      </Button>
    </div>
  );

  if (spaceshipTab) {
    return (
      <Card title="Domain costs">
        {tabs}
        <SpaceshipPriceCheckPanel session={session} fetchImpl={fetchImpl} />
      </Card>
    );
  }

  return (
    <Card title="Domain costs">
      {tabs}
      <p className="pc-muted">
        What each extension costs us at {registrar} ({costs.environment === 'live' ? 'live prices' : 'test environment'}), before our markup. Internal: never shown to
        customers. Updated daily at {costs.dailyAt}.
      </p>
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      {costs.quarantined && (
        <Alert tone="warning">
          The list fetched {formatWhen(costs.quarantined.fetchedAt)} was held back because {syncReason(costs.quarantined.reason ?? '', costs.provider) ?? 'it looked wrong'}. The
          prices below are still the last good ones.
        </Alert>
      )}
      {lastRun && lastRun.outcome === 'failed' && (
        <Alert tone="danger">
          The last sync ({formatWhen(lastRun.startedAt)}) failed: {syncReason(lastRun.reason ?? '', costs.provider) ?? 'unknown problem'}.
        </Alert>
      )}
      <div className="pc-admin__row">
        <p>
          {costs.latest
            ? `${costs.latest.items} extensions, fetched ${formatWhen(costs.latest.fetchedAt)}${costs.latest.refused > 0 ? `; ${costs.latest.refused} skipped as unreadable` : ''}.`
            : 'No prices have been fetched yet.'}
          {costs.pendingSince && <span className="pc-muted"> A sync is on its way…</span>}
        </p>
        {costs.canSync && (
          <Button variant="secondary" onClick={() => void syncNow()} loading={requesting} disabled={syncBlocked}>
            Sync now
          </Button>
        )}
      </div>
      {costs.canSync && costs.nextRequestAt && !costs.pendingSince && (
        <p className="pc-muted">Sync now is available again {formatWhen(costs.nextRequestAt)}.</p>
      )}
      {costs.items.length > 0 && (
        <>
          <TextField
            label="Find an extension"
            hint="For example com, ng or com.ng"
            value={query}
            spellCheck={false}
            onChange={(e) => {
              setQuery(e.target.value);
              setCostPage(1);
            }}
          />
          <div className="pc-table-scroll">
            <table className="pc-table">
              <caption className="pc-muted">
                Cost for one year.{' '}
                {matching.length === 0 ? 'No extension matches.' : `Showing ${firstRow + 1}–${firstRow + shown.length} of ${matching.length}.`}
              </caption>
              <thead>
                <tr>
                  <th scope="col">Extension</th>
                  <th scope="col">Register</th>
                  <th scope="col">Renew</th>
                  <th scope="col">Transfer</th>
                  <th scope="col">Restore</th>
                  <th scope="col">Years</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((item) => (
                  <CostRow key={item.tld} item={item} />
                ))}
              </tbody>
            </table>
          </div>
          {pageCount > 1 && (
            <nav className="pc-admin__row" aria-label="Domain cost pages">
              <Button variant="secondary" onClick={() => setCostPage(currentPage - 1)} disabled={currentPage <= 1}>
                Previous
              </Button>
              <span aria-live="polite">
                Page {currentPage} of {pageCount}
              </span>
              <Button variant="secondary" onClick={() => setCostPage(currentPage + 1)} disabled={currentPage >= pageCount}>
                Next
              </Button>
            </nav>
          )}
        </>
      )}
      {costs.skipped.length > 0 && <SkippedExtensions skipped={costs.skipped} />}
      {costs.availabilityCheck && <AvailabilityCheckLine check={costs.availabilityCheck} />}
      {costs.feedFields.length > 0 && <FeedFields fields={costs.feedFields} />}
      {costs.runs.length > 0 && (
        <details>
          <summary>Recent syncs</summary>
          <ul className="pc-admin__list">
            {costs.runs.map((run) => (
              <li key={`${run.startedAt}-${run.trigger}`}>
                {formatWhen(run.startedAt)} · {TRIGGERS[run.trigger] ?? run.trigger} ·{' '}
                <Badge tone={run.outcome === 'accepted' ? 'success' : run.outcome === 'quarantined' ? 'warning' : 'danger'}>{run.outcome}</Badge>
                {run.reason ? ` ${syncReason(run.reason, costs.provider) ?? run.reason}` : ''}
                {run.attempts > 1 ? ` (${run.attempts} attempts)` : ''}
              </li>
            ))}
          </ul>
        </details>
      )}
    </Card>
  );
}

const CHECK_RESULTS: Record<string, string> = { available: 'Available', missing: 'No answer', other: 'Other answer' };

/**
 * The Spaceship tab (THREAT-MODEL-DOMAIN-PRICING.md SP-3, SP-7). Spaceship's
 * API has no price list, so this asks the provider worker to check one
 * made-up, unregistered name per common extension and shows whether Spaceship
 * returned a price for it. Nothing is registered. Values render as text only.
 */
function SpaceshipPriceCheckPanel({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [result, setResult] = useState<SpaceshipCheckResult | 'loading'>('loading');
  const [version, setVersion] = useState(0);
  const [requesting, setRequesting] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger' | 'warning'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    void fetchSpaceshipCheck(fetchImpl).then((next) => {
      if (active) setResult(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version]);

  // While a check waits for the worker, look again every few seconds.
  const pending = result !== 'loading' && result.kind === 'ok' && result.state.pendingSince !== null;
  useEffect(() => {
    if (!pending) return;
    const timer = window.setTimeout(() => setVersion((n) => n + 1), 5000);
    return () => window.clearTimeout(timer);
  }, [pending, version]);

  async function runCheck() {
    setRequesting(true);
    setMessage(null);
    const sent = await requestSpaceshipCheck(session.csrfToken, fetchImpl);
    setRequesting(false);
    if (sent.kind === 'ok') {
      setMessage({ tone: 'success', text: 'Price check requested. The answer usually arrives within a minute; this page updates by itself.' });
    } else if (sent.kind === 'throttled') {
      setMessage({ tone: 'warning', text: `A check was requested recently. You can ask again ${describeWait(sent.retryAfterSeconds)}.` });
    } else if (sent.kind === 'rejected' && sent.code === 'not_connected') {
      setMessage({ tone: 'warning', text: 'Connect Spaceship first, under API providers → Domain registrars.' });
    } else {
      setMessage({ tone: 'danger', text: problemText(sent, 'The price check could not be requested.') });
    }
    setVersion((n) => n + 1);
  }

  if (result === 'loading') return <p className="pc-muted">Loading the Spaceship price check…</p>;
  if (result.kind === 'failed') return <Alert tone="danger">The Spaceship price check could not be loaded.</Alert>;

  const state = result.state;
  const latest = state.latest;
  const order = new Map(state.probeTlds.map((tld, index) => [tld, index]));
  const items = latest ? [...latest.items].sort((a, b) => (order.get(a.tld) ?? 99) - (order.get(b.tld) ?? 99)) : [];
  const blocked = !state.connected || state.pendingSince !== null || state.nextRequestAt !== null;

  return (
    <>
      <p className="pc-muted">
        Spaceship&apos;s API has no price list. This check asks Spaceship about one made-up, unregistered name in each of {state.probeTlds.length || 20} common
        extensions, to see whether it shows prices for ordinary names. Nothing is registered. Internal: never shown to customers.
      </p>
      {message && <Alert tone={message.tone}>{message.text}</Alert>}
      {!state.connected && (
        <Alert tone="warning">
          Spaceship is not connected yet. Add it under API providers → Domain registrars, with a key that has only the domains:read scope.
        </Alert>
      )}
      <div className="pc-admin__row">
        <p>
          {latest ? `Last check ${formatWhen(latest.checkedAt)}.` : 'No check has run yet.'}
          {state.pendingSince && <span className="pc-muted"> A check is on its way…</span>}
          {!state.pendingSince && state.nextRequestAt && <span className="pc-muted"> You can run another {formatWhen(state.nextRequestAt)}.</span>}
        </p>
        {state.canRun && (
          <Button variant="secondary" onClick={() => void runCheck()} loading={requesting} disabled={blocked}>
            Run price check
          </Button>
        )}
      </div>
      {latest && latest.outcome === 'failed' && (
        <Alert tone="danger">
          The check failed: {syncReason(latest.reason ?? '', 'spaceship') ?? 'unknown problem'}.
        </Alert>
      )}
      {latest && latest.outcome === 'done' && (
        <>
          <Alert tone={latest.priced > 0 ? 'success' : 'warning'}>
            Spaceship answered {latest.answered} of {latest.asked} names and showed a one-year price for {latest.priced}.{' '}
            {latest.priced > 0
              ? 'Its prices can be fetched automatically for these extensions.'
              : 'It does not show ordinary prices, so Spaceship costs would have to be entered by hand for a short list of extensions.'}
          </Alert>
          <div className="pc-table-scroll">
            <table className="pc-table" aria-label="Spaceship price check">
              <thead>
                <tr>
                  <th scope="col">Extension</th>
                  <th scope="col">Answer</th>
                  <th scope="col">Register (1 year)</th>
                  <th scope="col">Other prices shown</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.tld}>
                    <td>.{item.tld}</td>
                    <td>{CHECK_RESULTS[item.result] ?? item.result}</td>
                    <td>{item.register !== null && item.currency ? formatMinor(item.register, item.currency) : '—'}</td>
                    <td>{item.otherPriced.length > 0 ? item.otherPriced.join(', ') : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  );
}

const SKIP_REASONS: Record<string, string> = {
  unsupported_currency: 'Priced in a currency we do not accept yet',
  no_register_price: 'No one-year registration price',
  no_renew_price: 'No one-year renewal price',
  mixed_currency: 'Prices in more than one currency',
  bad_price: 'A price we could not read',
  bad_period: 'Registration periods we could not read',
  bad_status: 'A status we could not read',
  bad_name: 'An extension name we could not read',
  no_prices: 'No prices at all',
  duplicate: 'Listed twice by the registrar',
  not_object: 'An entry we could not read',
};

const PRICE_FIELDS: Record<string, string> = { reg: 'registration', renew: 'renewal' };
const PRICE_PROBLEMS: Record<string, string> = {
  missing: 'is missing',
  zero: 'is 0',
  negative: 'is negative',
  format: 'is not a plain number',
  type: 'is not a number',
  entry: 'is not readable',
};
const CURRENCY_PROBLEMS: Record<string, string> = {
  missing: 'no currency given',
  type: 'currency is not text',
  format: 'currency is not a 3-letter code',
};

/**
 * Plain words for a detail code (DR-14, DR-15): a currency code as is,
 * `periods:N` as the shortest term on offer, `reg:zero` as "registration
 * price is 0", `currency:missing`, and `min:N_max:M` as the periods sent.
 */
function skipDetail(detail: string | null): string {
  if (detail === null) return '';
  const periods = /^periods:(\d{1,2})$/.exec(detail);
  if (periods) return `shortest term ${periods[1]} years`;
  const [, field = '', problem = ''] = /^(reg|renew):([a-z]+)$/.exec(detail) ?? [];
  if (PRICE_FIELDS[field] && PRICE_PROBLEMS[problem]) return `${PRICE_FIELDS[field]} price ${PRICE_PROBLEMS[problem]}`;
  const [, currencyProblem = ''] = /^currency:([a-z]+)$/.exec(detail) ?? [];
  if (CURRENCY_PROBLEMS[currencyProblem]) return CURRENCY_PROBLEMS[currencyProblem];
  const range = /^min:(\d{1,2}|x)_max:(\d{1,2}|x)$/.exec(detail);
  if (range) return `minimum ${range[1]}, maximum ${range[2]} years`;
  return detail;
}

/**
 * The heading for a group of skipped extensions. A negative registration price
 * is how a registrar marks an extension it lists but does not sell (Dynadot
 * does this for some second-level names), so it reads as "not offered".
 */
function skipLabel(reason: string, detail: string): string {
  if (reason === 'bad_price' && detail === skipDetail('reg:negative')) return 'Not offered by this registrar (registration price is negative)';
  return `${SKIP_REASONS[reason] ?? reason}${detail ? ` (${detail})` : ''}`;
}

/**
 * DR-14: extensions the registrar lists but the last accepted sync could not
 * use, grouped by reason, so staff can see what is not on sale and why.
 * Names and details are server-validated and rendered as text only.
 */
function SkippedExtensions({ skipped }: { skipped: SkippedExtension[] }) {
  const groups = new Map<string, { reason: string; detail: string; names: string[]; unnamed: number }>();
  for (const item of skipped) {
    const detail = skipDetail(item.detail);
    const key = `${item.reason}|${detail}`;
    const group = groups.get(key) ?? { reason: item.reason, detail, names: [], unnamed: 0 };
    if (item.name === null) group.unnamed += 1;
    else group.names.push(`.${item.name}`);
    groups.set(key, group);
  }
  const sorted = [...groups.values()].sort((a, b) => b.names.length + b.unnamed - (a.names.length + a.unnamed));

  return (
    <details>
      <summary>Not on sale yet ({skipped.length})</summary>
      <p className="pc-muted">Extensions the registrar lists that we could not price from the last sync, and why.</p>
      <ul className="pc-admin__list">
        {sorted.map((group) => (
          <li key={`${group.reason}|${group.detail}`}>
            <strong>
              {skipLabel(group.reason, group.detail)}: {group.names.length + group.unnamed}
            </strong>
            <p className="pc-muted">
              {group.names.join(', ')}
              {group.unnamed > 0 ? `${group.names.length > 0 ? ', ' : ''}${group.unnamed} without a readable name` : ''}
            </p>
          </li>
        ))}
      </ul>
    </details>
  );
}

const AUDIT_GROUPS: Record<string, string> = {
  auth: 'Customer sign-in',
  identity: 'Customer sign-up',
  mfa: 'Customer two-step',
  staff: 'Staff',
  provider_credential: 'Provider credentials',
  provider_prices: 'Provider prices',
  domain_pricing: 'Domain pricing',
  registrar: 'Registrar',
  audit: 'Audit log views',
};

/**
 * The staff audit log (identity threat model §6.24): every recorded security
 * event, newest first, 50 at a time. Full IP addresses and user agents arrive
 * only for staff managers (T-56); every look is itself recorded (T-57).
 * Everything is rendered as text.
 */
function AuditLogCard({ fetchImpl }: { fetchImpl?: typeof fetch | undefined }) {
  const [outcome, setOutcome] = useState<'' | 'success' | 'failure'>('');
  const [group, setGroup] = useState('');
  const [subject, setSubject] = useState('');
  const [applied, setApplied] = useState<{ outcome: '' | 'success' | 'failure'; group: string; subject: string }>({ outcome: '', group: '', subject: '' });
  // Cursors of the pages already seen, so "Newer" can step back.
  const [cursors, setCursors] = useState<(number | null)[]>([null]);
  const [result, setResult] = useState<AuditLogResult | 'loading'>('loading');
  const outcomeId = useId();
  const groupId = useId();
  const before = cursors[cursors.length - 1] ?? null;

  useEffect(() => {
    let active = true;
    void fetchAuditLog({ ...applied, before }, fetchImpl).then((next) => {
      if (active) setResult(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, applied, before]);

  function search(event: FormEvent) {
    event.preventDefault();
    setResult('loading');
    setApplied({ outcome, group, subject: subject.trim().toLowerCase() });
    setCursors([null]);
  }

  /** "Only this" in the Who column: show every event about one customer or staff member. */
  function only(id: string) {
    setSubject(id);
    setOutcome('');
    setGroup('');
    setResult('loading');
    setApplied({ outcome: '', group: '', subject: id });
    setCursors([null]);
  }

  function turnPage(next: (c: (number | null)[]) => (number | null)[]) {
    setResult('loading');
    setCursors(next);
  }

  const loaded = result !== 'loading' && result.kind === 'loaded' ? result : null;

  return (
    <>
      <div className="pc-page-header">
        <div>
          <h1>Audit log</h1>
          <p className="pc-muted">Every security event, newest first. Append-only and hash-chained; your viewing is recorded too.</p>
        </div>
      </div>
      <Card title="Find events">
        <form className="pc-admin__filters" onSubmit={search}>
          <div className="pc-field">
            <label className="pc-field__label" htmlFor={groupId}>
              Area
            </label>
            <select id={groupId} className="pc-input" value={group} onChange={(e) => setGroup(e.target.value)}>
              <option value="">All areas</option>
              {Object.entries(AUDIT_GROUPS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div className="pc-field">
            <label className="pc-field__label" htmlFor={outcomeId}>
              Outcome
            </label>
            <select id={outcomeId} className="pc-input" value={outcome} onChange={(e) => setOutcome(e.target.value as '' | 'success' | 'failure')}>
              <option value="">Any outcome</option>
              <option value="success">Succeeded</option>
              <option value="failure">Failed</option>
            </select>
          </div>
          <TextField
            label="Customer, account or staff ID"
            hint="Or use “Only this” in the list"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            autoComplete="off"
            spellCheck={false}
          />
          <Button type="submit">Search</Button>
        </form>
      </Card>
      <Card title="Events">
        {result === 'loading' && <p className="pc-muted">Loading events…</p>}
        {result !== 'loading' && result.kind === 'invalid_filter' && <Alert tone="warning">That ID is not valid. IDs are 32 letters and digits (0–9, a–f).</Alert>}
        {result !== 'loading' && result.kind === 'forbidden' && <Alert tone="danger">Your roles do not include the audit log.</Alert>}
        {result !== 'loading' && result.kind === 'failed' && <Alert tone="danger">The audit log could not be loaded. Try again shortly.</Alert>}
        {loaded && (
          <>
            {!loaded.fullContext && <p className="pc-muted">Full IP addresses are shown to security administrators only; you see the network.</p>}
            {loaded.events.length === 0 ? (
              <p className="pc-muted">No events match.</p>
            ) : (
              <div className="pc-table-scroll">
                <table className="pc-table pc-audit">
                  <caption className="pc-visually-hidden">Audit events, newest first</caption>
                  <thead>
                    <tr>
                      <th scope="col">#</th>
                      <th scope="col">When</th>
                      <th scope="col">Event</th>
                      <th scope="col">Who</th>
                      <th scope="col">Where</th>
                      <th scope="col">Device</th>
                    </tr>
                  </thead>
                  <tbody>
                    {loaded.events.map((event) => (
                      <AuditRow key={event.seq} event={event} onOnly={only} />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <nav className="pc-admin__row" aria-label="Audit log pages">
              <Button variant="secondary" disabled={cursors.length <= 1} onClick={() => turnPage((c) => c.slice(0, -1))}>
                Newer
              </Button>
              <span className="pc-muted">
                Chain head #{loaded.head.seq} · <code title={loaded.head.hash}>{loaded.head.hash.slice(0, 12)}…</code>
              </span>
              <Button variant="secondary" disabled={loaded.nextBefore === null} onClick={() => turnPage((c) => [...c, loaded.nextBefore])}>
                Older
              </Button>
            </nav>
          </>
        )}
      </Card>
    </>
  );
}

function ShortId({ id, kind, onOnly }: { id: string; kind: string; onOnly: (id: string) => void }) {
  return (
    <>
      <code title={`${kind} ${id}`}>
        {kind === 'Staff' ? 'staff ' : ''}
        {id.slice(0, 8)}…
      </code>{' '}
      <button type="button" className="pc-link-button" onClick={() => onOnly(id)} aria-label={`Only events about ${kind.toLowerCase()} ${id}`}>
        Only this
      </button>
    </>
  );
}

function AuditRow({ event, onOnly }: { event: AuditLogEvent; onOnly: (id: string) => void }) {
  const label = eventLabel(event.type);
  const detailText = Object.entries(event.details)
    .filter(([key]) => key !== 'subject')
    .map(([key, value]) => `${key}: ${String(value)}`)
    .join(', ');
  const staff = typeof event.details.subject === 'string' ? event.details.subject : null;
  const where = describeLocation(event.city, event.region, event.country);
  return (
    <tr>
      <td>{event.seq}</td>
      <td>{formatWhen(event.occurredAt)}</td>
      <td>
        <span className="pc-table__primary">{label ?? event.type}</span> {event.outcome === 'failure' && <Badge tone="danger">Failed</Badge>}
        {label !== null && (
          <>
            <br />
            <code className="pc-muted">{event.type}</code>
          </>
        )}
        {detailText !== '' && (
          <>
            <br />
            <span className="pc-muted">{detailText}</span>
          </>
        )}
      </td>
      <td>
        {event.identityId !== null && <ShortId id={event.identityId} kind="Customer" onOnly={onOnly} />}
        {staff !== null && <ShortId id={staff} kind="Staff" onOnly={onOnly} />}
        {event.identityId === null && staff === null && <span className="pc-muted">—</span>}
      </td>
      <td>
        {event.ip ?? event.ipNetwork ?? <span className="pc-muted">not kept</span>}
        {where !== null && (
          <>
            <br />
            <span className="pc-muted">{where}</span>
          </>
        )}
      </td>
      <td>
        <span title={event.userAgent ?? undefined}>{event.device ?? <span className="pc-muted">—</span>}</span>
      </td>
    </tr>
  );
}

/** Fields whose names suggest domain lifecycle periods are listed first (DR-16). */
const LIFECYCLE_FIELD = /grace|redemption|restore|expir|period|days|hold|delete/i;

/**
 * DR-16: the field paths the registrar sent with the newest accepted list,
 * so staff can see whether it includes grace or redemption periods before
 * the parser relies on them. Names, counts and whole-number ranges only;
 * rendered as text.
 */
const AVAILABILITY_OUTCOMES: Record<string, string> = {
  answered: 'answered',
  not_connected: 'not run: this registrar is not connected',
  unauthorised: 'failed: the registrar refused the API key',
  rate_limited: 'failed: the registrar asked us to slow down',
  provider_error: 'failed: the registrar had an error',
  malformed: 'failed: the registrar sent an answer we could not read',
  transport: 'failed: the registrar could not be reached',
  credential_missing: 'not run: no credential saved',
  credential_invalid: 'not run: the saved credential is incomplete',
};

/** DS-13: why customers may see "Couldn't check", without names. */
function AvailabilityCheckLine({ check }: { check: AvailabilityCheck }) {
  const words = AVAILABILITY_OUTCOMES[check.outcome] ?? 'failed';
  return (
    <p className="pc-muted">
      Last availability check ({formatWhen(check.at)}): {words}
      {check.outcome === 'answered' ? ` (${check.answered} of ${check.asked} names)` : ''}.
    </p>
  );
}

function FeedFields({ fields }: { fields: FeedFieldSummary[] }) {
  const sorted = [...fields].sort((a, b) => Number(LIFECYCLE_FIELD.test(b.path)) - Number(LIFECYCLE_FIELD.test(a.path)) || a.path.localeCompare(b.path));
  return (
    <details>
      <summary>Fields the registrar sends ({fields.length})</summary>
      <p className="pc-muted">
        What the extensions in the last price list contained (priced and skipped), by field name. Values are not stored; whole numbers show their range. Period-like fields come first.
      </p>
      <div className="pc-table-scroll">
        <table className="pc-table">
          <caption className="pc-visually-hidden">Fields in the registrar price list</caption>
          <thead>
            <tr>
              <th scope="col">Field</th>
              <th scope="col">In extensions</th>
              <th scope="col">Value kinds</th>
              <th scope="col">Whole-number range</th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((f) => (
              <tr key={f.path}>
                <td>
                  <code>{f.path}</code>
                </td>
                <td>{f.items}</td>
                <td>{f.kinds.join(', ')}</td>
                <td>{f.min === null || f.max === null ? '—' : f.min === f.max ? f.min : `${f.min}–${f.max}`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  );
}

function CostRow({ item }: { item: DomainCostItem }) {
  const amount = (value: number | null) => (value === null ? '—' : formatMinor(value, item.currency));
  return (
    <tr>
      <td className="pc-table__primary">.{item.tld}</td>
      <td>{amount(item.register)}</td>
      <td>{amount(item.renew)}</td>
      <td>{amount(item.transfer)}</td>
      <td>{amount(item.restore)}</td>
      <td>{item.minYears === item.maxYears ? item.minYears : `${item.minYears}–${item.maxYears}`}</td>
    </tr>
  );
}
