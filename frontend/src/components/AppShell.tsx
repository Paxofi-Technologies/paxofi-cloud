import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronDownIcon, CloseIcon, MenuIcon } from './icons';
import { Logo } from './Logo';

export interface NavItem {
  id: string;
  label: string;
  icon: ReactNode;
  /** Shown instead of navigating when the area is not built yet. */
  comingIn?: string;
  /** Pages listed under this heading, shown as a collapsible group; the heading itself is not a page. */
  children?: readonly NavItem[];
}

export interface AppShellProps {
  nav: readonly NavItem[];
  current: string;
  onNavigate: (id: string) => void;
  userName: string;
  onSignOut: () => void;
  /** Label at the top of the page; the customer portal still shows sample data in places. */
  envLabel?: string;
  children: ReactNode;
}

/**
 * Left sidebar on wide screens. On phones (860px and narrower) the sidebar is
 * a drawer opened by the menu button in the top bar; it closes on choosing a
 * page, on the close button, on tapping outside it, and on Escape.
 *
 * A menu heading with child pages is a collapsible group: clicking it opens
 * or closes its pages. It starts open when one of its pages is the current
 * page, and opens by itself when navigation lands on one of them.
 */
export function AppShell({ nav, current, onNavigate, userName, onSignOut, envLabel = 'Prototype · sample data', children }: AppShellProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [openGroups, setOpenGroups] = useState<ReadonlySet<string>>(() => new Set(groupsHolding(nav, current)));
  const [shownPage, setShownPage] = useState(current);
  // Landing on a page inside a closed group (back button, a link) opens that group.
  // Adjusted while rendering, not in an effect, so the menu never draws closed first.
  if (shownPage !== current) {
    setShownPage(current);
    const holding = groupsHolding(nav, current);
    if (!holding.every((id) => openGroups.has(id))) setOpenGroups(new Set([...openGroups, ...holding]));
  }
  const menuButton = useRef<HTMLButtonElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    closeButton.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMenuOpen(false);
        menuButton.current?.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [menuOpen]);

  function toggleGroup(id: string) {
    setOpenGroups((open) => {
      const next = new Set(open);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function choose(id: string) {
    setMenuOpen(false);
    onNavigate(id);
  }

  return (
    <div className={`pc-shell${menuOpen ? ' pc-shell--menu-open' : ''}`}>
      <a className="pc-skip-link" href="#main">
        Skip to content
      </a>
      <aside id="pc-sidebar" className="pc-shell__sidebar" aria-label="Menu">
        <div className="pc-shell__brand">
          <Logo inverse />
          <button
            ref={closeButton}
            type="button"
            className="pc-shell__close"
            aria-label="Close menu"
            onClick={() => {
              setMenuOpen(false);
              menuButton.current?.focus();
            }}
          >
            <CloseIcon />
          </button>
        </div>
        <nav aria-label="Main">
          <ul className="pc-nav">
            {nav.map((item) =>
              item.children ? (
                <li key={item.id}>
                  <button
                    type="button"
                    id={`pc-nav-group-${item.id}`}
                    className={`pc-nav__item pc-nav__group${!openGroups.has(item.id) && item.children.some((c) => c.id === current) ? ' pc-nav__item--within' : ''}`}
                    aria-expanded={openGroups.has(item.id)}
                    aria-controls={`pc-nav-group-${item.id}-pages`}
                    onClick={() => toggleGroup(item.id)}
                  >
                    <span className="pc-nav__icon" aria-hidden="true">
                      {item.icon}
                    </span>
                    <span>{item.label}</span>
                    <span className="pc-nav__chevron" aria-hidden="true">
                      <ChevronDownIcon />
                    </span>
                  </button>
                  <ul
                    id={`pc-nav-group-${item.id}-pages`}
                    className="pc-nav pc-nav--sub"
                    aria-labelledby={`pc-nav-group-${item.id}`}
                    hidden={!openGroups.has(item.id)}
                  >
                    {item.children.map((child) => (
                      <NavEntry key={child.id} item={child} current={current} onChoose={choose} />
                    ))}
                  </ul>
                </li>
              ) : (
                <NavEntry key={item.id} item={item} current={current} onChoose={choose} />
              ),
            )}
          </ul>
        </nav>
      </aside>
      {menuOpen && <div className="pc-shell__backdrop" aria-hidden="true" onClick={() => setMenuOpen(false)} />}
      <div className="pc-shell__main">
        <header className="pc-topbar">
          <div className="pc-topbar__start">
            <button
              ref={menuButton}
              type="button"
              className="pc-topbar__menu"
              aria-label="Open menu"
              aria-controls="pc-sidebar"
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen(true)}
            >
              <MenuIcon />
            </button>
            <span className="pc-topbar__env">{envLabel}</span>
          </div>
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

function NavEntry({ item, current, onChoose }: { item: NavItem; current: string; onChoose: (id: string) => void }) {
  const active = item.id === current;
  const disabled = item.comingIn !== undefined;
  return (
    <li>
      <button
        type="button"
        className={`pc-nav__item${active ? ' pc-nav__item--active' : ''}`}
        aria-current={active ? 'page' : undefined}
        aria-disabled={disabled || undefined}
        onClick={() => {
          if (!disabled) onChoose(item.id);
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
}

/** The menu groups whose pages include the current page. */
function groupsHolding(nav: readonly NavItem[], current: string): string[] {
  return nav.filter((item) => item.children?.some((child) => child.id === current)).map((item) => item.id);
}
