import { useEffect, useState } from 'react';
import { Alert, AppShell, Button, type NavItem } from './components';
import { CloudIcon, GlobeIcon, HomeIcon, LifebuoyIcon, ReceiptIcon, ServerIcon, ShieldIcon } from './components/icons';
import { DashboardScreen } from './screens/DashboardScreen';
import { DomainSearchScreen } from './screens/DomainSearchScreen';
import { RegisterScreen } from './screens/RegisterScreen';
import { SignInScreen } from './screens/SignInScreen';
import { VerifyEmailScreen } from './screens/VerifyEmailScreen';
import { fetchSession, signOut, type SessionState } from './lib/session';

const NAV: readonly NavItem[] = [
  { id: 'dashboard', label: 'Dashboard', icon: <HomeIcon /> },
  { id: 'domains', label: 'Domains', icon: <GlobeIcon /> },
  { id: 'hosting', label: 'Hosting', icon: <ServerIcon />, comingIn: 'Soon' },
  { id: 'vps', label: 'Cloud servers', icon: <CloudIcon />, comingIn: 'Soon' },
  { id: 'billing', label: 'Billing', icon: <ReceiptIcon />, comingIn: 'Soon' },
  { id: 'support', label: 'Support', icon: <LifebuoyIcon />, comingIn: 'Soon' },
  { id: 'security', label: 'Security', icon: <ShieldIcon />, comingIn: 'Soon' },
];

type Screen = 'dashboard' | 'domains';

export interface AppProps {
  path?: string;
  fetchImpl?: typeof fetch | undefined;
  /** Full-page navigation; tests replace it. */
  navigate?: (path: string) => void;
}

const assign = (to: string): void => window.location.assign(to);

/**
 * Pages are chosen by path. Everything except sign-up, verification and
 * sign-in needs a session: the portal asks the API who is signed in and sends
 * anyone else to /sign-in (threat model §6.11).
 */
export function App({ path = window.location.pathname, fetchImpl, navigate = assign }: AppProps) {
  if (path === '/register') return <RegisterScreen fetchImpl={fetchImpl} />;
  if (path === '/verify-email') return <VerifyEmailScreen fetchImpl={fetchImpl} />;
  if (path === '/sign-in') return <SignInScreen fetchImpl={fetchImpl} onSignedIn={() => navigate('/')} />;

  return <Portal fetchImpl={fetchImpl} navigate={navigate} />;
}

function Portal({ fetchImpl, navigate }: { fetchImpl?: typeof fetch | undefined; navigate: (path: string) => void }) {
  const [state, setState] = useState<SessionState | 'loading'>('loading');
  const [screen, setScreen] = useState<Screen>('dashboard');
  const [attempt, setAttempt] = useState(0);
  const [signingOut, setSigningOut] = useState(false);

  useEffect(() => {
    let active = true;
    void fetchSession(fetchImpl).then((next) => {
      if (!active) return;
      if (next.kind === 'signed_out') navigate('/sign-in');
      setState(next);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, navigate, attempt]);

  if (state === 'loading' || state.kind === 'signed_out') {
    return (
      <p className="pc-loading" role="status">
        Loading…
      </p>
    );
  }
  if (state.kind === 'unavailable') {
    return (
      <div className="pc-auth">
        <div className="pc-auth__panel">
          <Alert tone="danger">We could not reach PaxofiCloud. Check your connection and try again.</Alert>
          <Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>
        </div>
      </div>
    );
  }

  const { session } = state;

  async function handleSignOut() {
    setSigningOut(true);
    const result = await signOut(session.csrfToken, fetchImpl);
    setSigningOut(false);
    // A 401/403 means the session is already gone or unusable here: leave either way.
    if (result.kind !== 'unavailable') navigate('/sign-in');
  }

  return (
    <AppShell
      nav={NAV}
      current={screen}
      onNavigate={(id) => setScreen(id === 'domains' ? 'domains' : 'dashboard')}
      userName={session.fullName}
      onSignOut={() => {
        if (!signingOut) void handleSignOut();
      }}
    >
      {screen === 'dashboard' ? <DashboardScreen fullName={session.fullName} onFindDomain={() => setScreen('domains')} /> : <DomainSearchScreen />}
    </AppShell>
  );
}
