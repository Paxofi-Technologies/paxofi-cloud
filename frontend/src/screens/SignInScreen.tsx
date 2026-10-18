import { useState, type FormEvent, type ReactNode } from 'react';
import { Alert, Button, Logo, ResendVerification, TextField } from '../components';
import { describeWait } from '../lib/api';
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

  async function submit(event: FormEvent) {
    event.preventDefault();
    const address = email.trim();
    const next: typeof errors = {};
    if (!EMAIL.test(address)) next.email = 'Enter the email address you registered with.';
    if (password.length === 0) next.password = 'Enter your password.';
    setErrors(next);
    setProblem(null);
    if (Object.keys(next).length > 0) return;

    setLoading(true);
    const result = await signIn(address, password, fetchImpl);
    setLoading(false);

    switch (result.kind) {
      case 'ok':
        setPassword('');
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
        if (result.code === 'email_not_verified') {
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

  return (
    <div className="pc-auth">
      <div className="pc-auth__panel">
        <Logo />
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
          <Button type="submit" block loading={loading}>
            Sign in
          </Button>
          <p className="pc-auth__footer">
            New to PaxofiCloud? <a href="/register">Create an account</a>
          </p>
        </form>
        {problem?.extra}
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
