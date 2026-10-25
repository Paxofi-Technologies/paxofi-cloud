import { useEffect, useState } from 'react';
import { Alert, AppShell, Button, CartButton, type NavItem } from './components';
import { CloudIcon, GlobeIcon, HomeIcon, LifebuoyIcon, ReceiptIcon, ServerIcon, ShieldIcon } from './components/icons';
import { BillingScreen } from './screens/BillingScreen';
import { CheckoutScreen } from './screens/CheckoutScreen';
import { DashboardScreen } from './screens/DashboardScreen';
import { DomainSearchScreen } from './screens/DomainSearchScreen';
import { RegisterScreen } from './screens/RegisterScreen';
import { SecurityScreen } from './screens/SecurityScreen';
import { SignInScreen } from './screens/SignInScreen';
import { VerifyEmailScreen } from './screens/VerifyEmailScreen';
import { fetchMfaStatus } from './lib/mfa';
import { fetchSession, signOut, type SessionState } from './lib/session';

const NAV: readonly NavItem[] = [
  { id: 'dashboard', label: 'Dashboard', icon: <HomeIcon /> },
  { id: 'domains', label: 'Domains', icon: <GlobeIcon /> },
  { id: 'hosting', label: 'Hosting', icon: <ServerIcon />, comingIn: 'Soon' },
  { id: 'vps', label: 'Cloud servers', icon: <CloudIcon />, comingIn: 'Soon' },
  { id: 'billing', label: 'Billing', icon: <ReceiptIcon /> },
  { id: 'support', label: 'Support', icon: <LifebuoyIcon />, comingIn: 'Soon' },
  { id: 'security', label: 'Security', icon: <ShieldIcon /> },
];

type Screen = 'dashboard' | 'domains' | 'checkout' | 'billing' | 'security';

/** Each portal page has its own address, so refresh, links in emails and back/forward land on it. */
const SCREEN_PATHS: Readonly<Record<Screen, string>> = { dashboard: '/', domains: '/domains', checkout: '/checkout', billing: '/billing', security: '/security' };

export function screenForPath(path: string): Screen | null {
  const match = (Object.keys(SCREEN_PATHS) as Screen[]).find((screen) => SCREEN_PATHS[screen] === path);
  return match ?? null;
}

/**
 * Where to go after signing in. Only exact portal paths are accepted, so a
 * crafted `?next=` can never send anyone off-site (open redirect).
 */
export function returnPath(search: string): string {
  const screen = screenForPath(new URLSearchParams(search).get('next') ?? '');
  // Return our own constant, never the query value itself.
  return screen !== null ? SCREEN_PATHS[screen] : '/';
}

export interface AppProps {
  path?: string;
  search?: string;
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
export function App({ path = window.location.pathname, search = window.location.search, fetchImpl, navigate = assign }: AppProps) {
  if (path === '/register') return <RegisterScreen fetchImpl={fetchImpl} />;
  if (path === '/verify-email') return <VerifyEmailScreen fetchImpl={fetchImpl} />;
  if (path === '/sign-in') return <SignInScreen fetchImpl={fetchImpl} onSignedIn={() => navigate(returnPath(search))} />;

  return <Portal path={path} fetchImpl={fetchImpl} navigate={navigate} />;
}

function Portal({ path, fetchImpl, navigate }: { path: string; fetchImpl?: typeof fetch | undefined; navigate: (path: string) => void }) {
  const [state, setState] = useState<SessionState | 'loading'>('loading');
  const [screen, setScreen] = useState<Screen>(() => screenForPath(path) ?? 'dashboard');
  const [attempt, setAttempt] = useState(0);
  const [signingOut, setSigningOut] = useState(false);
  const [mfaEnabled, setMfaEnabled] = useState<boolean | null>(null);
  // Bumped whenever a page changes the cart, so the top-bar count and other pages refresh.
  const [cartVersion, setCartVersion] = useState(0);

  // Unknown addresses show the dashboard under its own address; back/forward follow the address bar.
  useEffect(() => {
    if (screenForPath(window.location.pathname) === null && window.location.pathname === path) window.history.replaceState(null, '', '/');
    const onPop = () => setScreen(screenForPath(window.location.pathname) ?? 'dashboard');
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [path]);

  useEffect(() => {
    let active = true;
    void fetchSession(fetchImpl).then((next) => {
      if (!active) return;
      if (next.kind === 'signed_out') {
        const wanted = screenForPath(path);
        navigate(wanted !== null && wanted !== 'dashboard' ? `/sign-in?next=${encodeURIComponent(SCREEN_PATHS[wanted])}` : '/sign-in');
      }
      setState(next);
      if (next.kind === 'signed_in') {
        void fetchMfaStatus(fetchImpl).then((status) => {
          if (active && status) setMfaEnabled(status.totpEnabled);
        });
      }
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, navigate, attempt, path]);

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

  function go(next: Screen) {
    setScreen(next);
    if (window.location.pathname !== SCREEN_PATHS[next]) window.history.pushState(null, '', SCREEN_PATHS[next]);
  }

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
      current={screen === 'checkout' ? 'domains' : screen}
      onNavigate={(id) => go(id === 'domains' || id === 'billing' || id === 'security' ? id : 'dashboard')}
      userName={session.fullName}
      topbarActions={
        <CartButton
          csrfToken={session.csrfToken}
          fetchImpl={fetchImpl}
          version={cartVersion}
          onChange={() => setCartVersion((v) => v + 1)}
          onCheckout={() => go('checkout')}
          onFindDomains={() => go('domains')}
        />
      }
      onSignOut={() => {
        if (!signingOut) void handleSignOut();
      }}
    >
      {screen === 'dashboard' && (
        <DashboardScreen fullName={session.fullName} mfaEnabled={mfaEnabled ?? true} onFindDomain={() => go('domains')} onSetUpMfa={() => go('security')} />
      )}
      {screen === 'domains' && (
        <DomainSearchScreen
          csrfToken={session.csrfToken}
          fetchImpl={fetchImpl}
          onCheckout={() => go('checkout')}
          cartVersion={cartVersion}
          onCartChange={() => setCartVersion((v) => v + 1)}
        />
      )}
      {screen === 'checkout' && (
        <CheckoutScreen
          csrfToken={session.csrfToken}
          fetchImpl={fetchImpl}
          onBack={() => go('domains')}
          onBilling={() => go('billing')}
          onCartChange={() => setCartVersion((v) => v + 1)}
        />
      )}
      {screen === 'billing' && <BillingScreen csrfToken={session.csrfToken} fetchImpl={fetchImpl} />}
      {screen === 'security' && <SecurityScreen csrfToken={session.csrfToken} fetchImpl={fetchImpl} onStatusChange={(status) => setMfaEnabled(status.totpEnabled)} />}
    </AppShell>
  );
}
