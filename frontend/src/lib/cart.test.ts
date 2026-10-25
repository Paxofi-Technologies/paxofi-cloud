import { describe, expect, it, vi } from 'vitest';
import { addDomainToCart, fetchCart, readCart } from './cart';

const reply = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  vi.fn(() => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } }))) as unknown as typeof fetch;

describe('cart client (CD-20)', () => {
  it('keeps only well-formed lines and amounts', () => {
    const cart = readCart({
      lines: [
        { id: '0123456789abcdef', domain: 'a.ng', available: true, register: { usd: 990, ngn: 1_485_000 } },
        { id: 'fedcba9876543210', domain: 'b.ng', available: true, register: { usd: 1.5, ngn: 1 } },
        { id: 'bad', domain: 'c.ng', available: true, register: { usd: 1, ngn: 1 } },
        'junk',
      ],
      total: { usd: -1, ngn: 'x' },
    });
    expect(cart.lines.map((l) => [l.domain, l.available])).toEqual([
      ['a.ng', true],
      ['b.ng', false],
    ]);
    expect(cart.total).toEqual({ usd: 0, ngn: null });
  });

  it('treats a signed-out visitor as an empty cart', async () => {
    expect(await fetchCart(reply(401, { code: 'not_signed_in' }))).toEqual({ kind: 'ok', cart: { lines: [], total: { usd: 0, ngn: 0 } } });
  });

  it('explains refusals in plain words', async () => {
    expect(await addDomainToCart('a.ng', 't', reply(422, { code: 'cart_full' }))).toEqual({ kind: 'error', message: 'Your cart is full (20 items). Remove one to add another.' });
    const throttled = await addDomainToCart('a.ng', 't', reply(429, { code: 'too_many_requests' }, { 'retry-after': '600' }));
    expect(throttled.kind === 'error' && throttled.message).toMatch(/Too many changes to your cart/);
  });
});
