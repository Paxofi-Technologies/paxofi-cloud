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
