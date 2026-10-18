import { useEffect, useState } from 'react';
import { describe, fetchActivity, formatWhen, type ActivityEvent } from '../lib/activity';
import { Alert } from './Alert';
import { Badge } from './Badge';
import { Card } from './Card';

/**
 * "Recent security activity": the last 20 security events on the account,
 * with where and on what device they happened (threat model §6.14, T-52).
 * Location is approximate; failed attempts by others show up here too, so
 * the owner can spot them.
 */
export function RecentActivity({ fetchImpl, refreshKey = 0 }: { fetchImpl?: typeof fetch | undefined; refreshKey?: number }) {
  const [events, setEvents] = useState<ActivityEvent[] | null | 'loading'>('loading');

  useEffect(() => {
    let active = true;
    void fetchActivity(fetchImpl).then((next) => {
      if (active) setEvents(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, refreshKey]);

  return (
    <Card>
      <div className="pc-stack">
        <div>
          <h2>Recent security activity</h2>
          <p className="pc-muted">Sign-ins, failed attempts and security changes on your account. Locations are approximate.</p>
        </div>
        {events === 'loading' && <p role="status">Loading…</p>}
        {events === null && <Alert tone="danger">We could not load your recent activity. Please reload the page.</Alert>}
        {Array.isArray(events) && events.length === 0 && <p className="pc-muted">No activity yet.</p>}
        {Array.isArray(events) && events.length > 0 && (
          <div className="pc-table-scroll">
            <table className="pc-table">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Activity</th>
                  <th scope="col">Where</th>
                  <th scope="col">Device</th>
                  <th scope="col">IP address</th>
                </tr>
              </thead>
              <tbody>
                {events.map((event) => (
                  <tr key={event.id}>
                    <td>{formatWhen(event.occurredAt)}</td>
                    <td>
                      <span className="pc-table__primary">{describe(event)}</span>{' '}
                      {event.outcome === 'failure' && <Badge tone="danger">Failed</Badge>}
                    </td>
                    <td>{event.location ?? 'Unknown'}</td>
                    <td>{event.device ?? 'Unknown'}</td>
                    <td>{event.ip ?? 'Unknown'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {Array.isArray(events) && events.some((e) => e.outcome === 'failure') && (
          <p className="pc-muted">Don't recognise something here? Change your password and turn on two-step verification.</p>
        )}
      </div>
    </Card>
  );
}
