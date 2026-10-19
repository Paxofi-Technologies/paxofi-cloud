import { postJson, type ApiResult } from '../lib/api';

/**
 * Client for the staff admin API (identity threat model §6.18). It answers
 * only on the admin host; the staff session cookie is HttpOnly and
 * SameSite=Strict, and the CSRF token is kept in memory only.
 */
export const ADMIN_API = '/admin-api/v1';

export const STAFF_ROLES = [
  { value: 'support', label: 'Support' },
  { value: 'billing', label: 'Billing' },
  { value: 'provisioning_operator', label: 'Provisioning operator' },
  { value: 'abuse', label: 'Abuse' },
  { value: 'catalogue', label: 'Catalogue' },
  { value: 'finance', label: 'Finance' },
  { value: 'security_admin', label: 'Security admin' },
  { value: 'super_admin', label: 'Super admin' },
] as const;

/** Super Admin is never an invitation's starting role (SEC-017: it needs a second approver). */
export const INVITABLE_ROLES = STAFF_ROLES.filter((role) => role.value !== 'super_admin');

export function roleLabel(value: string): string {
  return STAFF_ROLES.find((role) => role.value === value)?.label ?? value;
}

export interface StaffSession {
  id: string;
  fullName: string;
  email: string;
  roles: string[];
  permissions: string[];
  /** §6.20: which sign-in methods the member has set up. */
  signIn: { password: boolean; passkeys: number };
  csrfToken: string;
}

export type StaffSessionState = { kind: 'signed_in'; session: StaffSession } | { kind: 'signed_out' } | { kind: 'unavailable' };

export interface StaffMember {
  id: string;
  fullName: string;
  email: string;
  status: string;
  roles: string[];
}

export interface PendingGrant {
  grantId: string;
  staffId: string;
  role: string;
  grantedBy: string;
}

async function getJson(path: string, fetchImpl: typeof fetch): Promise<{ status: number; data: unknown } | null> {
  try {
    const response = await fetchImpl(path, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) return { status: response.status, data: null };
    return { status: response.status, data: (await response.json()) as unknown };
  } catch {
    return null;
  }
}

function obj(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : null;
}

function strings(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [];
}

export async function fetchStaffSession(fetchImpl: typeof fetch = fetch): Promise<StaffSessionState> {
  const result = await getJson(`${ADMIN_API}/auth/session`, fetchImpl);
  if (result === null) return { kind: 'unavailable' };
  if (result.status === 401) return { kind: 'signed_out' };
  const body = obj(result.data);
  const staff = obj(body?.staff);
  if (!body || !staff || typeof staff.id !== 'string' || typeof body.csrf_token !== 'string') return { kind: 'unavailable' };

  return {
    kind: 'signed_in',
    session: {
      id: staff.id,
      fullName: typeof staff.full_name === 'string' ? staff.full_name : '',
      email: typeof staff.email === 'string' ? staff.email : '',
      roles: strings(staff.roles),
      permissions: strings(staff.permissions),
      signIn: {
        password: obj(staff.sign_in)?.password === true,
        passkeys: typeof obj(staff.sign_in)?.passkeys === 'number' ? (obj(staff.sign_in)?.passkeys as number) : 0,
      },
      csrfToken: body.csrf_token,
    },
  };
}

export type StaffList = { kind: 'ok'; staff: StaffMember[]; pendingGrants: PendingGrant[] } | { kind: 'failed' };

