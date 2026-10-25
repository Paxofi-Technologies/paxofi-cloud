import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { AppShell } from './AppShell';

const nav = [
  { id: 'home', label: 'Overview', icon: <span /> },
  { id: 'costs', label: 'Domain costs', icon: <span /> },
  { id: 'later', label: 'Billing', icon: <span />, comingIn: 'Soon' },
];

function shell(onNavigate = vi.fn()) {
  render(
    <AppShell nav={nav} current="home" onNavigate={onNavigate} userName="Ada" onSignOut={vi.fn()} envLabel="Staff console">
      <p>Page</p>
    </AppShell>,
  );
  return onNavigate;
}

const isOpen = () => document.querySelector('.pc-shell')?.classList.contains('pc-shell--menu-open') ?? false;

describe('AppShell sidebar drawer (phones)', () => {
  it('opens from the menu button and closes when a page is chosen', async () => {
    const user = userEvent.setup();
    const onNavigate = shell();
    const menu = screen.getByRole('button', { name: 'Open menu' });
    expect(menu).toHaveAttribute('aria-controls', 'pc-sidebar');
    expect(menu).toHaveAttribute('aria-expanded', 'false');

    await user.click(menu);
    expect(isOpen()).toBe(true);
    expect(menu).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: 'Close menu' })).toHaveFocus();

    await user.click(screen.getByRole('button', { name: 'Domain costs' }));
    expect(onNavigate).toHaveBeenCalledWith('costs');
    expect(isOpen()).toBe(false);
  });

  it('closes on Escape, the close button and the backdrop, and returns focus to the menu button', async () => {
    const user = userEvent.setup();
    shell();
    const menu = screen.getByRole('button', { name: 'Open menu' });

    await user.click(menu);
    await user.keyboard('{Escape}');
    expect(isOpen()).toBe(false);
    expect(menu).toHaveFocus();

    await user.click(menu);
    await user.click(screen.getByRole('button', { name: 'Close menu' }));
    expect(isOpen()).toBe(false);
    expect(menu).toHaveFocus();

    await user.click(menu);
    const backdrop = document.querySelector('.pc-shell__backdrop');
    expect(backdrop).not.toBeNull();
    await user.click(backdrop as Element);
    expect(isOpen()).toBe(false);
    expect(document.querySelector('.pc-shell__backdrop')).toBeNull();
  });

  it('keeps an area that is not built yet closed and unselectable', async () => {
    const user = userEvent.setup();
    const onNavigate = shell();
    await user.click(screen.getByRole('button', { name: 'Open menu' }));
    await user.click(screen.getByRole('button', { name: /Billing/ }));
    expect(onNavigate).not.toHaveBeenCalled();
    expect(isOpen()).toBe(true);
    expect(screen.getByText('Staff console')).toBeInTheDocument();
  });
});

describe('AppShell menu groups (dropdowns)', () => {
  const grouped = [
    { id: 'home', label: 'Overview', icon: <span /> },
    {
      id: 'api',
      label: 'API providers',
      icon: <span />,
      children: [
        { id: 'registrars', label: 'Domain registrars', icon: <span /> },
        { id: 'servers', label: 'Server providers', icon: <span /> },
      ],
    },
  ];

  function render2(current: string, onNavigate = vi.fn()) {
    const view = render(
      <AppShell nav={grouped} current={current} onNavigate={onNavigate} userName="Ada" onSignOut={vi.fn()}>
        <p>Page</p>
      </AppShell>,
    );
    return { onNavigate, view };
  }

  it('starts closed when no page inside it is open, and opens and closes on click', async () => {
    const user = userEvent.setup();
    const { onNavigate } = render2('home');
    const toggle = screen.getByRole('button', { name: 'API providers' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('button', { name: 'Domain registrars' })).toBeNull();

    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('list', { name: 'API providers' })).toBeVisible();
    expect(onNavigate).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Server providers' }));
    expect(onNavigate).toHaveBeenCalledWith('servers');

    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('button', { name: 'Server providers' })).toBeNull();
  });

  it('starts open on one of its pages, closes when navigation leaves it, and opens by itself when navigation lands inside it', async () => {
    const user = userEvent.setup();
    const { view } = render2('registrars');
    const at = (current: string) =>
      view.rerender(
        <AppShell nav={grouped} current={current} onNavigate={vi.fn()} userName="Ada" onSignOut={vi.fn()}>
          <p>Page</p>
        </AppShell>,
      );
    const toggle = screen.getByRole('button', { name: 'API providers' });
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: 'Domain registrars' })).toHaveAttribute('aria-current', 'page');

    at('home');
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('button', { name: 'Domain registrars' })).toBeNull();

    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    at('servers');
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: 'Server providers' })).toHaveAttribute('aria-current', 'page');
  });

  it('marks a closed group that holds the current page', async () => {
    const user = userEvent.setup();
    render2('servers');
    const toggle = screen.getByRole('button', { name: 'API providers' });
    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(toggle).toHaveClass('pc-nav__item--within');
  });

  it('keeps one group open at a time, and a closed group cannot be reached by keyboard', async () => {
    const user = userEvent.setup();
    const twoGroups = [
      ...grouped,
      { id: 'cat', label: 'Catalogue', icon: <span />, children: [{ id: 'costs', label: 'Domain costs', icon: <span /> }] },
    ];
    render(
      <AppShell nav={twoGroups} current="home" onNavigate={vi.fn()} userName="Ada" onSignOut={vi.fn()}>
        <p>Page</p>
      </AppShell>,
    );
    const api = screen.getByRole('button', { name: 'API providers' });
    const cat = screen.getByRole('button', { name: 'Catalogue' });
    await user.click(api);
    expect(api).toHaveAttribute('aria-expanded', 'true');
    await user.click(cat);
    expect(cat).toHaveAttribute('aria-expanded', 'true');
    expect(api).toHaveAttribute('aria-expanded', 'false');
    const closedPanel = document.getElementById('pc-nav-group-api-pages')?.parentElement;
    expect(closedPanel).toHaveAttribute('inert');
    expect(closedPanel).toHaveAttribute('data-open', 'false');
    expect(document.getElementById('pc-nav-group-cat-pages')?.parentElement).not.toHaveAttribute('inert');
  });
});
