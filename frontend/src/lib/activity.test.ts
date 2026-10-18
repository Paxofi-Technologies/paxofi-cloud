import { describe as group, expect, it } from 'vitest';
import { describe, fetchActivity, formatWhen } from './activity';

const event = {
  id: 'a'.repeat(32),
  occurred_at: '2026-10-18T09:12:00Z',
  type: 'auth.sign_in.new_device',
  outcome: 'success',
  details: {},
  ip: '203.0.113.9',
  location: { country: 'NG', region: 'Lagos', city: 'Ikeja' },
  device: { browser: 'Chrome', browser_version: '146', os: 'Windows', os_version: '', type: 'desktop', label: 'Chrome 146 on Windows (desktop)' },
};

const respond = (status: number, body: unknown) =>
  (async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })) as unknown as typeof fetch;

group('fetchActivity', () => {
  it('reads events with where and on what', async () => {
    const events = await fetchActivity(respond(200, { events: [event] }));
    expect(events).toEqual([
      {
        id: 'a'.repeat(32),
        occurredAt: '2026-10-18T09:12:00Z',
        type: 'auth.sign_in.new_device',
        outcome: 'success',
        details: {},
        ip: '203.0.113.9',
        location: 'Ikeja, Lagos, Nigeria',
        device: 'Chrome 146 on Windows (desktop)',
      },
    ]);
  });

  it('keeps purged context as unknown and drops malformed rows', async () => {
    const purged = { ...event, ip: null, location: { country: null, region: null, city: null }, device: null };
    const events = await fetchActivity(respond(200, { events: [purged, { id: 1 }, 'x'] }));
    expect(events).toHaveLength(1);
    expect(events?.[0]).toMatchObject({ ip: null, location: null, device: null });
  });

  it('returns null when signed out or on errors', async () => {
    expect(await fetchActivity(respond(401, {}))).toBeNull();
    expect(await fetchActivity((async () => { throw new Error('offline'); }) as unknown as typeof fetch)).toBeNull();
  });
});

group('describe', () => {
  it('turns codes into plain English', () => {
    expect(describe({ type: 'auth.sign_in.failed', details: { reason: 'wrong_password' } })).toBe('Sign-in failed (wrong password)');
    expect(describe({ type: 'auth.sign_in.succeeded', details: { method: 'password_recovery_code' } })).toBe('Signed in (recovery code)');
    expect(describe({ type: 'mfa.disabled', details: { method: 'totp' } })).toBe('Two-step verification turned off');
  });

  it('never shows an unknown code raw', () => {
    expect(describe({ type: 'something.new', details: { reason: '<b>x</b>' } })).toBe('Security event');
  });
});

group('formatWhen', () => {
  it('shows Nigerian time like the emails', () => {
    expect(formatWhen('2026-10-18T09:12:00Z')).toBe('18 Oct 2026, 10:12 WAT');
    expect(formatWhen('2026-10-18T23:30:00Z')).toBe('19 Oct 2026, 00:30 WAT');
    expect(formatWhen('not a date')).toBe('Unknown time');
  });
});
