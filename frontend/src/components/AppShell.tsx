import type { ReactNode } from 'react';
import { Logo } from './Logo';

export interface NavItem {
  id: string;
  label: string;
  icon: ReactNode;
  /** Shown instead of navigating when the area is not built yet. */
  comingIn?: string;
}

export interface AppShellProps {
  nav: readonly NavItem[];
  current: string;
  onNavigate: (id: string) => void;
  userName: string;
  onSignOut: () => void;
  children: ReactNode;
}

export function AppShell({ nav, current, onNavigate, userName, onSignOut, children }: AppShellProps) {
  return (
    <div className="pc-shell">
      <a className="pc-skip-link" href="#main">
        Skip to content
      </a>
      <aside className="pc-shell__sidebar">
        <div className="pc-shell__brand">
          <Logo inverse />
        </div>
        <nav aria-label="Main">
          <ul className="pc-nav">
            {nav.map((item) => {
              const active = item.id === current;
              const disabled = item.comingIn !== undefined;
              return (
                <li key={item.id}>
                  <button
                    type="button"
                    className={`pc-nav__item${active ? ' pc-nav__item--active' : ''}`}
                    aria-current={active ? 'page' : undefined}
                    aria-disabled={disabled || undefined}
                    onClick={() => {
                      if (!disabled) onNavigate(item.id);
                    }}
                  >
                    <span className="pc-nav__icon" aria-hidden="true">
                      {item.icon}
                    </span>
                    <span>{item.label}</span>
                    {disabled && <span className="pc-nav__soon">{item.comingIn}</span>}
                  </button>
                </li>
              );
            })}
          </ul>
        </nav>
      </aside>
      <div className="pc-shell__main">
        <header className="pc-topbar">
          <span className="pc-topbar__env">Prototype · sample data</span>
          <div className="pc-topbar__user">
            <span className="pc-avatar" aria-hidden="true">
              {userName.slice(0, 1).toUpperCase()}
            </span>
            <span className="pc-topbar__name">{userName}</span>
            <button type="button" className="pc-link-button" onClick={onSignOut}>
              Sign out
            </button>
          </div>
        </header>
        <main id="main" className="pc-content" tabIndex={-1}>
          {children}
        </main>
      </div>
    </div>
  );
}
