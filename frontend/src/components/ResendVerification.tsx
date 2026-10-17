import { useEffect, useState, type FormEvent } from 'react';
import { describeWait, postJson } from '../lib/api';
import { Alert } from './Alert';
import { Button } from './Button';
import { TextField } from './TextField';

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
    setLoading(true);
    const result = await postJson('/api/v1/auth/resend-verification', { email: address }, fetchImpl);
    setLoading(false);

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
      <Button type="submit" variant={email === undefined ? 'primary' : 'secondary'} block loading={loading} disabled={cooldown > 0}>
        {label}
      </Button>
    </form>
  );
}
