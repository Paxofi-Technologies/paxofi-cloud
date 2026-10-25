import { useEffect, useState } from 'react';
import { Button, Card, PromoLabel, TextField } from '../components';
import { formatMoney } from '../lib/money';

type Pair = { usd: number; ngn: number } | null;

/** One extension on the public price list (THREAT-MODEL-DOMAIN-SEARCH.md DS-11). Minor units only. */
export interface PriceRow {
  tld: string;
  featured: boolean;
  label: string;
  category: string;
  register: Pair;
  transfer: Pair;
  renew: Pair;
}

export interface PriceList {
  featured: PriceRow[];
  /** Extensions on sale per category (for the tab counts). */
  categories: Record<string, number>;
  total: number;
  page: number;
  pageSize: number;
  rows: PriceRow[];
}

/** Tab names for the categories the server knows (DS-12). */
export const CATEGORY_NAMES: Record<string, string> = {
  business: 'Business',
  technology: 'Technology',
  geographic: 'Geographic',
  arts: 'Arts and entertainment',
  sports: 'Sports',
  services: 'Services',
  finance: 'Money and finance',
  education: 'Education',
  food: 'Food and drink',
  leisure: 'Leisure and recreation',
  shopping: 'Shopping',
  real_estate: 'Real estate',
  novelty: 'Novelty',
  other: 'Other',
};
const isMinor = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;

function pair(value: unknown): Pair {
  const v = value as Record<string, unknown> | null | undefined;
  return v && isMinor(v.usd) && isMinor(v.ngn) ? { usd: v.usd, ngn: v.ngn } : null;
}

function rows(value: unknown): PriceRow[] {
  const out: PriceRow[] = [];
  for (const raw of Array.isArray(value) ? value : []) {
    const r = raw as Record<string, unknown>;
    if (typeof r.tld !== 'string' || !/^[a-z0-9.-]{1,63}$/.test(r.tld)) continue;
    const register = pair(r.register);
    if (!register) continue;
    out.push({
      tld: r.tld,
      featured: r.featured === true,
      label: typeof r.label === 'string' ? r.label : 'none',
      category: typeof r.category === 'string' && Object.hasOwn(CATEGORY_NAMES, r.category) ? r.category : 'other',
      register, transfer: pair(r.transfer), renew: pair(r.renew) });
  }
  return out;
}

