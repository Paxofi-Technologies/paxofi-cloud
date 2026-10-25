import { useRef, useState, type FormEvent } from 'react';
import { Badge, Button, Card, TextField } from '../components';
import { CheckIcon, SearchIcon } from '../components/icons';
import { describeWait } from '../lib/api';
import { formatMoney } from '../lib/money';
import { DomainPriceListView } from './DomainPriceList';

/** One searched name with its selling price (THREAT-MODEL-DOMAIN-SEARCH.md DS-9). Minor units only. */
export interface SearchResult {
  domain: string;
  tld: string;
  status: 'available' | 'taken' | 'unknown';
  featured: boolean;
  label: string;
  register: { usd: number; ngn: number };
  renew: { usd: number; ngn: number | null } | null;
}

type SearchOutcome = { kind: 'ok'; results: SearchResult[] } | { kind: 'error'; message: string };

const LABELS: Record<string, string> = { hot: 'HOT', new: 'NEW', sale: 'SALE' };
const isMinor = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;

/** Calls the public search and keeps only well-formed results; values are rendered as text. */
export async function searchDomains(name: string, fetchImpl: typeof fetch = fetch): Promise<SearchOutcome> {
  let response: Response;
  try {
    response = await fetchImpl(`/api/v1/domains/search?name=${encodeURIComponent(name)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  } catch {
    return { kind: 'error', message: 'We could not reach PaxofiCloud. Check your connection and try again.' };
  }
  if (response.status === 429) {
    const wait = Number(response.headers.get('retry-after') ?? '60');
    return { kind: 'error', message: `Too many searches. Try again ${describeWait(Number.isFinite(wait) ? wait : 60)}.` };
  }
  if (response.status === 422 || response.status === 400) {
    let code = '';
    try {
      const body = (await response.json()) as { code?: unknown };
      code = typeof body.code === 'string' ? body.code : '';
    } catch {
      // keep the general message
    }
    return {
      kind: 'error',
      message:
        code === 'unknown_extension'
          ? "We don't sell that extension yet. Try .ng, .com.ng, .com or another one."
          : 'Use letters, numbers and hyphens only, like yourbusiness or yourbusiness.ng.',
    };
  }
  if (!response.ok) return { kind: 'error', message: 'Search is not available right now. Please try again in a moment.' };
  const body = (await response.json()) as { results?: unknown };
  const results: SearchResult[] = [];
  for (const raw of Array.isArray(body.results) ? body.results : []) {
    const r = raw as Record<string, unknown>;
    const register = r.register as Record<string, unknown> | undefined;
    const renew = r.renew as Record<string, unknown> | null | undefined;
    if (
      typeof r.domain !== 'string' ||
      typeof r.tld !== 'string' ||
      (r.status !== 'available' && r.status !== 'taken' && r.status !== 'unknown') ||
      !register ||
      !isMinor(register.usd) ||
      !isMinor(register.ngn)
    )
      continue;
    results.push({
      domain: r.domain,
      tld: r.tld,
      status: r.status,
      featured: r.featured === true,
      label: typeof r.label === 'string' ? r.label : 'none',
      register: { usd: register.usd, ngn: register.ngn },
      renew: renew && isMinor(renew.usd) ? { usd: renew.usd, ngn: isMinor(renew.ngn) ? renew.ngn : null } : null,
    });
  }
  return { kind: 'ok', results };
}

/**
 * Find a domain (DOM-001, DOM-003): the typed name in its extension and our
 * featured extensions, with live availability (a pre-check; the registrar
 * confirms at checkout) and our prices in naira and US dollars.
 */
export function DomainSearchScreen({ fetchImpl }: { fetchImpl?: typeof fetch | undefined }) {
  const [query, setQuery] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [results, setResults] = useState<readonly SearchResult[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [picked, setPicked] = useState<ReadonlyMap<string, SearchResult>>(new Map());
  const top = useRef<HTMLDivElement>(null);

  /** A card or price-list row puts that extension into the search box. */
  function pick(tld: string) {
    const name = query.trim().toLowerCase().split('.')[0] ?? '';
    setQuery(`${name || 'yourname'}.${tld}`);
    setError(undefined);
    top.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    top.current?.querySelector('input')?.focus();
  }

  async function search(event: FormEvent) {
    event.preventDefault();
    const name = query.trim().toLowerCase();
    if (name === '') {
      setError('Type the name you want, like yourbusiness.');
      return;
    }
    setLoading(true);
    setError(undefined);
    const outcome = await searchDomains(name, fetchImpl);
    setLoading(false);
    if (outcome.kind === 'error') {
      setError(outcome.message);
      setResults(null);
      return;
    }
    setResults(outcome.results);
  }

  function toggle(result: SearchResult) {
    setPicked((current) => {
      const next = new Map(current);
      if (next.has(result.domain)) next.delete(result.domain);
      else next.set(result.domain, result);
      return next;
    });
  }

  const totalNgn = [...picked.values()].reduce((sum, r) => sum + r.register.ngn, 0);

  return (
    <div className="pc-stack" ref={top}>
      <div>
        <h1>Find your domain</h1>
        <p className="pc-muted">Search .ng, .com.ng, .com and more. Prices are for the first year.</p>
      </div>

      <Card>
        <form onSubmit={(e) => void search(e)} role="search" noValidate>
          <TextField
            label="Domain name"
            placeholder="yourbusiness.ng"
            autoComplete="off"
            spellCheck={false}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            error={error}
            trailing={
              <Button type="submit" loading={loading}>
                <SearchIcon />
                Search
              </Button>
            }
          />
        </form>
      </Card>

      <div aria-live="polite" className="pc-stack">
        {results && (
          <Card title="Results" actions={<span className="pc-small pc-muted">Availability is confirmed when you check out.</span>}>
            {results.length === 0 ? (
              <p className="pc-muted">No extensions are on sale yet. Please check back soon.</p>
            ) : (
              <ul className="pc-results">
                {results.map((r, i) => {
                  const chosen = picked.has(r.domain);
                  return (
                    <li key={r.domain} className={`pc-result${i === 0 ? ' pc-result--exact' : ''}`}>
                      <div className="pc-result__name">
                        <span className="pc-result__domain">{r.domain}</span>
                        {r.status === 'available' && <Badge tone="success">Available</Badge>}
                        {r.status === 'taken' && <Badge tone="neutral">Taken</Badge>}
                        {r.status === 'unknown' && <Badge tone="warning">Couldn't check</Badge>}
                        {LABELS[r.label] && <Badge tone="accent">{LABELS[r.label]}</Badge>}
                        {(r.tld === 'ng' || r.tld.endsWith('.ng')) && <Badge tone="info">Nigeria</Badge>}
                      </div>
                      {r.status === 'available' ? (
                        <div className="pc-result__buy">
                          <span className="pc-result__price">
                            {formatMoney({ minor: r.register.ngn, currency: 'NGN' })}
                            <span className="pc-muted pc-small">/yr</span>
                            <br />
                            <span className="pc-muted pc-small">
                              {formatMoney({ minor: r.register.usd, currency: 'USD' })}
                              {r.renew && r.renew.ngn !== null && r.renew.ngn !== r.register.ngn
                                ? ` · renews at ${formatMoney({ minor: r.renew.ngn, currency: 'NGN' })}`
                                : ''}
                            </span>
                          </span>
                          <Button
                            size="sm"
                            variant={chosen ? 'secondary' : 'primary'}
                            aria-pressed={chosen}
                            aria-label={`${chosen ? 'Remove' : 'Choose'} ${r.domain}`}
                            onClick={() => toggle(r)}
                          >
                            {chosen ? (
                              <>
                                <CheckIcon />
                                Chosen
                              </>
                            ) : (
                              'Choose'
                            )}
                          </Button>
                        </div>
                      ) : (
                        <span className="pc-small pc-muted">{r.status === 'taken' ? 'Already registered' : 'Try again in a minute'}</span>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        )}

        {picked.size > 0 && (
          <div className="pc-cartbar" role="status">
            <span>
              <strong>{picked.size}</strong> {picked.size === 1 ? 'domain' : 'domains'} chosen · <strong>{formatMoney({ minor: totalNgn, currency: 'NGN' })}</strong> before VAT
            </span>
            <span className="pc-small pc-muted">Checkout opens soon.</span>
          </div>
        )}
      </div>

      <DomainPriceListView fetchImpl={fetchImpl} onPick={pick} />
    </div>
  );
}
