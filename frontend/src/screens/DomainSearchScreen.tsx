import { useRef, useState, type FormEvent } from 'react';
import { Badge, Button, Card, PromoLabel, TextField } from '../components';
import { CheckIcon, SearchIcon } from '../components/icons';
import { describeWait, postJson } from '../lib/api';
import { formatMoney } from '../lib/money';
import { DomainPriceListView } from './DomainPriceList';

/** One searched name with its selling price (THREAT-MODEL-DOMAIN-SEARCH.md DS-9). Minor units only. */
export interface SearchResult {
  domain: string;
  tld: string;
  status: 'available' | 'taken' | 'unknown' | 'unavailable';
  /** True once the registrar has confirmed the status (DS-13). */
  confirmed?: boolean;
  premium?: boolean;
  featured: boolean;
  label: string;
  register: { usd: number; ngn: number };
  renew: { usd: number; ngn: number | null } | null;
}

type SearchOutcome = { kind: 'ok'; results: SearchResult[] } | { kind: 'error'; message: string };

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

type RegistrarAnswer = { domain: string; status: SearchResult['status']; premium: boolean; register: { usd: number; ngn: number } | null };

/**
 * Asks the registrar itself (through the provider worker, DS-13) about the names
 * RDAP could not rule out, and waits up to ~15 s for the answer.
 */
export async function confirmWithRegistrar(
  domains: string[],
  fetchImpl: typeof fetch = fetch,
  wait: (ms: number) => Promise<void> = (ms) => new Promise((r) => window.setTimeout(r, ms)),
): Promise<RegistrarAnswer[]> {
  if (domains.length === 0) return [];
  const queued = await postJson('/api/v1/domains/check', { domains: domains.slice(0, 6) }, fetchImpl);
  const id = queued.kind === 'ok' ? (queued.data as { id?: unknown } | null)?.id : undefined;
  if (typeof id !== 'string' || !/^[a-f0-9]{32}$/.test(id)) return [];
  for (let attempt = 0; attempt < 15; attempt++) {
    await wait(1000);
    let body: { status?: unknown; results?: unknown };
    try {
      const response = await fetchImpl(`/api/v1/domains/check?id=${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) return [];
      body = (await response.json()) as typeof body;
    } catch {
      return [];
    }
    if (body.status === 'pending') continue;
    if (body.status !== 'done' || !Array.isArray(body.results)) return [];
    const out: RegistrarAnswer[] = [];
    for (const raw of body.results) {
      const r = raw as Record<string, unknown>;
      const register = r.register as Record<string, unknown> | null | undefined;
      if (typeof r.domain !== 'string' || !['available', 'taken', 'unknown', 'unavailable'].includes(String(r.status))) continue;
      out.push({
        domain: r.domain,
        status: r.status as SearchResult['status'],
        premium: r.premium === true,
        register: register && isMinor(register.usd) && isMinor(register.ngn) ? { usd: register.usd, ngn: register.ngn } : null,
      });
    }
    return out;
  }
  return [];
}

/** The registrar's answer wins over RDAP; "unknown" from the registrar keeps the RDAP result. */
export function mergeAnswers(results: readonly SearchResult[], answers: readonly RegistrarAnswer[]): SearchResult[] {
  const byDomain = new Map(answers.map((a) => [a.domain, a]));
  return results.map((r) => {
    const a = byDomain.get(r.domain);
    if (!a || a.status === 'unknown') return r;
    const next: SearchResult = { ...r, status: a.status, confirmed: true, premium: a.premium };
    if (a.premium && a.register) next.register = a.register;
    if (a.premium && !a.register && a.status === 'available') next.status = 'unavailable';
    if (a.premium) next.renew = null;
    return next;
  });
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
  const [confirming, setConfirming] = useState(false);
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
    const toConfirm = outcome.results.filter((r) => r.status !== 'taken').map((r) => r.domain);
    if (toConfirm.length > 0) {
      setConfirming(true);
      const answers = await confirmWithRegistrar(toConfirm, fetchImpl);
      setConfirming(false);
      if (answers.length > 0) setResults((current) => (current ? mergeAnswers(current, answers) : current));
    }
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
          <Card title="Results" actions={<span className="pc-small pc-muted">{confirming ? 'Confirming with the registry…' : 'Checked with the registry; confirmed again at checkout.'}</span>}>
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
                        {r.status === 'unknown' && <Badge tone="warning">{confirming ? 'Checking…' : "Couldn't check"}</Badge>}
                        {r.status === 'unavailable' && <Badge tone="neutral">Not available</Badge>}
                        {r.premium && <Badge tone="accent">Premium</Badge>}
                        <PromoLabel label={r.label} />
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
                        <span className="pc-small pc-muted">
                          {r.status === 'taken'
                            ? 'Already registered'
                            : r.status === 'unavailable'
                              ? r.premium
                                ? 'Premium name: contact us for the price'
                                : 'Reserved by the registry'
                              : confirming
                                ? 'Asking the registry…'
                                : 'Try again in a minute'}
                        </span>
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
