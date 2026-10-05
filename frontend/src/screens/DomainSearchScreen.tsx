import { useState, type FormEvent } from 'react';
import { Badge, Button, Card, TextField } from '../components';
import { CheckIcon, SearchIcon } from '../components/icons';
import { LAUNCH_TLDS, parseDomainQuery, type Tld } from '../lib/domain';
import { formatMoney } from '../lib/money';
import { sampleAvailability, samplePrices } from '../mocks/sample';

interface Result {
  domain: string;
  tld: Tld;
  available: boolean;
  exact: boolean;
}

export function DomainSearchScreen() {
  const [query, setQuery] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [results, setResults] = useState<readonly Result[] | null>(null);
  const [cart, setCart] = useState<ReadonlyMap<string, Tld>>(new Map());

  function search(event: FormEvent) {
    event.preventDefault();
    const parsed = parseDomainQuery(query);
    if (!parsed.ok) {
      setError(parsed.error);
      setResults(null);
      return;
    }
    setError(undefined);
    const order: Tld[] = parsed.tld ? [parsed.tld, ...LAUNCH_TLDS.filter((t) => t !== parsed.tld)] : [...LAUNCH_TLDS];
    setResults(
      order.map((tld, i) => ({
        domain: `${parsed.label}.${tld}`,
        tld,
        available: sampleAvailability(parsed.label, tld),
        exact: parsed.tld !== null && i === 0,
      })),
    );
  }

  function toggle(result: Result) {
    setCart((current) => {
      const next = new Map(current);
      if (next.has(result.domain)) next.delete(result.domain);
      else next.set(result.domain, result.tld);
      return next;
    });
  }

  // Integer minor units throughout; the cart keeps items across searches.
  const cartTotal = [...cart.values()].reduce((sum, tld) => sum + samplePrices[tld].minor, 0);

  return (
    <div className="pc-stack">
      <div>
        <h1>Find your domain</h1>
        <p className="pc-muted">Search .ng, .com.ng, .com and more. Prices are per year.</p>
      </div>

      <Card>
        <form onSubmit={search} role="search" noValidate>
          <TextField
            label="Domain name"
            placeholder="yourbusiness.ng"
            autoComplete="off"
            spellCheck={false}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            error={error}
            trailing={
              <Button type="submit">
                <SearchIcon />
                Search
              </Button>
            }
          />
        </form>
      </Card>

      <div aria-live="polite" className="pc-stack">
        {results && (
          <Card title="Results" actions={<span className="pc-small pc-muted">Sample prices · availability is simulated</span>}>
            <ul className="pc-results">
              {results.map((r) => {
                const inCart = cart.has(r.domain);
                return (
                  <li key={r.domain} className={`pc-result${r.exact ? ' pc-result--exact' : ''}`}>
                    <div className="pc-result__name">
                      <span className="pc-result__domain">{r.domain}</span>
                      {r.available ? <Badge tone="success">Available</Badge> : <Badge tone="neutral">Taken</Badge>}
                      {r.tld === 'ng' || r.tld === 'com.ng' ? <Badge tone="accent">Nigeria</Badge> : null}
                    </div>
                    {r.available ? (
                      <div className="pc-result__buy">
                        <span className="pc-result__price">
                          {formatMoney(samplePrices[r.tld])}
                          <span className="pc-muted pc-small">/yr</span>
                        </span>
                        <Button
                          size="sm"
                          variant={inCart ? 'secondary' : 'primary'}
                          aria-pressed={inCart}
                          aria-label={`${inCart ? 'Remove' : 'Add'} ${r.domain} ${inCart ? 'from' : 'to'} cart`}
                          onClick={() => toggle(r)}
                        >
                          {inCart ? (
                            <>
                              <CheckIcon />
                              In cart
                            </>
                          ) : (
                            'Add to cart'
                          )}
                        </Button>
                      </div>
                    ) : (
                      <span className="pc-small pc-muted">Not available</span>
                    )}
                  </li>
                );
              })}
            </ul>
          </Card>
        )}

        {cart.size > 0 && (
          <div className="pc-cartbar" role="status">
            <span>
              <strong>{cart.size}</strong> {cart.size === 1 ? 'domain' : 'domains'} in cart ·{' '}
              <strong>{formatMoney({ minor: cartTotal, currency: 'NGN' })}</strong> before VAT
            </span>
            <Button>Continue to checkout</Button>
          </div>
        )}
      </div>
    </div>
  );
}
