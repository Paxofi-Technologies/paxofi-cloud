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

/** Provider connections (THREAT-MODEL-PROVIDER-CONNECTIONS.md): status only, never a secret (PC-2). */
export interface ProviderConnectionField {
  name: string;
  label: string;
  secret: boolean;
}

export interface ProviderConnectionStatus {
  provider: string;
  label: string;
  fields: ProviderConnectionField[];
  configuredAt: string | null;
  reviewDueOn: string | null;
  pendingSince: string | null;
}

export type ProviderConnectionList = { kind: 'ok'; providers: ProviderConnectionStatus[] } | { kind: 'failed' };

function nullableString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

export async function fetchProviderConnections(fetchImpl: typeof fetch = fetch): Promise<ProviderConnectionList> {
  const result = await getJson(`${ADMIN_API}/provider-connections`, fetchImpl);
  const list = obj(result?.data)?.providers;
  if (result === null || result.status !== 200 || !Array.isArray(list)) return { kind: 'failed' };
  const providers: ProviderConnectionStatus[] = [];
  for (const item of list) {
    const p = obj(item);
    if (!p || typeof p.provider !== 'string' || typeof p.label !== 'string' || !Array.isArray(p.fields)) continue;
    const fields = p.fields
      .map(obj)
      .filter((f): f is Record<string, unknown> => f !== null && typeof f.name === 'string' && typeof f.label === 'string' && typeof f.secret === 'boolean')
      .map((f) => ({ name: f.name as string, label: f.label as string, secret: f.secret as boolean }));
    providers.push({
      provider: p.provider,
      label: p.label,
      fields,
      configuredAt: nullableString(p.configured_at),
      reviewDueOn: nullableString(p.review_due_on),
      pendingSince: nullableString(p.pending_since),
    });
  }
  return { kind: 'ok', providers };
}

export const submitProviderCredential = (csrfToken: string, provider: string, fields: Record<string, string>, code: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/provider-connections`, { provider, fields, code }, fetchImpl, { 'X-CSRF-Token': csrfToken });

/** Domain costs (THREAT-MODEL-DOMAIN-REGISTRAR.md DR-12, DR-13): internal, integer minor units. */
export interface DomainCostItem {
  tld: string;
  currency: string;
  register: number;
  renew: number;
  transfer: number | null;
  restore: number | null;
  minYears: number;
  maxYears: number;
  status: string;
}

export interface CostSnapshot {
  status: string;
  reason: string | null;
  items: number;
  refused: number;
  fetchedAt: string;
}

export interface CostSyncRun {
  trigger: string;
  outcome: string;
  reason: string | null;
  attempts: number;
  startedAt: string;
  finishedAt: string;
}

/** An extension the last accepted sync could not use, and why (DR-14). */
export interface SkippedExtension {
  name: string | null;
  reason: string;
  detail: string | null;
}

export interface DomainCosts {
  provider: string;
  environment: string;
  canSync: boolean;
  dailyAt: string;
  pendingSince: string | null;
  nextRequestAt: string | null;
  latest: CostSnapshot | null;
  quarantined: CostSnapshot | null;
  runs: CostSyncRun[];
  items: DomainCostItem[];
  skipped: SkippedExtension[];
}

export type DomainCostsResult = { kind: 'ok'; costs: DomainCosts } | { kind: 'failed' };

function minor(value: unknown): number | null {
  return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0 ? value : null;
}

function snapshot(value: unknown): CostSnapshot | null {
  const s = obj(value);
  if (!s || typeof s.status !== 'string' || typeof s.fetched_at !== 'string' || minor(s.items) === null || minor(s.refused) === null) return null;
  return { status: s.status, reason: nullableString(s.reason), items: s.items as number, refused: s.refused as number, fetchedAt: s.fetched_at };
}

export async function fetchDomainCosts(fetchImpl: typeof fetch = fetch): Promise<DomainCostsResult> {
  const result = await getJson(`${ADMIN_API}/domain-costs`, fetchImpl);
  const body = obj(result?.data);
  if (result === null || result.status !== 200 || !body || typeof body.provider !== 'string' || !Array.isArray(body.items) || !Array.isArray(body.runs)) {
    return { kind: 'failed' };
  }
  const items: DomainCostItem[] = [];
  for (const raw of body.items) {
    const i = obj(raw);
    const register = minor(i?.register);
    const renew = minor(i?.renew);
    if (!i || typeof i.tld !== 'string' || typeof i.currency !== 'string' || register === null || renew === null) continue;
    items.push({
      tld: i.tld,
      currency: i.currency,
      register,
      renew,
      transfer: minor(i.transfer),
      restore: minor(i.restore),
      minYears: minor(i.min_years) ?? 1,
      maxYears: minor(i.max_years) ?? 1,
      status: typeof i.status === 'string' ? i.status : '',
    });
  }
  const runs: CostSyncRun[] = [];
  for (const raw of body.runs) {
    const r = obj(raw);
    if (!r || typeof r.trigger !== 'string' || typeof r.outcome !== 'string' || typeof r.started_at !== 'string' || typeof r.finished_at !== 'string') continue;
    runs.push({ trigger: r.trigger, outcome: r.outcome, reason: nullableString(r.reason), attempts: minor(r.attempts) ?? 1, startedAt: r.started_at, finishedAt: r.finished_at });
  }
  const skipped: SkippedExtension[] = [];
  for (const raw of Array.isArray(body.skipped) ? body.skipped : []) {
    const k = obj(raw);
    if (!k || typeof k.reason !== 'string') continue;
    skipped.push({ name: nullableString(k.name), reason: k.reason, detail: nullableString(k.detail) });
  }
  return {
    kind: 'ok',
    costs: {
      provider: body.provider,
      environment: typeof body.environment === 'string' ? body.environment : '',
      canSync: body.can_sync === true,
      dailyAt: typeof body.daily_at === 'string' ? body.daily_at : '',
      pendingSince: nullableString(body.pending_since),
      nextRequestAt: nullableString(body.next_request_at),
      latest: snapshot(body.latest),
      quarantined: snapshot(body.quarantined),
      runs,
      items,
      skipped,
    },
  };
}

export const requestDomainCostSync = (csrfToken: string, fetchImpl: typeof fetch = fetch) =>
  postJson(`${ADMIN_API}/domain-costs/sync`, {}, fetchImpl, { 'X-CSRF-Token': csrfToken });

/** Formats integer minor units without floating point, e.g. 123456 USD → "USD 1,234.56". */
export function formatMinor(amount: number, currency: string): string {
  const whole = Math.trunc(amount / 100).toLocaleString('en-NG');
  const cents = String(amount % 100).padStart(2, '0');
  return `${currency} ${whole}.${cents}`;
}
