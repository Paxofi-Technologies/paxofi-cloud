import { postJson, type ApiResult } from './api';

/**
 * The customer's own signed-in sessions (threat model T-23, §6.21). IDs are
 * list names only; they cannot be used to sign in.
 */
export interface DeviceSession {
  id: string;
  current: boolean;
  signedInAt: string;
  lastSeenAt: string;
  ip: string | null;
  location: string | null;
  device: string;
}

function obj(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : null;
}

/** null when the list could not be loaded. */
export async function fetchSessions(fetchImpl: typeof fetch = fetch): Promise<DeviceSession[] | null> {
  let response: Response;
  try {
    response = await fetchImpl('/api/v1/account/sessions', { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
  } catch {
    return null;
  }
  if (!response.ok) return null;
  let data: unknown;
  try {
    data = await response.json();
  } catch {
    return null;
  }
  const rows = obj(data)?.sessions;
  if (!Array.isArray(rows)) return null;
  const sessions: DeviceSession[] = [];
  for (const entry of rows) {
    const r = obj(entry);
    if (!r || typeof r.id !== 'string' || !/^[0-9a-f]{32}$/.test(r.id) || typeof r.signed_in_at !== 'string' || typeof r.last_seen_at !== 'string') continue;
    sessions.push({
      id: r.id,
      current: r.current === true,
      signedInAt: r.signed_in_at,
      lastSeenAt: r.last_seen_at,
      ip: typeof r.ip === 'string' ? r.ip : null,
      location: typeof r.location === 'string' ? r.location : null,
      device: typeof obj(r.device)?.label === 'string' ? (obj(r.device)?.label as string) : 'Unknown device',
    });
  }
  return sessions;
}

/**
 * Signing devices out needs the password, plus a current code or a recovery
 * code while two-step verification is on (§6.21, T-22), so a stolen session
 * alone cannot sign the owner out.
 */
export interface StepUp {
  password: string;
  factor?: { kind: 'code' | 'recovery'; value: string } | undefined;
}

function stepUpBody(proof: StepUp): Record<string, string> {
  if (!proof.factor) return { password: proof.password };
  return proof.factor.kind === 'code' ? { password: proof.password, code: proof.factor.value } : { password: proof.password, recovery_code: proof.factor.value };
}

export const revokeSession = (id: string, proof: StepUp, csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<ApiResult> =>
  postJson('/api/v1/account/sessions/revoke', { id, ...stepUpBody(proof) }, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const revokeOtherSessions = (proof: StepUp, csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<ApiResult> =>
  postJson('/api/v1/account/sessions/revoke-others', stepUpBody(proof), fetchImpl, { 'X-CSRF-Token': csrfToken });
