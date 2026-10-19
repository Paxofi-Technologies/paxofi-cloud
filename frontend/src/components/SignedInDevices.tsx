import { useEffect, useState } from 'react';
import { formatWhen } from '../lib/activity';
import { fetchSessions, revokeOtherSessions, revokeSession, type DeviceSession } from '../lib/sessions';
import { Alert } from './Alert';
import { Badge } from './Badge';
import { Button } from './Button';
import { Card } from './Card';

/**
 * "Where you're signed in" (threat model T-23, §6.21): every live session of
 * this account, with sign-out for one or all others. Locations are approximate
 * and recorded at sign-in.
 */
export function SignedInDevices({
  csrfToken,
  fetchImpl,
  refreshKey = 0,
  onChanged,
}: {
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  refreshKey?: number;
  onChanged?: () => void;
}) {
  const [sessions, setSessions] = useState<DeviceSession[] | null | 'loading'>('loading');
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
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

  async function signOut(id: string | 'others') {
    setMessage(null);
    setBusy(id);
    const result = id === 'others' ? await revokeOtherSessions(csrfToken, fetchImpl) : await revokeSession(id, csrfToken, fetchImpl);
    setBusy(null);
    if (result.kind === 'ok') {
      setMessage({ tone: 'success', text: id === 'others' ? 'All other devices are signed out.' : 'That device is signed out.' });
      onChanged?.();
    } else if (result.kind === 'rejected' && result.code === 'session_not_found') {
      setMessage({ tone: 'success', text: 'That device was already signed out.' });
    } else {
      setMessage({ tone: 'danger', text: 'We could not sign that device out. Reload the page and try again.' });
    }
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
                        <Button size="sm" variant="secondary" loading={busy === session.id} onClick={() => void signOut(session.id)}>
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
        {others.length > 0 && (
          <div>
            <Button variant="secondary" loading={busy === 'others'} onClick={() => void signOut('others')}>
              Sign out all other devices
            </Button>
          </div>
        )}
        {Array.isArray(sessions) && others.length === 0 && <p className="pc-muted">You are not signed in anywhere else.</p>}
      </div>
    </Card>
  );
}
