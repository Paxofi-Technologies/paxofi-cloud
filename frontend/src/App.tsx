import { useState } from 'react';
import { AppShell, type NavItem } from './components';
import { CloudIcon, GlobeIcon, HomeIcon, LifebuoyIcon, ReceiptIcon, ServerIcon, ShieldIcon } from './components/icons';
import { DashboardScreen } from './screens/DashboardScreen';
import { DomainSearchScreen } from './screens/DomainSearchScreen';
import { SignInScreen } from './screens/SignInScreen';
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

export function App() {
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
