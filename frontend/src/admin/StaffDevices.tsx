import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import { formatWhen } from '../lib/activity';
import { fetchStaffSessions, revokeOtherStaffSessions, revokeStaffSession, type StaffDeviceSession } from './adminApi';

type Target = { id: string; device: string } | 'others';
type Message = { tone: 'success' | 'danger'; text: string };

/** Plain words for a refused staff session change (§6.25). */
export function sessionChangeProblem(result: ApiResult): string {
  if (result.kind === 'throttled') return `Too many attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'rejected' && result.code === 'step_up_required') return 'That authenticator code is not right, or it was already used. Wait for the next code and try again.';
  if (result.kind === 'rejected' && (result.code === 'not_signed_in' || result.code === 'csrf_token_invalid')) return 'Your session has ended. Please sign in again.';
  if (result.kind === 'unavailable') return 'We could not reach PaxofiCloud. Check your connection and try again.';
  return 'That did not work. Reload the page and try again.';
}

/**
 * "Where you're signed in" for staff (identity threat model §6.25): every
 * live session of this staff account, with sign-out for one or all others.
 * Each sign-out needs a fresh authenticator code, so a stolen session alone
 * cannot sign the owner out; the owner is emailed. Locations are approximate.
 */
export function StaffDevices({ csrfToken, fetchImpl }: { csrfToken: string; fetchImpl?: typeof fetch | undefined }) {
  const [sessions, setSessions] = useState<StaffDeviceSession[] | null | 'loading'>('loading');
  const [target, setTarget] = useState<Target | null>(null);
  const [message, setMessage] = useState<Message | null>(null);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let active = true;
    void fetchStaffSessions(fetchImpl).then((next) => {
      if (active) setSessions(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version]);

  function done(next: Message) {
    setTarget(null);
    setMessage(next);
    setVersion((n) => n + 1);
  }

  const others = Array.isArray(sessions) ? sessions.filter((s) => !s.current) : [];

  return (
    <Card title="Where you're signed in">
      <div className="pc-stack">
        <p className="pc-muted">Devices signed in to your staff account now. Locations are approximate. If you don't recognise one, sign it out and tell the security team.</p>
        {message && <Alert tone={message.tone}>{message.text}</Alert>}
        {target && (
          <ConfirmWithCode
            key={target === 'others' ? 'others' : target.id}
            title={target === 'others' ? 'Sign out all other devices' : `Sign out ${target.device}`}
            action={target === 'others' ? 'Sign out all other devices' : 'Sign out device'}
            onCancel={() => setTarget(null)}
            submit={(code) => (target === 'others' ? revokeOtherStaffSessions(csrfToken, code, fetchImpl) : revokeStaffSession(csrfToken, target.id, code, fetchImpl))}
            onDone={(result) => {
              if (result.kind === 'rejected' && result.code === 'not_found') {
                done({ tone: 'success', text: 'That device was already signed out.' });
                return true;
              }
              if (result.kind !== 'ok') return false;
              done({ tone: 'success', text: target === 'others' ? 'All other devices are signed out.' : 'That device is signed out.' });
              return true;
            }}
          />
        )}
        {sessions === 'loading' && <p role="status">Loading…</p>}
        {sessions === null && <Alert tone="danger">We could not load your signed-in devices. Please reload the page.</Alert>}
        {Array.isArray(sessions) && sessions.length > 0 && (
          <div className="pc-table-scroll">
            <table className="pc-table">
              <caption className="pc-visually-hidden">Devices signed in to your staff account</caption>
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
                {sessions.map((s) => (
                  <tr key={s.id}>
                    <td>
                      <span className="pc-table__primary">{s.device}</span> {s.current && <Badge tone="success">This device</Badge>}
                    </td>
                    <td>{s.location ?? 'Unknown'}</td>
                    <td>{s.ip ?? 'Unknown'}</td>
                    <td>{formatWhen(s.signedInAt)}</td>
                    <td>{formatWhen(s.lastActiveAt)}</td>
                    <td>
                      {!s.current && (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setMessage(null);
                            setTarget({ id: s.id, device: s.device });
                          }}
                        >
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
            <Button
              variant="secondary"
              onClick={() => {
                setMessage(null);
                setTarget('others');
              }}
            >
              Sign out all other devices
            </Button>
          </div>
        )}
        {Array.isArray(sessions) && others.length === 0 && <p className="pc-muted">You are not signed in anywhere else.</p>}
      </div>
    </Card>
  );
}

/**
 * Asks for a fresh 6-digit authenticator code, sends it with `submit`, and
 * lets `onDone` decide whether the result finished the action.
 */
export function ConfirmWithCode({
  title,
  lead = 'Confirm with a fresh code from your authenticator app. We will email the account owner when it is done.',
  action,
  submit,
  onDone,
  onCancel,
}: {
  title: string;
  lead?: string;
  action: string;
  submit: (code: string) => Promise<ApiResult>;
  onDone: (result: ApiResult) => boolean;
  onCancel: () => void;
}) {
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setProblem(null);
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your app.');
      return;
    }
    setError(undefined);
    setLoading(true);
    const result = await submit(code);
    setLoading(false);
    setCode('');
    if (!onDone(result)) setProblem(sessionChangeProblem(result));
  }

  return (
    <form className="pc-stack" onSubmit={onSubmit} noValidate aria-label={title}>
      <h3>{title}</h3>
      <p className="pc-muted">{lead}</p>
      {problem && <Alert tone="danger">{problem}</Alert>}
      <TextField
        label="Authenticator code"
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
          {action}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
