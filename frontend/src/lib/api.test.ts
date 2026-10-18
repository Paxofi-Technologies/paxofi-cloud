import { describe, expect, it, vi } from 'vitest';
import { describeWait, postJson } from './api';

function respond(status: number, body: unknown = {}, headers: Record<string, string> = {}): typeof fetch {
  return vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers }))) as unknown as typeof fetch;
}

describe('postJson', () => {
  it('sends a same-origin JSON POST', async () => {
    const fetchImpl = respond(202);
    await postJson('/api/v1/auth/register', { a: 1 }, fetchImpl);
    expect(fetchImpl).toHaveBeenCalledWith('/api/v1/auth/register', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: '{"a":1}',
    });
  });

  it('classifies responses', async () => {
    expect(await postJson('/x', {}, respond(202))).toEqual({ kind: 'ok', status: 202, data: null });
    expect(await postJson('/x', {}, respond(422, { code: 'validation_failed', errors: { email: 'invalid', bad: 3 } }))).toEqual({
      kind: 'invalid',
      errors: { email: 'invalid' },
    });
    expect(await postJson('/x', {}, respond(429, {}, { 'retry-after': '120' }))).toEqual({ kind: 'throttled', retryAfterSeconds: 120 });
    expect(await postJson('/x', {}, respond(400, { code: 'verification_link_invalid' }))).toEqual({ kind: 'rejected', code: 'verification_link_invalid' });
    expect(await postJson('/x', {}, respond(503))).toEqual({ kind: 'unavailable' });
    const offline = vi.fn(() => Promise.reject(new TypeError('offline'))) as unknown as typeof fetch;
    expect(await postJson('/x', {}, offline)).toEqual({ kind: 'unavailable' });
  });

  it('describes waits in plain words', () => {
    expect(describeWait(30)).toBe('a minute');
    expect(describeWait(600)).toBe('10 minutes');
    expect(describeWait(3600)).toBe('about an hour');
    expect(describeWait(7200)).toBe('about 2 hours');
  });

  it('passes the fail-closed bot check refusal to the form instead of a generic outage', async () => {
    expect(await postJson('/x', {}, respond(503, { code: 'challenge_unavailable' }))).toEqual({ kind: 'rejected', code: 'challenge_unavailable' });
    expect(await postJson('/x', {}, respond(503, { code: 'maintenance' }))).toEqual({ kind: 'unavailable' });
  });
});
