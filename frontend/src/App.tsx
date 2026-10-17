import { useState } from 'react';
import { AppShell, type NavItem } from './components';
import { CloudIcon, GlobeIcon, HomeIcon, LifebuoyIcon, ReceiptIcon, ServerIcon, ShieldIcon } from './components/icons';
import { DashboardScreen } from './screens/DashboardScreen';
import { DomainSearchScreen } from './screens/DomainSearchScreen';
import { RegisterScreen } from './screens/RegisterScreen';
import { SignInScreen } from './screens/SignInScreen';
import { VerifyEmailScreen } from './screens/VerifyEmailScreen';
import { sampleCustomer } from './mocks/sample';

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

/** Real (API-backed) pages are chosen by path; everything else is still the prototype. */
export function App({ path = window.location.pathname }: { path?: string }) {
  if (path === '/register') return <RegisterScreen />;
  if (path === '/verify-email') return <VerifyEmailScreen />;

  return <Prototype />;
}

function Prototype() {
  const [signedIn, setSignedIn] = useState(false);
  const [screen, setScreen] = useState<Screen>('dashboard');

  if (!signedIn) {
    return <SignInScreen onSignedIn={() => setSignedIn(true)} />;
  }

  return (
    <AppShell
      nav={NAV}
      current={screen}
      onNavigate={(id) => setScreen(id === 'domains' ? 'domains' : 'dashboard')}
      userName={sampleCustomer.name}
      onSignOut={() => {
        setSignedIn(false);
        setScreen('dashboard');
      }}
    >
      {screen === 'dashboard' ? <DashboardScreen onFindDomain={() => setScreen('domains')} /> : <DomainSearchScreen />}
    </AppShell>
  );
}
