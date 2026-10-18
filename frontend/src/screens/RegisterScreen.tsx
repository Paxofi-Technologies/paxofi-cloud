import { useState, type FormEvent } from 'react';
import { Alert, AuthLayout, Button, Checkbox, RESEND_COOLDOWN_SECONDS, ResendVerification, TextField, Turnstile } from '../components';
import { describeWait, postJson } from '../lib/api';
import { challengeProblem, useChallengeSiteKey } from '../lib/challenge';
import { fromServerErrors, PASSWORD_MIN, toRequest, validate, type FieldErrors, type RegistrationForm } from '../lib/registration';

export interface RegisterScreenProps {
  /** Injected in tests; the browser's fetch otherwise. */
  fetchImpl?: typeof fetch | undefined;
}

const EMPTY: RegistrationForm = { fullName: '', email: '', phone: '', password: '', acceptTerms: false };

/**
 * Customer sign-up (SRS IAM-001). Whatever the address, a successful request
 * shows the same "check your email" page: the API never reveals whether an
 * account already exists (threat model T-01, CEO decision 2026-10-17).
 */
export function RegisterScreen({ fetchImpl }: RegisterScreenProps) {
  const [form, setForm] = useState<RegistrationForm>(EMPTY);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const siteKey = useChallengeSiteKey(fetchImpl);
  const [token, setToken] = useState<string | null>(null);
  const [resetSignal, setResetSignal] = useState(0);

  function update<K extends keyof RegistrationForm>(key: K, value: RegistrationForm[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setProblem(null);
    const local = validate(form);
    setErrors(local);
    if (Object.keys(local).length > 0) return;
    if (typeof siteKey === 'string' && token === null) {
      setProblem('Please complete the security check.');
      return;
    }

    setLoading(true);
    const body = token === null ? toRequest(form) : { ...toRequest(form), turnstile_token: token };
    const result = await postJson('/api/v1/auth/register', body, fetchImpl);
    setLoading(false);
    // Each token can be verified once; get a fresh one for any next attempt.
    setResetSignal((n) => n + 1);

    switch (result.kind) {
      case 'ok':
        setSentTo(form.email.trim());
        setForm(EMPTY);
        return;
      case 'invalid': {
        const mapped = fromServerErrors(result.errors);
        setErrors(mapped.fields);
        if (mapped.other) setProblem('Something in the form could not be accepted. Please check it and try again.');
        return;
      }
      case 'throttled':
        setProblem(`Too many sign-up attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.`);
        return;
      case 'rejected':
        setProblem(challengeProblem(result.code) ?? 'We could not create your account right now. Please try again in a few minutes.');
        return;
      case 'unavailable':
        setProblem('We could not create your account right now. Please try again in a few minutes.');
    }
  }

  if (sentTo !== null) {
    return (
      <AuthLayout>
        <div className="pc-auth__form">
          <div>
            <h1>Check your email</h1>
            <p className="pc-muted">
              We have sent a link to <strong>{sentTo}</strong>. Open it within 24 hours to confirm your address and finish setting up your
              account.
            </p>
          </div>
          <Alert tone="info">
            No email after a few minutes? Check your spam folder. If you already have an account with this address, the email explains how to sign
            in instead.
          </Alert>
          <ResendVerification email={sentTo} initialCooldown={RESEND_COOLDOWN_SECONDS} fetchImpl={fetchImpl} />
          <p className="pc-auth__footer">
            <a href="/sign-in">Back to sign in</a>
          </p>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <form className="pc-auth__form" onSubmit={submit} noValidate>
        <div>
          <h1>Create your account</h1>
          <p className="pc-muted">Domains, hosting and cloud servers in one place.</p>
        </div>
        {problem && <Alert tone="danger">{problem}</Alert>}
        <TextField
          label="Full name"
          autoComplete="name"
          value={form.fullName}
          onChange={(e) => update('fullName', e.target.value)}
          error={errors.fullName}
          maxLength={100}
          required
        />
        <TextField
          label="Email address"
          type="email"
          autoComplete="email"
          inputMode="email"
          value={form.email}
          onChange={(e) => update('email', e.target.value)}
          error={errors.email}
          maxLength={254}
          required
        />
        <TextField
          label="Phone number"
          type="tel"
          autoComplete="tel"
          inputMode="tel"
          value={form.phone}
          onChange={(e) => update('phone', e.target.value)}
          error={errors.phone}
          hint="Nigerian numbers can start with 0; other countries need the + code."
          maxLength={24}
          required
        />
        <TextField
          label="Password"
          type={showPassword ? 'text' : 'password'}
          autoComplete="new-password"
          value={form.password}
          onChange={(e) => update('password', e.target.value)}
          error={errors.password}
          hint={`At least ${PASSWORD_MIN} characters. Spaces are allowed, so a short sentence works well.`}
          maxLength={512}
          required
          trailing={
            <Button variant="ghost" size="sm" onClick={() => setShowPassword((v) => !v)} aria-pressed={showPassword}>
              {showPassword ? 'Hide' : 'Show'}
            </Button>
          }
        />
        <Checkbox
          checked={form.acceptTerms}
          onChange={(e) => update('acceptTerms', e.target.checked)}
          error={errors.acceptTerms}
          label="I agree to the PaxofiCloud Terms of Service and Privacy Policy."
        />
        {typeof siteKey === 'string' && <Turnstile siteKey={siteKey} action="register" onToken={setToken} resetSignal={resetSignal} />}
        <Button type="submit" block loading={loading}>
          Create account
        </Button>
        <p className="pc-auth__footer">
          Already have an account? <a href="/sign-in">Sign in</a>
        </p>
      </form>
    </AuthLayout>
  );
}
