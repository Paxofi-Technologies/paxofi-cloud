import { useState, type FormEvent, type ReactNode } from 'react';
import { Alert, Button, Logo, ResendVerification, TextField, Turnstile } from '../components';
import { describeWait } from '../lib/api';
import { challengeProblem, useChallengeSiteKey } from '../lib/challenge';
import { completeSignIn } from '../lib/mfa';
import { signIn } from '../lib/session';

export interface SignInScreenProps {
  /** Called after the server has set the session cookie. */
  onSignedIn: () => void;
  fetchImpl?: typeof fetch | undefined;
}

type Problem = { tone: 'danger' | 'warning'; text: string; extra?: ReactNode } | null;

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Customer sign-in (SRS IAM-005, IAM-006). The server gives one answer for a
 * wrong email or a wrong password (threat model T-09), and so does this
 * screen. "Confirm your email first" and "account unavailable" only come back
 * after the correct password, so showing them reveals nothing new.
 */
export function SignInScreen({ onSignedIn, fetchImpl }: SignInScreenProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<{ email?: string | undefined; password?: string | undefined }>({});
  const [problem, setProblem] = useState<Problem>(null);
  const [loading, setLoading] = useState(false);
  const siteKey = useChallengeSiteKey(fetchImpl);
  // The server asks for the bot check only after repeated failures (§6.12).
  const [challenged, setChallenged] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [resetSignal, setResetSignal] = useState(0);
  const [mfaStep, setMfaStep] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const address = email.trim();
    const next: typeof errors = {};
    if (!EMAIL.test(address)) next.email = 'Enter the email address you registered with.';
    if (password.length === 0) next.password = 'Enter your password.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;
    if (challenged && typeof siteKey === 'string' && token === null) {
      setProblem({ tone: 'warning', text: 'Please complete the security check, then sign in.' });
      return;
    }

    setLoading(true);
    const result = await signIn(address, password, fetchImpl, token);
    setLoading(false);
    if (token !== null) setResetSignal((n) => n + 1);

    switch (result.kind) {
      case 'ok':
        setPassword('');
        if (typeof result.data === 'object' && result.data !== null && (result.data as Record<string, unknown>).status === 'mfa_required') {
          setMfaStep(true);
          return;
        }
        onSignedIn();
        return;
      case 'throttled':
        setProblem({ tone: 'warning', text: `Too many sign-in attempts. Please try again in ${describeWait(result.retryAfterSeconds)}.` });
        return;
      case 'invalid':
        setErrors({
          email: result.errors.email ? 'Enter the email address you registered with.' : undefined,
          password: result.errors.password ? 'Enter your password.' : undefined,
        });
        return;
      case 'unavailable':
        setProblem({ tone: 'danger', text: 'We could not reach PaxofiCloud. Check your connection and try again.' });
        return;
      case 'rejected':
        setPassword('');
        if (result.code === 'challenge_required' || result.code === 'challenge_unavailable') {
          setChallenged(true);
          setProblem({ tone: 'warning', text: challengeProblem(result.code) ?? '' });
        } else if (result.code === 'email_not_verified') {
          setProblem({
            tone: 'warning',
            text: 'Confirm your email address before signing in. We can send you a new link.',
            extra: <ResendVerification email={address} fetchImpl={fetchImpl} />,
          });
        } else if (result.code === 'account_unavailable') {
          setProblem({ tone: 'danger', text: 'This account is not available. Please contact PaxofiCloud support.' });
        } else {
          setProblem({ tone: 'danger', text: 'The email address or password is incorrect.' });
        }
    }
  }

  if (mfaStep) {
    return (
      <AuthShell>
        <MfaStep fetchImpl={fetchImpl} onSignedIn={onSignedIn} onRestart={() => setMfaStep(false)} />
      </AuthShell>
    );
  }

  return (
    <AuthShell>
      <form className="pc-auth__form" onSubmit={submit} noValidate>
        <div>
          <h1>Sign in</h1>
          <p className="pc-muted">Manage your domains, hosting and servers.</p>
        </div>
        {problem && (
          <Alert tone={problem.tone}>
            {problem.text}
          </Alert>
        )}
        <TextField
          label="Email address"
          type="email"
          autoComplete="username"
          inputMode="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={errors.email}
          required
        />
        <TextField
          label="Password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={errors.password}
          required
        />
        {challenged && typeof siteKey === 'string' && (
          <Turnstile siteKey={siteKey} action="sign_in" onToken={setToken} resetSignal={resetSignal} />
        )}
        <Button type="submit" block loading={loading}>
          Sign in
        </Button>
        <p className="pc-auth__footer">
          New to PaxofiCloud? <a href="/register">Create an account</a>
        </p>
      </form>
      {problem?.extra}
    </AuthShell>
  );
}

