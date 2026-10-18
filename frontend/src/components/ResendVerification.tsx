import { useEffect, useState, type FormEvent } from 'react';
import { describeWait, postJson } from '../lib/api';
import { challengeProblem, useChallengeSiteKey } from '../lib/challenge';
import { Alert } from './Alert';
import { Button } from './Button';
import { TextField } from './TextField';
import { Turnstile } from './Turnstile';

export const RESEND_COOLDOWN_SECONDS = 60;

export interface ResendVerificationProps {
  /** The address just registered; when absent the visitor types it. */
  email?: string;
  /** Seconds before the first resend is allowed (the email was just sent). */
  initialCooldown?: number;
  fetchImpl?: typeof fetch | undefined;
}

type Notice = { tone: 'success' | 'warning' | 'danger'; text: string } | null;

/**
 * "Resend the verification email" (SRS IAM-002). The API answers the same for
 * every address (threat model T-49), so the confirmation never says whether an
 * account exists. A 60-second cooldown sits in front of the server limits.
 */
export function ResendVerification({ email, initialCooldown = 0, fetchImpl }: ResendVerificationProps) {
  const [typed, setTyped] = useState('');
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);
  const [cooldown, setCooldown] = useState(initialCooldown);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<Notice>(null);
  const siteKey = useChallengeSiteKey(fetchImpl);
  const [token, setToken] = useState<string | null>(null);
  const [resetSignal, setResetSignal] = useState(0);

  useEffect(() => {
    if (cooldown <= 0) return undefined;
    const timer = window.setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldown]);

  async function resend(event?: FormEvent) {
    event?.preventDefault();
    const address = (email ?? typed).trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address)) {
      setFieldError('Enter the email address you registered with.');
      return;
    }
    setFieldError(undefined);
    setNotice(null);
    if (typeof siteKey === 'string' && token === null) {
      setNotice({ tone: 'warning', text: 'Please complete the security check.' });
      return;
    }
    setLoading(true);
    const body = token === null ? { email: address } : { email: address, turnstile_token: token };
    const result = await postJson('/api/v1/auth/resend-verification', body, fetchImpl);
    setLoading(false);
    setResetSignal((n) => n + 1);

    switch (result.kind) {
      case 'ok':
        setCooldown(RESEND_COOLDOWN_SECONDS);
        setNotice({
          tone: 'success',
          text:
            email === undefined
              ? 'If that address has a sign-up waiting for confirmation, a new link is on its way. Only the newest link works.'
              : 'We have sent a new link. Only the newest link works, so use the latest email.',
        });
        return;
      case 'invalid':
        setFieldError('Enter the email address you registered with.');
        return;
      case 'throttled':
        setCooldown(result.retryAfterSeconds);
        setNotice({ tone: 'warning', text: `Too many emails requested. Please try again in ${describeWait(result.retryAfterSeconds)}.` });
        return;
      case 'rejected':
        setNotice({ tone: 'danger', text: challengeProblem(result.code) ?? 'We could not send the email right now. Please try again in a few minutes.' });
        return;
      case 'unavailable':
        setNotice({ tone: 'danger', text: 'We could not send the email right now. Please try again in a few minutes.' });
    }
  }

  const label = cooldown > 0 ? `Resend email (${cooldown}s)` : email === undefined ? 'Send a new link' : 'Resend email';

  return (
    <form className="pc-auth__form" onSubmit={resend} noValidate>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {email === undefined && (
        <TextField
          label="Email address"
          type="email"
          autoComplete="email"
          value={typed}
          error={fieldError}
          onChange={(e) => setTyped(e.target.value)}
        />
      )}
      {email !== undefined && fieldError && <Alert tone="danger">{fieldError}</Alert>}
      {typeof siteKey === 'string' && <Turnstile siteKey={siteKey} action="resend" onToken={setToken} resetSignal={resetSignal} />}
      <Button type="submit" variant={email === undefined ? 'primary' : 'secondary'} block loading={loading} disabled={cooldown > 0}>
        {label}
      </Button>
    </form>
  );
}
