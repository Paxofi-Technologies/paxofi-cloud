import { postJson, type ApiResult } from './api';

/**
 * Two-step verification API (identity threat model §6.13). Every write sends
 * the session's CSRF token; the secret and recovery codes live only in React
 * state while shown and are never written to storage.
 */
export interface MfaStatus {
  totpEnabled: boolean;
  recoveryCodesLeft: number;
}

export async function fetchMfaStatus(fetchImpl: typeof fetch = fetch): Promise<MfaStatus | null> {
  try {
    const response = await fetchImpl('/api/v1/account/mfa', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) return null;
    const data = (await response.json()) as Record<string, unknown>;
    if (typeof data.totp_enabled !== 'boolean' || typeof data.recovery_codes_left !== 'number') return null;
    return { totpEnabled: data.totp_enabled, recoveryCodesLeft: data.recovery_codes_left };
  } catch {
    return null;
  }
}

const write = (path: string, body: Record<string, string>, csrfToken: string, fetchImpl: typeof fetch) =>
  postJson(`/api/v1/account/mfa${path}`, body, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const startSetup = (password: string, csrf: string, f: typeof fetch = fetch) => write('/totp/setup', { password }, csrf, f);
export const confirmSetup = (code: string, csrf: string, f: typeof fetch = fetch) => write('/totp/confirm', { code }, csrf, f);
export const newRecoveryCodes = (password: string, csrf: string, f: typeof fetch = fetch) => write('/recovery-codes', { password }, csrf, f);
export const disableMfa = (password: string, factor: SecondFactor, csrf: string, f: typeof fetch = fetch) =>
  write('/disable', { password, ...factorBody(factor) }, csrf, f);

/** The second sign-in step; the ticket travels in an HttpOnly cookie. */
export const completeSignIn = (factor: SecondFactor, f: typeof fetch = fetch): Promise<ApiResult> =>
  postJson('/api/v1/auth/sign-in/mfa', factorBody(factor), f);

export type SecondFactor = { kind: 'code'; value: string } | { kind: 'recovery'; value: string };

function factorBody(factor: SecondFactor): Record<string, string> {
  return factor.kind === 'code' ? { code: factor.value } : { recovery_code: factor.value };
}

export function readSetup(data: unknown): { secret: string; uri: string } | null {
  if (typeof data !== 'object' || data === null) return null;
  const { secret, otpauth_uri: uri } = data as Record<string, unknown>;
  return typeof secret === 'string' && typeof uri === 'string' && uri.startsWith('otpauth://totp/') ? { secret, uri } : null;
}

export function readRecoveryCodes(data: unknown): string[] | null {
  if (typeof data !== 'object' || data === null) return null;
  const codes = (data as Record<string, unknown>).recovery_codes;
  return Array.isArray(codes) && codes.every((c) => typeof c === 'string') ? (codes as string[]) : null;
}

/** "ABCD EFGH …" groups of four, easier to type into an app by hand. */
export function groupSecret(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim();
}