/** Fetches the public price list and keeps only well-formed rows; values are rendered as text. */
export async function fetchPriceList(query: { q: string; page: number; category?: string }, fetchImpl: typeof fetch = fetch): Promise<PriceList | null> {
  const params = new URLSearchParams();
  if (query.q) params.set('q', query.q);
  if (query.category) params.set('category', query.category);
  if (query.page > 1) params.set('page', String(query.page));
  try {
    const response = await fetchImpl(`/api/v1/domains/prices${params.size ? `?${params.toString()}` : ''}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) return null;
    const body = (await response.json()) as Record<string, unknown>;
    const counts: Record<string, number> = {};
    const rawCounts = (body.categories ?? {}) as Record<string, unknown>;
    for (const key of Object.keys(CATEGORY_NAMES)) {
      const n = rawCounts[key];
      if (isMinor(n) && n > 0) counts[key] = n;
    }
    return {
      featured: rows(body.featured),
      categories: counts,
      total: isMinor(body.total) ? body.total : 0,
      page: isMinor(body.page) ? body.page : 1,
      pageSize: isMinor(body.page_size) && body.page_size > 0 ? body.page_size : 25,
      rows: rows(body.rows),
    };
  } catch {
    return null;
  }
}

function Price({ value }: { value: Pair }) {
  if (!value) return <span className="pc-muted">Not online</span>;
  return (
    <>
      <span className="pc-pricelist__amount">{formatMoney({ minor: value.ngn, currency: 'NGN' })}</span>
      <span className="pc-pricelist__sub">{formatMoney({ minor: value.usd, currency: 'USD' })} · 1 year</span>
    </>
  );
}

/**
 * Featured extensions as cards, then every extension we sell with its
 * register, transfer and renew price. Choosing a card puts that extension
 * into the search box.
 */
export function DomainPriceListView({ fetchImpl, onPick }: { fetchImpl?: typeof fetch | undefined; onPick: (tld: string) => void }) {
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState('');
  const [list, setList] = useState<PriceList | null | 'loading'>('loading');

  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      void fetchPriceList({ q: query.trim().toLowerCase().replace(/^\./, ''), page, category }, fetchImpl).then((next) => {
        if (active) setList(next);
      });
    }, 200);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [fetchImpl, query, page, category]);

  if (list === 'loading') return <p className="pc-muted">Loading prices…</p>;
  if (list === null) return null;
  const pageCount = Math.max(1, Math.ceil(list.total / list.pageSize));

  return (
    <>
      {list.featured.length > 0 && (
        <section aria-label="Featured extensions" className="pc-tldcards">
          {list.featured.map((row, i) => (
            <button key={row.tld} type="button" className={`pc-tldcard pc-tldcard--${i % 5}`} onClick={() => onPick(row.tld)} style={{ animationDelay: `${i * 60}ms` }}>
              <PromoLabel label={row.label} className="pc-tldcard__label" />
              <span className="pc-tldcard__name">.{row.tld}</span>
              <span className="pc-tldcard__price">
                {row.register ? formatMoney({ minor: row.register.ngn, currency: 'NGN' }) : ''}
                <small>/yr</small>
              </span>
              {row.register && <span className="pc-tldcard__usd">{formatMoney({ minor: row.register.usd, currency: 'USD' })}/yr</span>}
            </button>
          ))}
        </section>
      )}
      <Card title="Domain prices">
        <div className="pc-pricelist__tabs" role="tablist" aria-label="Extension categories">
          {[['', 'All'] as const, ...Object.keys(list.categories).map((c) => [c, CATEGORY_NAMES[c] ?? c] as const)].map(([key, name]) => (
            <button
              key={key || 'all'}
              type="button"
              role="tab"
              aria-selected={category === key}
              className="pc-pricelist__tab"
              onClick={() => {
                setCategory(key);
                setPage(1);
              }}
            >
              {name}
              {key !== '' && <span className="pc-pricelist__count"> ({list.categories[key]})</span>}
            </button>
          ))}
        </div>
        <div className="pc-pricelist__filter">
          <TextField
            label="Find an extension"
            placeholder="com, ng, africa…"
            spellCheck={false}
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setPage(1);
            }}
          />
        </div>
        {list.rows.length === 0 ? (
          <p className="pc-muted">{query ? 'No extension matches that search.' : 'Prices will appear here soon.'}</p>
        ) : (
          <div className="pc-table-scroll">
            <table className="pc-table pc-pricelist" aria-label="Domain prices">
              <caption className="pc-visually-hidden">Prices for one year, in naira and US dollars. {list.total} extensions.</caption>
              <thead>
                <tr>
                  <th scope="col">Extension</th>
                  <th scope="col">Register</th>
                  <th scope="col">Transfer</th>
                  <th scope="col">Renew</th>
                </tr>
              </thead>
              <tbody>
                {list.rows.map((row) => (
                  <tr key={row.tld}>
                    <th scope="row">
                      <button type="button" className="pc-pricelist__tld" onClick={() => onPick(row.tld)}>
                        .{row.tld}
                      </button>{' '}
                      <PromoLabel label={row.label} />
                    </th>
                    <td>
                      <Price value={row.register} />
                    </td>
                    <td>
                      <Price value={row.transfer} />
                    </td>
                    <td>
                      <Price value={row.renew} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {pageCount > 1 && (
          <nav className="pc-admin__row" aria-label="Price list pages">
            <Button variant="secondary" onClick={() => setPage(list.page - 1)} disabled={list.page <= 1}>
              Previous
            </Button>
            <span aria-live="polite">
              Page {list.page} of {pageCount}
            </span>
            <Button variant="secondary" onClick={() => setPage(list.page + 1)} disabled={list.page >= pageCount}>
              Next
            </Button>
          </nav>
        )}
      </Card>
    </>
  );
}