function AuthShell({ children }: { children: ReactNode }) {
  return (
    <div className="pc-auth">
      <div className="pc-auth__panel">
        <Logo />
        {children}
      </div>
      <div className="pc-auth__aside" aria-hidden="true">
        <div className="pc-auth__aside-inner">
          <p className="pc-auth__eyebrow">PaxofiCloud</p>
          <p className="pc-auth__headline">Domains, hosting and cloud servers — built for Nigerian businesses.</p>
          <ul className="pc-auth__points">
            <li>.ng and .com.ng domains in minutes</li>
            <li>Pay in naira or dollars</li>
            <li>Your account protected by strong security</li>
          </ul>
        </div>
      </div>
    </div>
  );
}

/**
 * The second sign-in step (SRS IAM-007; threat model §6.13): a code from the
 * authenticator app, or one recovery code. The 5-minute ticket is an HttpOnly
 * cookie the page never sees; when it ends the customer starts again.
 */
function MfaStep({ fetchImpl, onSignedIn, onRestart }: { fetchImpl?: typeof fetch | undefined; onSignedIn: () => void; onRestart: () => void }) {
  const [useRecovery, setUseRecovery] = useState(false);
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [expired, setExpired] = useState(false);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const entered = useRecovery ? value.trim() : value.replace(/\s/g, '');
    if (useRecovery ? !/^[A-Za-z2-7\s-]{16,24}$/.test(entered) : !/^\d{6}$/.test(entered)) {
      setError(useRecovery ? 'Enter one of your recovery codes, like ABCD-EFGH-IJKL-MNOP.' : 'Enter the 6-digit code from your authenticator app.');
      return;
    }
    setError(undefined);
    setProblem(null);
    setLoading(true);
    const result = await completeSignIn({ kind: useRecovery ? 'recovery' : 'code', value: entered }, fetchImpl);
    setLoading(false);
    setValue('');

    switch (result.kind) {
      case 'ok':
        onSignedIn();
        return;
      case 'invalid':
        setError(useRecovery ? 'That recovery code is not valid or has already been used.' : 'That code is not right. Check the time on your phone and try the newest code.');
        return;
      case 'throttled':
        setExpired(true);
        setProblem(`Too many wrong codes. For your safety, two-step sign-in is paused for ${describeWait(result.retryAfterSeconds)}.`);
        return;
      case 'rejected':
        setExpired(true);
        setProblem(result.code === 'account_unavailable' ? 'This account is not available. Please contact PaxofiCloud support.' : 'This sign-in has expired. Please sign in again.');
        return;
      case 'unavailable':
        setProblem('We could not reach PaxofiCloud. Check your connection and try again.');
    }
  }

  return (
    <form className="pc-auth__form" onSubmit={submit} noValidate>
      <div>
        <h1>Two-step verification</h1>
        <p className="pc-muted">
          {useRecovery ? 'Enter one of the recovery codes you saved when you turned on two-step verification.' : 'Open your authenticator app and enter the 6-digit code for PaxofiCloud.'}
        </p>
      </div>
      {problem && <Alert tone="warning">{problem}</Alert>}
      {expired ? (
        <Button type="button" block onClick={onRestart}>
          Sign in again
        </Button>
      ) : (
        <>
          <TextField
            label={useRecovery ? 'Recovery code' : 'Verification code'}
            inputMode={useRecovery ? 'text' : 'numeric'}
            autoComplete="one-time-code"
            maxLength={useRecovery ? 24 : 6}
            value={value}
            onChange={(e) => setValue(useRecovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
            error={error}
            required
          />
          <Button type="submit" block loading={loading}>
            Verify and sign in
          </Button>
          <Button
            type="button"
            variant="ghost"
            block
            onClick={() => {
              setUseRecovery((v) => !v);
              setValue('');
              setError(undefined);
            }}
          >
            {useRecovery ? 'Use a code from my app instead' : 'Use a recovery code instead'}
          </Button>
        </>
      )}
    </form>
  );
}
