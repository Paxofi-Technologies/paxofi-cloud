/**
 * The signed-in customer's recent security activity (identity threat model
 * §6.14, T-52). The API only ever returns the session's own events; values
 * are rendered as text, never as HTML.
 */
export interface ActivityEvent {
  id: string;
  occurredAt: string;
  type: string;
  outcome: 'success' | 'failure';
  details: Record<string, string | number | boolean>;
  ip: string | null;
  location: string | null;
  device: string | null;
}

export async function fetchActivity(fetchImpl: typeof fetch = fetch): Promise<ActivityEvent[] | null> {
  try {
    const response = await fetchImpl('/api/v1/account/activity', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    if (!response.ok) return null;
    const data = (await response.json()) as { events?: unknown };
    if (!Array.isArray(data.events)) return null;
    return data.events.map(readEvent).filter((e): e is ActivityEvent => e !== null);
  } catch {
    return null;
  }
}

function readEvent(raw: unknown): ActivityEvent | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const e = raw as Record<string, unknown>;
  if (typeof e.id !== 'string' || typeof e.occurred_at !== 'string' || typeof e.type !== 'string' || (e.outcome !== 'success' && e.outcome !== 'failure')) {
    return null;
  }
  const location = typeof e.location === 'object' && e.location !== null ? (e.location as Record<string, unknown>) : {};
  const device = typeof e.device === 'object' && e.device !== null ? (e.device as Record<string, unknown>) : null;
  const details: Record<string, string | number | boolean> = {};
  if (typeof e.details === 'object' && e.details !== null) {
    for (const [key, value] of Object.entries(e.details as Record<string, unknown>)) {
      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') details[key] = value;
    }
  }
  const where = describeLocation(location.city, location.region, location.country) ?? '';

  return {
    id: e.id,
    occurredAt: e.occurred_at,
    type: e.type,
    outcome: e.outcome,
    details,
    ip: typeof e.ip === 'string' && e.ip !== '' ? e.ip : null,
    location: where === '' ? null : where,
    device: device !== null && typeof device.label === 'string' ? device.label : null,
  };
}

const LABELS: Record<string, string> = {
  'identity.registered': 'Account created',
  'identity.registration_repeated': 'Sign-up form sent again',
  'identity.registration_existing_account': 'Someone tried to sign up with your email',
  'identity.email_verified': 'Email address confirmed',
  'identity.verification_resent': 'Confirmation email sent again',
  'auth.sign_in.succeeded': 'Signed in',
  'auth.sign_in.failed': 'Sign-in failed',
  'auth.sign_in.mfa_required': 'Password accepted, code requested',
  'auth.sign_in.many_failures': 'Many failed sign-in attempts',
  'auth.sign_in.new_device': 'Sign-in from a new device',
  'auth.mfa.succeeded': 'Two-step code accepted',
  'auth.mfa.failed': 'Two-step code rejected',
  'auth.mfa.locked': 'Two-step sign-in locked for 15 minutes',
  'auth.signed_out': 'Signed out',
  'auth.session.revoked': 'Signed out another device',
  'auth.sessions.revoked_others': 'Signed out all other devices',
  'mfa.enabled': 'Two-step verification turned on',
  'mfa.disabled': 'Two-step verification turned off',
  'mfa.recovery_codes_regenerated': 'New recovery codes created',
  'mfa.change_refused': 'Security change refused',
  'billing.details_saved': 'Billing details updated',
};

const REASONS: Record<string, string> = {
  wrong_password: 'wrong password',
  unknown_account: 'unknown account',
  email_not_verified: 'email not confirmed',
  account_unavailable: 'account unavailable',
  incorrect: 'incorrect code',
  locked: 'locked',
  wrong_code: 'wrong code',
  wrong_recovery_code: 'wrong recovery code',
};

/** "Ikeja, Lagos, Nigeria" from Cloudflare's approximate location; null when nothing is known. */
export function describeLocation(city: unknown, region: unknown, country: unknown): string | null {
  const c = typeof city === 'string' && city !== '' ? city : null;
  const r = typeof region === 'string' && region !== '' && region !== c ? region : null;
  const n = typeof country === 'string' && country !== '' ? countryName(country) : null;
  const where = [c, r, n].filter((part): part is string => part !== null).join(', ');

  return where === '' ? null : where;
}

/** The plain-English label for a known event type, or null. */
export function eventLabel(type: string): string | null {
  return LABELS[type] ?? null;
}

/** Plain-English description; unknown types fall back to a generic label rather than raw codes. */
export function describe(event: Pick<ActivityEvent, 'type' | 'details'>): string {
  const label = LABELS[event.type] ?? 'Security event';
  const reason = event.details.reason;
  if (typeof reason === 'string' && REASONS[reason] !== undefined) return `${label} (${REASONS[reason]})`;
  if (event.details.method === 'recovery_code' || event.details.method === 'password_recovery_code') return `${label} (recovery code)`;

  return label;
}

/** "18 Oct 2026, 10:12 WAT" — Nigerian time, matching the security emails. */
export function formatWhen(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return 'Unknown time';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Africa/Lagos',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';

  return `${get('day')} ${get('month')} ${get('year')}, ${get('hour')}:${get('minute')} WAT`;
}

function countryName(code: string): string {
  try {
    return new Intl.DisplayNames(['en'], { type: 'region' }).of(code) ?? code;
  } catch {
    return code;
  }
}