export async function fetchStaff(fetchImpl: typeof fetch = fetch): Promise<StaffList> {
  const result = await getJson(`${ADMIN_API}/staff`, fetchImpl);
  const body = obj(result?.data);
  if (!body || !Array.isArray(body.staff)) return { kind: 'failed' };
  const staff: StaffMember[] = [];
  for (const entry of body.staff) {
    const m = obj(entry);
    if (m && typeof m.id === 'string' && typeof m.full_name === 'string' && typeof m.email === 'string' && typeof m.status === 'string') {
      staff.push({ id: m.id, fullName: m.full_name, email: m.email, status: m.status, roles: strings(m.roles) });
    }
  }
  const pendingGrants: PendingGrant[] = [];
  for (const entry of Array.isArray(body.pending_grants) ? body.pending_grants : []) {
    const g = obj(entry);
    if (g && typeof g.grant_id === 'string' && typeof g.staff_id === 'string' && typeof g.role === 'string' && typeof g.granted_by === 'string') {
      pendingGrants.push({ grantId: g.grant_id, staffId: g.staff_id, role: g.role, grantedBy: g.granted_by });
    }
  }
  return { kind: 'ok', staff, pendingGrants };
}

/** A ceremony start: the one-time ceremony ID and the WebAuthn options. */
export function ceremony(result: ApiResult): { ceremonyId: string; options: unknown } | null {
  if (result.kind !== 'ok') return null;
  const body = obj(result.data);
  if (!body || typeof body.ceremony_id !== 'string' || obj(body.options) === null) return null;
  return { ceremonyId: body.ceremony_id, options: body.options };
}

export const signInOptions = (fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/auth/passkey/options`, {}, fetchImpl);

export const finishSignIn = (ceremonyId: string, credential: unknown, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/auth/passkey`, { ceremony_id: ceremonyId, credential }, fetchImpl);

/*
 * Code sign-in (§6.20): password, then the authenticator code, then the code
 * sent by email. The ticket between steps is an HttpOnly cookie the page never sees.
 */
export const signInWithPassword = (email: string, password: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/auth/password`, { email, password }, fetchImpl);

export const submitAuthenticatorCode = (code: string, fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/auth/authenticator-code`, { code }, fetchImpl);

export const submitEmailCode = (code: string, fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/auth/email-code`, { code }, fetchImpl);

export const resendEmailCode = (fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/auth/email-code/resend`, {}, fetchImpl);

/** A setup start: who is being set up and the new authenticator secret. */
export interface SignInSetup {
  fullName: string;
  email: string;
  secret: string;
  uri: string;
}

export function readSetup(result: ApiResult): SignInSetup | null {
  if (result.kind !== 'ok') return null;
  const body = obj(result.data);
  if (!body || typeof body.secret !== 'string' || typeof body.uri !== 'string' || !body.uri.startsWith('otpauth://totp/')) return null;
  return {
    fullName: typeof body.full_name === 'string' ? body.full_name : '',
    email: typeof body.email === 'string' ? body.email : '',
    secret: body.secret,
    uri: body.uri,
  };
}

export const startEnrolment = (token: string, fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/enrolment/start`, { token }, fetchImpl);

export const finishEnrolment = (token: string, password: string, code: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/enrolment`, { token, password, code }, fetchImpl);

/** For members who enrolled with passkeys before codes existed. */
export const startMemberSetup = (csrfToken: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/account/sign-in-setup/start`, {}, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const finishMemberSetup = (csrfToken: string, password: string, code: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/account/sign-in-setup`, { password, code }, fetchImpl, { 'X-CSRF-Token': csrfToken });

/** Optional passkeys: a current authenticator code starts the ceremony. */
export const addPasskeyOptions = (csrfToken: string, code: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/account/passkeys/options`, { code }, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const addPasskey = (csrfToken: string, ceremonyId: string, credential: unknown, label: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/account/passkeys`, { ceremony_id: ceremonyId, credential, label }, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const inviteStaff = (csrfToken: string, invitation: { email: string; fullName: string; role: string }, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/staff/invitations`, { email: invitation.email, full_name: invitation.fullName, role: invitation.role }, fetchImpl, {
    'X-CSRF-Token': csrfToken,
  });

export const approveGrant = (csrfToken: string, grantId: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/staff/roles/approve`, { grant_id: grantId }, fetchImpl, { 'X-CSRF-Token': csrfToken });

export const signOutStaff = (csrfToken: string, fetchImpl: typeof fetch = fetch) => postJson(`${ADMIN_API}/auth/sign-out`, {}, fetchImpl, { 'X-CSRF-Token': csrfToken });
