import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Checkbox, RecentActivity, TextField } from '../components';
import { QrCode } from '../components/QrCode';
import { describeWait, type ApiResult } from '../lib/api';
import {
  confirmSetup,
  disableMfa,
  fetchMfaStatus,
  groupSecret,
  newRecoveryCodes,
  readRecoveryCodes,
  readSetup,
  startSetup,
  type MfaStatus,
} from '../lib/mfa';

export interface SecurityScreenProps {
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  /** Lets the rest of the portal (e.g. the dashboard banner) follow changes. */
  onStatusChange?: (status: MfaStatus) => void;
}

type View =
  | { kind: 'overview' }
  | { kind: 'password'; purpose: 'enable' | 'codes' }
  | { kind: 'scan'; secret: string; uri: string }
  | { kind: 'codes'; codes: string[] }
  | { kind: 'disable' };

/**
 * Two-step verification settings (SRS IAM-007, IAM-013, IAM-014; threat model
 * §6.13). Every change asks for the password again; the secret and recovery
 * codes exist only in this component's state while they are on screen.
 */
export function SecurityScreen({ csrfToken, fetchImpl, onStatusChange }: SecurityScreenProps) {
  const [status, setStatus] = useState<MfaStatus | null | 'loading'>('loading');
  const [view, setView] = useState<View>({ kind: 'overview' });
  // Bumped after every change so the activity list shows it straight away.
  const [activityKey, setActivityKey] = useState(0);

  async function refresh() {
    setActivityKey((key) => key + 1);
    const next = await fetchMfaStatus(fetchImpl);
    setStatus(next);
    if (next) onStatusChange?.(next);
  }

  useEffect(() => {
    let active = true;
    void fetchMfaStatus(fetchImpl).then((next) => {
      if (!active) return;
      setStatus(next);
      if (next) onStatusChange?.(next);
    });
    return () => {
      active = false;
    };
    // onStatusChange is a notification only; re-fetching when it changes is not wanted.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetchImpl]);

  return (
    <div className="pc-stack">
      <div className="pc-page-header">
        <div>
          <h1>Security</h1>
          <p className="pc-muted">Protect your domains, hosting and servers with a second step at sign-in.</p>
        </div>
      </div>
      <Card>
        {status === 'loading' && <p role="status">Loading…</p>}
        {status === null && <Alert tone="danger">We could not load your security settings. Please reload the page.</Alert>}
        {status !== 'loading' && status !== null && (
          <>
            {view.kind === 'overview' && <Overview status={status} onEnable={() => setView({ kind: 'password', purpose: 'enable' })} onNewCodes={() => setView({ kind: 'password', purpose: 'codes' })} onDisable={() => setView({ kind: 'disable' })} />}
            {view.kind === 'password' && (
              <PasswordStep
                purpose={view.purpose}
                csrfToken={csrfToken}
                fetchImpl={fetchImpl}
                onCancel={() => setView({ kind: 'overview' })}
                onSetup={(secret, uri) => setView({ kind: 'scan', secret, uri })}
                onCodes={(codes) => setView({ kind: 'codes', codes })}
              />
            )}
            {view.kind === 'scan' && (
              <ScanStep
                secret={view.secret}
                uri={view.uri}
                csrfToken={csrfToken}
                fetchImpl={fetchImpl}
                onCancel={() => setView({ kind: 'overview' })}
                onEnabled={(codes) => setView({ kind: 'codes', codes })}
              />
            )}
            {view.kind === 'codes' && (
              <RecoveryCodesView
                codes={view.codes}
                onDone={() => {
                  setView({ kind: 'overview' });
                  void refresh();
                }}
              />
            )}
            {view.kind === 'disable' && (
              <DisableStep
                csrfToken={csrfToken}
                fetchImpl={fetchImpl}
                onCancel={() => setView({ kind: 'overview' })}
                onDisabled={() => {
                  setView({ kind: 'overview' });
                  void refresh();
                }}
              />
            )}
          </>
        )}
      </Card>
      <RecentActivity fetchImpl={fetchImpl} refreshKey={activityKey} />
    </div>
  );
}

function Overview({ status, onEnable, onNewCodes, onDisable }: { status: MfaStatus; onEnable: () => void; onNewCodes: () => void; onDisable: () => void }) {
  if (!status.totpEnabled) {
    return (
      <div className="pc-stack">
        <div className="pc-row-between">
          <h2>Two-step verification</h2>
          <Badge tone="warning">Off</Badge>
        </div>
        <p>
          With two-step verification on, signing in needs your password <strong>and</strong> a 6-digit code from an authenticator app such as Google
          Authenticator, Microsoft Authenticator or 1Password. A stolen password alone is then not enough.
        </p>
        <div>
          <Button onClick={onEnable}>Turn on two-step verification</Button>
        </div>
      </div>
    );
  }

  return (
    <div className="pc-stack">
      <div className="pc-row-between">
        <h2>Two-step verification</h2>
        <Badge tone="success">On</Badge>
      </div>
      <p>Signing in asks for a code from your authenticator app.</p>
      {status.recoveryCodesLeft <= 3 ? (
        <Alert tone="warning">
          You have {status.recoveryCodesLeft} recovery code{status.recoveryCodesLeft === 1 ? '' : 's'} left. Get a new set so you are not locked out if you lose your phone.
        </Alert>
      ) : (
        <p className="pc-muted">{status.recoveryCodesLeft} unused recovery codes.</p>
      )}
      <div className="pc-row">
        <Button variant="secondary" onClick={onNewCodes}>
          Get new recovery codes
        </Button>
        <Button variant="ghost" onClick={onDisable}>
          Turn off
        </Button>
      </div>
    </div>
  );
}

function PasswordStep({
  purpose,
  csrfToken,
  fetchImpl,
  onCancel,
  onSetup,
  onCodes,
}: {
  purpose: 'enable' | 'codes';
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onSetup: (secret: string, uri: string) => void;
  onCodes: (codes: string[]) => void;
}) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (password === '') {
      setError('Enter your password.');
      return;
    }
    setError(undefined);
    setProblem(null);
    setLoading(true);
    const result = purpose === 'enable' ? await startSetup(password, csrfToken, fetchImpl) : await newRecoveryCodes(password, csrfToken, fetchImpl);
    setLoading(false);
    setPassword('');

    if (result.kind === 'ok') {
      if (purpose === 'enable') {
        const setup = readSetup(result.data);
        if (setup) onSetup(setup.secret, setup.uri);
        else setProblem('Something went wrong. Please try again.');
      } else {
        const codes = readRecoveryCodes(result.data);
        if (codes) onCodes(codes);
        else setProblem('Something went wrong. Please try again.');
      }
      return;
    }
    if (result.kind === 'invalid' && result.errors.password) setError('That password is not right.');
    else setProblem(problemText(result));
  }

  return (
    <form className="pc-stack" onSubmit={submit} noValidate>
      <h2>{purpose === 'enable' ? 'Confirm it is you' : 'Get new recovery codes'}</h2>
      <p className="pc-muted">
        {purpose === 'enable' ? 'Enter your password to start setting up two-step verification.' : 'Enter your password. Your old recovery codes will stop working.'}
      </p>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={error} required />
      <div className="pc-row">
        <Button type="submit" loading={loading}>
          Continue
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function ScanStep({
  secret,
  uri,
  csrfToken,
  fetchImpl,
  onCancel,
  onEnabled,
}: {
  secret: string;
  uri: string;
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onEnabled: (codes: string[]) => void;
}) {
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code shown in your app.');
      return;
    }
    setError(undefined);
    setProblem(null);
    setLoading(true);
    const result = await confirmSetup(code, csrfToken, fetchImpl);
    setLoading(false);
    setCode('');

    if (result.kind === 'ok') {
      const codes = readRecoveryCodes(result.data);
      if (codes) onEnabled(codes);
      else setProblem('Something went wrong. Please try again.');
      return;
    }
    if (result.kind === 'invalid') setError('That code is not right. Check the time on your phone and try the newest code.');
    else setProblem(problemText(result));
  }

  return (
    <form className="pc-stack" onSubmit={submit} noValidate>
      <h2>Scan this code with your authenticator app</h2>
      <div className="pc-mfa-setup">
        <QrCode value={uri} label="QR code for your authenticator app" />
        <div className="pc-stack">
          <p className="pc-muted">Can't scan it? Add an account in the app manually with this key (time-based):</p>
          <code className="pc-secret">{groupSecret(secret)}</code>
          <p className="pc-muted">Then enter the 6-digit code the app shows for PaxofiCloud.</p>
        </div>
      </div>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField
        label="Verification code"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={error}
        required
      />
      <div className="pc-row">
        <Button type="submit" loading={loading}>
          Turn on
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function RecoveryCodesView({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const [saved, setSaved] = useState(false);
  const [copied, setCopied] = useState(false);
  const text = `PaxofiCloud recovery codes\nEach code works once.\n\n${codes.join('\n')}\n`;

  return (
    <div className="pc-stack">
      <h2>Save your recovery codes</h2>
      <Alert tone="warning">
        If you lose your phone, a recovery code is the only way back in without contacting support. Each code works once. They will not be shown again.
      </Alert>
      <ol className="pc-recovery-codes" aria-label="Recovery codes">
        {codes.map((code) => (
          <li key={code}>
            <code>{code}</code>
          </li>
        ))}
      </ol>
      <div className="pc-row">
        <Button
          variant="secondary"
          onClick={() => {
            void navigator.clipboard?.writeText(text).then(() => setCopied(true));
          }}
        >
          {copied ? 'Copied' : 'Copy codes'}
        </Button>
        <Button
          variant="secondary"
          onClick={() => {
            const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = 'paxoficloud-recovery-codes.txt';
            link.click();
            URL.revokeObjectURL(url);
          }}
        >
          Download
        </Button>
      </div>
      <Checkbox checked={saved} onChange={(e) => setSaved(e.target.checked)} label="I have saved these codes somewhere safe" />
      <div>
        <Button disabled={!saved} onClick={onDone}>
          Done
        </Button>
      </div>
    </div>
  );
}

function DisableStep({ csrfToken, fetchImpl, onCancel, onDisabled }: { csrfToken: string; fetchImpl?: typeof fetch | undefined; onCancel: () => void; onDisabled: () => void }) {
  const [password, setPassword] = useState('');
  const [factor, setFactor] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [errors, setErrors] = useState<{ password?: string | undefined; factor?: string | undefined }>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const next: typeof errors = {};
    if (password === '') next.password = 'Enter your password.';
    if (useRecovery ? factor.trim() === '' : !/^\d{6}$/.test(factor)) next.factor = useRecovery ? 'Enter a recovery code.' : 'Enter the 6-digit code from your app.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const result = await disableMfa(password, { kind: useRecovery ? 'recovery' : 'code', value: factor.trim() }, csrfToken, fetchImpl);
    setLoading(false);
    setPassword('');
    setFactor('');

    if (result.kind === 'ok') {
      onDisabled();
      return;
    }
    if (result.kind === 'invalid') {
      setErrors({
        password: result.errors.password ? 'That password is not right.' : undefined,
        factor: result.errors.code || result.errors.recovery_code ? 'That code is not right.' : undefined,
      });
      return;
    }
    setProblem(problemText(result));
  }

  return (
    <form className="pc-stack" onSubmit={submit} noValidate>
      <h2>Turn off two-step verification</h2>
      <Alert tone="warning">Your account will be protected by your password only.</Alert>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} required />
      <TextField
        label={useRecovery ? 'Recovery code' : 'Verification code'}
        inputMode={useRecovery ? 'text' : 'numeric'}
        autoComplete="one-time-code"
        maxLength={useRecovery ? 24 : 6}
        value={factor}
        onChange={(e) => setFactor(useRecovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
        error={errors.factor}
        required
      />
      <div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => {
            setUseRecovery((v) => !v);
            setFactor('');
          }}
        >
          {useRecovery ? 'Use a code from my app instead' : 'Use a recovery code instead'}
        </Button>
      </div>
      <div className="pc-row">
        <Button type="submit" loading={loading}>
          Turn off
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function problemText(result: ApiResult): string {
  switch (result.kind) {
    case 'throttled':
      return `Too many attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.`;
    case 'rejected':
      if (result.code === 'mfa_unavailable') return 'Two-step verification is not available right now. Please try again later.';
      if (result.code === 'mfa_already_enabled') return 'Two-step verification is already on.';
      if (result.code === 'not_signed_in' || result.code === 'csrf_token_invalid') return 'Your session has ended. Please sign in again.';
      return 'Something went wrong. Please try again.';
    default:
      return 'We could not reach PaxofiCloud. Check your connection and try again.';
  }
}
