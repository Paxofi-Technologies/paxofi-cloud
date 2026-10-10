import { useState, type FormEvent } from 'react';
import { Alert, Button, Logo, TextField } from '../components';

type Step = 'credentials' | 'mfa';

export interface SignInScreenProps {
  onSignedIn: (email: string) => void;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Prototype of the sign-in flow (IAM-005/007). No request leaves the browser.
 * Mirrors the real behaviour the threat model requires: one generic error for
 * wrong email or password (D-7) and a separate TOTP step (IAM-007).
 */
export function SignInScreen({ onSignedIn }: SignInScreenProps) {
  const [step, setStep] = useState<Step>('credentials');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<{ email?: string; password?: string; code?: string }>({});
  const [loading, setLoading] = useState(false);

  function submitCredentials(event: FormEvent) {
    event.preventDefault();
    const next: typeof errors = {};
    if (!EMAIL.test(email.trim())) next.email = 'Enter the email address you registered with.';
    if (password.length === 0) next.password = 'Enter your password.';
    setErrors(next);
    if (Object.keys(next).length > 0) return;
    setLoading(true);
    window.setTimeout(() => {
      setLoading(false);
      setStep('mfa');
    }, 300);
  }

  function submitCode(event: FormEvent) {
    event.preventDefault();
    if (!/^\d{6}$/.test(code)) {
      setErrors({ code: 'Enter the 6-digit code from your authenticator app.' });
      return;
    }
    setErrors({});
    onSignedIn(email.trim());
  }

  return (
    <div className="pc-auth">
      <div className="pc-auth__panel">
        <Logo />
        {step === 'credentials' ? (
          <form className="pc-auth__form" onSubmit={submitCredentials} noValidate>
            <div>
              <h1>Sign in</h1>
              <p className="pc-muted">Manage your domains, hosting and servers.</p>
            </div>
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
            <div className="pc-auth__row">
              <a href="#forgot">Forgot password?</a>
            </div>
            <Button type="submit" block loading={loading}>
              Continue
            </Button>
            <p className="pc-auth__footer">
              New to PaxofiCloud? <a href="#register">Create an account</a>
            </p>
          </form>
        ) : (
          <form className="pc-auth__form" onSubmit={submitCode} noValidate>
            <div>
              <h1>Two-step verification</h1>
              <p className="pc-muted">Open your authenticator app and enter the 6-digit code for PaxofiCloud.</p>
            </div>
            <TextField
              label="Verification code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
              error={errors.code}
              hint="Lost your device? Use one of your recovery codes."
              required
            />
            <Button type="submit" block>
              Verify and sign in
            </Button>
            <Button
              variant="ghost"
              block
              onClick={() => {
                setStep('credentials');
                setCode('');
                setErrors({});
              }}
            >
              Back
            </Button>
          </form>
        )}
        <Alert tone="info">Prototype only: no real accounts. Any email, password and 6-digit code will work.</Alert>
      </div>
      <div className="pc-auth__aside" aria-hidden="true">
        <div className="pc-auth__aside-inner">
          <p className="pc-auth__eyebrow">PaxofiCloud</p>
          <p className="pc-auth__headline">Domains, hosting and cloud servers — built for Nigerian businesses.</p>
          <ul className="pc-auth__points">
            <li>.ng and .com.ng domains in minutes</li>
            <li>Pay in naira or dollars</li>
            <li>Every account protected with two-step verification</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
