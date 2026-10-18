import { postJson, type ApiResult } from './api';

/**
 * The customer's server-side session (threat model D-6, §6.11). The session
 * cookie is HttpOnly, so this code never sees it; it only learns who is signed
 * in and the CSRF token that later writes must send (T-21). The token lives in
 * memory only, never in localStorage.
 */
export interface SignedIn {
  fullName: string;
  email: string;
  csrfToken: string;
}

export type SessionState = { kind: 'signed_in'; session: SignedIn } | { kind: 'signed_out' } | { kind: 'unavailable' };

export async function fetchSession(fetchImpl: typeof fetch = fetch): Promise<SessionState> {
  let response: Response;
  try {
    response = await fetchImpl('/api/v1/auth/session', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
  } catch {
    return { kind: 'unavailable' };
  }
  if (response.status === 401) return { kind: 'signed_out' };
  if (!response.ok) return { kind: 'unavailable' };

  try {
    const session = parseSession((await response.json()) as unknown);
    return session === null ? { kind: 'unavailable' } : { kind: 'signed_in', session };
  } catch {
    return { kind: 'unavailable' };
  }
}

export function signIn(email: string, password: string, fetchImpl: typeof fetch = fetch): Promise<ApiResult> {
  return postJson('/api/v1/auth/sign-in', { email, password }, fetchImpl);
}

export function signOut(csrfToken: string, fetchImpl: typeof fetch = fetch): Promise<ApiResult> {
  return postJson('/api/v1/auth/sign-out', {}, fetchImpl, { 'X-CSRF-Token': csrfToken });
}

function parseSession(data: unknown): SignedIn | null {
  if (typeof data !== 'object' || data === null) return null;
  const record = data as Record<string, unknown>;
  const identity = record.identity;
  if (typeof identity !== 'object' || identity === null || typeof record.csrf_token !== 'string') return null;
  const { full_name: fullName, email } = identity as Record<string, unknown>;
  if (typeof fullName !== 'string' || typeof email !== 'string') return null;

  return { fullName, email, csrfToken: record.csrf_token };
}
