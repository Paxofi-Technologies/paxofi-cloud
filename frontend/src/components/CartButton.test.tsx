import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { CartButton } from './CartButton';

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const LINE = { id: '0123456789abcdef', domain: 'paxofi-test.ng', tld: 'ng', years: 1, available: true, register: { usd: 1157, ngn: 1_735_000 } };

describe('CartButton (CD-20)', () => {
  it('shows the count, lists the cart, removes a line and starts checkout only from its button', async () => {
    const user = userEvent.setup();
    let lines = [LINE];
    const fetchImpl = vi.fn((path: string, init?: RequestInit) => {
      if (path === '/api/v1/cart/remove') {
        expect(new Headers(init?.headers).get('X-CSRF-Token')).toBe('csrf-1');
        lines = [];
      }
      return Promise.resolve(json(200, { lines, total: { usd: lines.length * 1157, ngn: lines.length * 1_735_000 } }));
    }) as unknown as typeof fetch;
    const onCheckout = vi.fn();
    const onChange = vi.fn();
    render(<CartButton csrfToken="csrf-1" fetchImpl={fetchImpl} onCheckout={onCheckout} onFindDomains={vi.fn()} onChange={onChange} />);

    const button = await screen.findByRole('button', { name: 'Cart, 1 domain' });
    await user.click(button);
    expect(screen.getByRole('dialog', { name: 'Your cart' })).toBeInTheDocument();
    expect(screen.getByText('paxofi-test.ng')).toBeInTheDocument();
    expect(screen.getAllByText('₦17,350.00')).toHaveLength(2);

    await user.click(screen.getByRole('button', { name: 'Check out' }));
    expect(onCheckout).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect((fetchImpl as unknown as ReturnType<typeof vi.fn>).mock.calls.some(([p]) => String(p).includes('/checkout/'))).toBe(false);

    await user.click(button);
    await user.click(await screen.findByRole('button', { name: 'Remove paxofi-test.ng' }));
    expect(await screen.findByText('Your cart is empty.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check out' })).toBeDisabled();
    expect(onChange).toHaveBeenCalled();
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    const fetchImpl = vi.fn(() => Promise.resolve(json(200, { lines: [], total: { usd: 0, ngn: 0 } }))) as unknown as typeof fetch;
    render(<CartButton csrfToken="csrf-1" fetchImpl={fetchImpl} onCheckout={vi.fn()} onFindDomains={vi.fn()} />);
    await user.click(await screen.findByRole('button', { name: 'Cart, empty' }));
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
