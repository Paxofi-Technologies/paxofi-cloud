import { useEffect, useRef, useState } from 'react';
import { Alert, AuthLayout, ResendVerification } from '../components';
import { describeWait, postJson } from '../lib/api';

export interface VerifyEmailScreenProps {
  /** Injected in tests; the browser's location and fetch otherwise. */
  initialHash?: string;
  fetchImpl?: typeof fetch;
}

type State = { kind: 'verifying' } | { kind: 'verified' } | { kind: 'invalid' } | { kind: 'throttled'; wait: string } | { kind: 'error' };

const TOKEN = /^[A-Za-z0-9_-]{43}$/;

/** Parses `#token=…`; anything that is not a well-formed token is treated as no token. */
export function tokenFromHash(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  return token !== null && TOKEN.test(token) ? token : null;
}

/**
 * Email verification (SRS IAM-002). The token arrives in the URL fragment, so
 * it never reaches a server log or a Referer header; this page POSTs it once
 * (threat model T-03, T-25). Link scanners that only fetch the page cannot
 * consume it.
 */
export function VerifyEmailScreen({ initialHash, fetchImpl }: VerifyEmailScreenProps) {
  // Read once, without side effects (StrictMode may run initialisers twice).
  const [token] = useState(() => tokenFromHash(initialHash ?? window.location.hash));
  const [state, setState] = useState<State>(token === null ? { kind: 'invalid' } : { kind: 'verifying' });
  const started = useRef(false);

  useEffect(() => {
    // The token must not linger in the address bar or history.
    if (window.location.hash !== '') {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    // StrictMode also runs effects twice; a single-use token is sent once.
    if (token === null || started.current) return;
    started.current = true;
    void postJson('/api/v1/auth/verify-email', { token }, fetchImpl).then((result) => {
      if (result.kind === 'ok') setState({ kind: 'verified' });
      else if (result.kind === 'throttled') setState({ kind: 'throttled', wait: describeWait(result.retryAfterSeconds) });
      else if (result.kind === 'rejected') setState({ kind: 'invalid' });
      else setState({ kind: 'error' });
    });
  }, [token, fetchImpl]);

  return (
    <AuthLayout>
      <div className="pc-auth__form" aria-live="polite">
        {state.kind === 'verifying' && (
          <div>
            <h1>Confirming your email…</h1>
            <p className="pc-muted">This only takes a moment.</p>
          </div>
        )}
        {state.kind === 'verified' && (
          <>
            <div>
              <h1>Email confirmed</h1>
              <p className="pc-muted">Your PaxofiCloud account is ready.</p>
            </div>
            <Alert tone="success">You can now sign in with your email address and password.</Alert>
            <p className="pc-auth__footer">
              <a href="/">Continue to sign in</a>
            </p>
          </>
        )}
        {state.kind === 'invalid' && (
          <>
            <div>
              <h1>This link can’t be used</h1>
              <p className="pc-muted">This link has already been used or has expired. Links work once and last 24 hours.</p>
            </div>
            <Alert tone="info">If you have already confirmed your email, just sign in. Otherwise, enter your email to get a new link.</Alert>
            <ResendVerification fetchImpl={fetchImpl} />
            <p className="pc-auth__footer">
              <a href="/">Sign in</a> · <a href="/register">Register again</a>
            </p>
          </>
        )}
        {state.kind === 'throttled' && (
          <Alert tone="warning" title="Too many attempts">
            Please try the link again in {state.wait}.
          </Alert>
        )}
        {state.kind === 'error' && (
          <Alert tone="danger" title="Something went wrong">
            We could not confirm your email right now. Please open the link again in a few minutes.
          </Alert>
        )}
      </div>
    </AuthLayout>
  );
}
