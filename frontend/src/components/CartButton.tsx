import { useEffect, useId, useRef, useState } from 'react';
import { fetchCart, removeFromCart, type Cart } from '../lib/cart';
import { formatMoney } from '../lib/money';
import { Button } from './Button';
import { CartIcon } from './icons';

export interface CartButtonProps {
  csrfToken: string;
  fetchImpl?: typeof fetch | undefined;
  onCheckout: () => void;
  onFindDomains: () => void;
  /** Bumped by pages that change the cart, so the count stays current. */
  version?: number;
  /** Called after the cart changes here (a removal), so pages can refresh. */
  onChange?: () => void;
}

/**
 * The cart in the top bar: a count badge and a panel with the domains in the
 * cart (CD-20). Looking costs nothing; checkout starts only from its button,
 * because each checkout asks the registry again and is limited per hour.
 */
export function CartButton({ csrfToken, fetchImpl, onCheckout, onFindDomains, version = 0, onChange }: CartButtonProps) {
  const [cart, setCart] = useState<Cart | null>(null);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const panelId = useId();
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let active = true;
    void fetchCart(fetchImpl).then((r) => {
      if (!active) return;
      if (r.kind === 'ok') setCart(r.cart);
      else setError(r.message);
    });
    return () => {
      active = false;
    };
  }, [fetchImpl, version, open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    const onClick = (e: MouseEvent) => {
      if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onClick);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('mousedown', onClick);
    };
  }, [open]);

  async function remove(id: string) {
    setError(null);
    const r = await removeFromCart(id, csrfToken, fetchImpl);
    if (r.kind === 'ok') {
      setCart(r.cart);
      onChange?.();
    } else setError(r.message);
  }

  const lines = cart?.lines ?? [];
  const count = lines.length;
  const price = (usd: number, ngn: number | null) => (ngn !== null ? formatMoney({ minor: ngn, currency: 'NGN' }) : formatMoney({ minor: usd, currency: 'USD' }));

  return (
    <div className="pc-cart" ref={root}>
      <button
        type="button"
        className="pc-cart__button"
        aria-label={count === 0 ? 'Cart, empty' : `Cart, ${count} ${count === 1 ? 'domain' : 'domains'}`}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((o) => !o)}
      >
        <CartIcon />
        {count > 0 && (
          <span className="pc-cart__count" aria-hidden="true">
            {count}
          </span>
        )}
      </button>
      {open && (
        <div id={panelId} className="pc-cart__panel" role="dialog" aria-label="Your cart">
          <p className="pc-cart__title">Your cart</p>
          {error && <p className="pc-field__error">{error}</p>}
          {count === 0 ? (
            <p className="pc-muted pc-small">Your cart is empty.</p>
          ) : (
            <ul className="pc-cart__lines">
              {lines.map((l) => (
                <li key={l.id}>
                  <span className="pc-cart__domain">{l.domain}</span>
                  <span className="pc-small">{l.register ? price(l.register.usd, l.register.ngn) : 'Not for sale'}</span>
                  <button type="button" className="pc-link-button pc-small" onClick={() => void remove(l.id)} aria-label={`Remove ${l.domain}`}>
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}
          {count > 0 && cart && (
            <p className="pc-cart__total">
              <span>Total before tax</span>
              <strong>{price(cart.total.usd, cart.total.ngn)}</strong>
            </p>
          )}
          <div className="pc-row">
            <Button
              size="sm"
              disabled={count === 0}
              onClick={() => {
                setOpen(false);
                onCheckout();
              }}
            >
              Check out
            </Button>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => {
                setOpen(false);
                onFindDomains();
              }}
            >
              Find domains
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
