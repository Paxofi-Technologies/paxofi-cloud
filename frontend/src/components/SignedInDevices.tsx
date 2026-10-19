import { useEffect, useState, type FormEvent } from 'react';
import { formatWhen } from '../lib/activity';
import { describeWait, type ApiResult } from '../lib/api';
import { fetchSessions, revokeOtherSessions, revokeSession, type DeviceSession } from '../lib/sessions';
import { Alert } from './Alert';
import { Badge } from './Badge';
import { Button } from './Button';
import { Card } from './Card';
import { TextField } from './TextField';

type Target = { id: string; device: string } | 'others';
type Message = { tone: 'success' | 'danger'; text: string };

/**
 * "Where you're signed in" (threat model T-23, §6.21): every live session of
 * this account, with sign-out for one or all others. Signing out needs the
 * password (and a code while two-step verification is on), so a stolen
 * session alone cannot sign the owner out. Locations are approximate and
 * recorded at sign-in.
 */
export function SignedInDevices({
  csrfToken,
  mfaEnabled,
  fetchImpl,
  refreshKey = 0,
  onChanged,
}: {
  csrfToken: string;
  mfaEnabled: boolean;
  fetchImpl?: typeof fetch | undefined;
  refreshKey?: number;
  onChanged?: () => void;
}) {
  const [sessions, setSessions] = useState<DeviceSession[] | null | 'loading'>('loading');
  const [target, setTarget] = useState<Target | null>(null);
  const [message, setMessage] = useState<Message | null>(null);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let active = true;
    void fetchSessions(fetchImpl).then((next) => {
      if (active) setSessions(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, refreshKey, version]);

  function choose(next: Target) {
    setMessage(null);
    setTarget(next);
  }

  function done(next: Message, changed: boolean) {
    setTarget(null);
    setMessage(next);
    if (changed) onChanged?.();
    setVersion((n) => n + 1);
  }

  const others = Array.isArray(sessions) ? sessions.filter((s) => !s.current) : [];

  return (
    <Card>
      <div className="pc-stack">
        <div>
          <h2>Where you're signed in</h2>
          <p className="pc-muted">Devices signed in to your account now. Locations are approximate. If you don't recognise one, sign it out and change your password.</p>
        </div>
        {message && <Alert tone={message.tone}>{message.text}</Alert>}
        {target && (
          <ConfirmSignOut
            key={target === 'others' ? 'others' : target.id}
            target={target}
            csrfToken={csrfToken}
            mfaEnabled={mfaEnabled}
            fetchImpl={fetchImpl}
            onCancel={() => setTarget(null)}
            onDone={done}
          />
        )}
        {sessions === 'loading' && <p role="status">Loading…</p>}
        {sessions === null && <Alert tone="danger">We could not load your signed-in devices. Please reload the page.</Alert>}
        {Array.isArray(sessions) && sessions.length > 0 && (
          <div className="pc-table-scroll">
            <table className="pc-table">
              <thead>
                <tr>
                  <th scope="col">Device</th>
                  <th scope="col">Where</th>
                  <th scope="col">IP address</th>
                  <th scope="col">Signed in</th>
                  <th scope="col">Last active</th>
                  <th scope="col">
                    <span className="pc-visually-hidden">Action</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.id}>
                    <td>
                      <span className="pc-table__primary">{session.device}</span> {session.current && <Badge tone="success">This device</Badge>}
                    </td>
                    <td>{session.location ?? 'Unknown'}</td>
                    <td>{session.ip ?? 'Unknown'}</td>
                    <td>{formatWhen(session.signedInAt)}</td>
                    <td>{formatWhen(session.lastSeenAt)}</td>
                    <td>
                      {!session.current && (
                        <Button size="sm" variant="secondary" onClick={() => choose({ id: session.id, device: session.device })}>
                          Sign out
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {others.length > 0 && target === null && (
          <div>
            <Button variant="secondary" onClick={() => choose('others')}>
              Sign out all other devices
            </Button>
          </div>
        )}
        {Array.isArray(sessions) && others.length === 0 && <p className="pc-muted">You are not signed in anywhere else.</p>}
      </div>
    </Card>
  );
}

/** Password (and code) step-up before any device is signed out (T-22). */
function ConfirmSignOut({
  target,
  csrfToken,
  mfaEnabled,
  fetchImpl,
  onCancel,
  onDone,
}: {
  target: Target;
  csrfToken: string;
  mfaEnabled: boolean;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onDone: (message: Message, changed: boolean) => void;
}) {
  const [password, setPassword] = useState('');
  const [factor, setFactor] = useState('');
  // The server decides: if it asks for a code, show the field even when our status was stale.
  const [needsCode, setNeedsCode] = useState(mfaEnabled);
  const [useRecovery, setUseRecovery] = useState(false);
  const [errors, setErrors] = useState<{ password?: string | undefined; factor?: string | undefined }>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const label = target === 'others' ? 'Sign out all other devices' : 'Sign out device';

  async function submit(event: FormEvent) {
    event.preventDefault();
    const next: typeof errors = {};
    if (password === '') next.password = 'Enter your password.';
    if (needsCode && (useRecovery ? factor.trim() === '' : !/^\d{6}$/.test(factor))) next.factor = useRecovery ? 'Enter a recovery code.' : 'Enter the 6-digit code from your app.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const proof = { password, factor: needsCode ? { kind: useRecovery ? ('recovery' as const) : ('code' as const), value: factor.trim() } : undefined };
    const result = target === 'others' ? await revokeOtherSessions(proof, csrfToken, fetchImpl) : await revokeSession(target.id, proof, csrfToken, fetchImpl);
    setLoading(false);
    setPassword('');
    setFactor('');

    if (result.kind === 'ok') {
      onDone({ tone: 'success', text: target === 'others' ? 'All other devices are signed out.' : 'That device is signed out.' }, true);
      return;
    }
    if (result.kind === 'rejected' && result.code === 'session_not_found') {
      onDone({ tone: 'success', text: 'That device was already signed out.' }, false);
      return;
    }
    if (result.kind === 'invalid') {
      const codeError = result.errors.code ?? result.errors.recovery_code;
      if (codeError === 'required') setNeedsCode(true);
      setErrors({
        password: result.errors.password === 'incorrect' ? 'That password is not right.' : result.errors.password ? 'Enter your password.' : undefined,
        factor: codeError === 'incorrect' ? 'That code is not right.' : codeError ? 'Enter the code from your app.' : undefined,
      });
      return;
    }
    setProblem(problemText(result));
  }

  return (
    <form className="pc-stack" onSubmit={submit} noValidate aria-label="Confirm sign-out">
      <h3>{target === 'others' ? 'Sign out all other devices' : `Sign out ${target.device}`}</h3>
      <p className="pc-muted">
        To keep your account safe, confirm it's you with your password{needsCode ? ' and a code from your authenticator app' : ''}. We'll email you when it's done.
      </p>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} required />
      {needsCode && (
        <>
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
        </>
      )}
      <div className="pc-row">
        <Button type="submit" loading={loading}>
          {label}
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
      if (result.code === 'not_signed_in' || result.code === 'csrf_token_invalid') return 'Your session has ended. Please sign in again.';
      return 'We could not sign that device out. Reload the page and try again.';
    default:
      return 'We could not reach PaxofiCloud. Check your connection and try again.';
  }
}
