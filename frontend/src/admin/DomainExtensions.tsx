import { CatalogueBackupCard } from './CatalogueBackup';
import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Modal, PROMO_LABELS, PromoLabel, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import { formatWhen } from '../lib/activity';
import {
  fetchDomainExtensions,
  formatMinor,
  saveDomainPricing,
  saveExtensionSettings,
  type DomainExtensions,
  type ExtensionPriceInput,
  type ExtensionRow,
  type StaffSession,
} from './adminApi';

const REGISTRARS: Record<string, string> = { domainnameapi: 'DomainNameAPI', namecheap: 'Namecheap', dynadot: 'Dynadot', spaceship: 'Spaceship' };
const LABELS: Record<string, string> = { none: 'No label', ...PROMO_LABELS };
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
const PRICE_ACTIONS = ['register', 'renew', 'transfer'] as const;
const ACTION_NAMES: Record<string, string> = { register: 'Register', renew: 'Renew', transfer: 'Transfer' };

/** "25" or "25.5" (per cent) → basis points, without floating point. */
export function percentToBp(text: string): number | null {
  const m = /^(\d{1,4})(?:\.(\d{1,2}))?$/.exec(text.trim());
  if (!m) return null;
  return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}

/** "80" or "80.5" (major units) → minor units, without floating point. */
export function amountToMinor(text: string): number | null {
  const m = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(text.trim());
  if (!m) return null;
  return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}

const bpToPercent = (bp: number): string => `${Math.trunc(bp / 100)}${bp % 100 === 0 ? '' : `.${String(bp % 100).padStart(2, '0').replace(/0$/, '')}`}`;
const minorToAmount = (minor: number): string => `${Math.trunc(minor / 100)}${minor % 100 === 0 ? '' : `.${String(minor % 100).padStart(2, '0')}`}`;

function failure(result: ApiResult, fallback: string): string {
  if (result.kind === 'throttled') return `Too many attempts. Try again ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'rejected' && result.code === 'step_up_failed') return 'That authenticator code is not right. Use the newest code from your app.';
  if (result.kind === 'rejected' && result.code === 'invalid') return 'Some values are out of range. Check them and try again.';
  if (result.kind === 'rejected' && result.code === 'below_floor')
    return 'One of your prices is below the registrar cost plus your minimum margin, or that action is not sold for this extension. Raise it or leave it empty.';
  return fallback;
}

/**
 * Domain extensions (THREAT-MODEL-DOMAIN-PRICING.md DX-2 to DX-13): every
 * extension a registrar offers, the registrar new registrations go to, and
 * the one-year selling prices in USD and NGN. Catalogue staff pin a
 * registrar, feature and label extensions, and set grace and redemption;
 * pricing settings also need a fresh authenticator code. Text only.
 */
export function DomainExtensionsPage({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [data, setData] = useState<DomainExtensions | 'loading' | 'failed'>('loading');
  const [query, setQuery] = useState('');
  const [featured, setFeatured] = useState(false);
  const [page, setPage] = useState(1);
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      void fetchDomainExtensions({ q: query, page, featured }, fetchImpl).then((next) => {
        if (active) setData(next.kind === 'ok' ? next.data : 'failed');
      });
    }, 200);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [fetchImpl, query, page, featured, version]);

  const reload = (text: string) => {
    setMessage({ tone: 'success', text });
    setEditing(null);
    setVersion((n) => n + 1);
  };

  if (data === 'loading') return <Card title="Domain extensions"><p className="pc-muted">Loading extensions…</p></Card>;
  if (data === 'failed') return <Card title="Domain extensions"><Alert tone="danger">Domain extensions could not be loaded.</Alert></Card>;

  const pageCount = Math.max(1, Math.ceil(data.total / data.pageSize));

  return (
    <>
      <PricingSettingsCard data={data} session={session} fetchImpl={fetchImpl} onSaved={() => reload('Pricing settings saved. Prices below are recalculated.')} />
      <Card title="Domain extensions">
        <p className="pc-muted">
          One selling price per extension. New registrations go to the cheapest registrar with a fresh price list, unless you pin one. Existing domains stay with
          their registrar. Internal costs are never shown to customers.
        </p>
        {message && <Alert tone={message.tone}>{message.text}</Alert>}
        <div className="pc-admin__row">
          <TextField
            label="Find an extension"
            value={query}
            spellCheck={false}
            hint="For example com, ng or com.ng"
            onChange={(e) => {
              setQuery(e.target.value);
              setPage(1);
            }}
          />
          <label className="pc-checkbox">
            <input
              type="checkbox"
              checked={featured}
              onChange={(e) => {
                setFeatured(e.target.checked);
                setPage(1);
              }}
            />{' '}
            Featured only
          </label>
        </div>
        <div className="pc-table-scroll">
          <table className="pc-table" aria-label="Domain extensions">
            <caption className="pc-muted">
              One-year prices. {data.total === 0 ? 'No extension matches.' : `${data.total} extensions.`}
              {data.pricing === null && ' Grey amounts are the registrar cost; selling prices appear once pricing settings are saved.'}
            </caption>
            <thead>
              <tr>
                <th scope="col">Extension</th>
                <th scope="col">Registrar</th>
                <th scope="col">Register</th>
                <th scope="col">Renew</th>
                <th scope="col">Transfer</th>
                <th scope="col">Restore</th>
                <th scope="col">Grace / redemption</th>
                {data.canManage && <th scope="col"><span className="pc-visually-hidden">Edit</span></th>}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => (
                <ExtensionRowView key={row.tld} row={row} canManage={data.canManage} priced={data.pricing !== null} onEdit={() => setEditing(row.tld)} />
              ))}
            </tbody>
          </table>
        </div>
        <ExtensionEditor
          row={data.rows.find((r) => r.tld === editing) ?? null}
          data={data}
          session={session}
          fetchImpl={fetchImpl}
          onClose={() => setEditing(null)}
          onSaved={(tld) => reload(`.${tld} saved.`)}
        />
        {pageCount > 1 && (
          <nav className="pc-admin__row" aria-label="Extension pages">
            <Button variant="secondary" onClick={() => setPage(data.page - 1)} disabled={data.page <= 1}>
              Previous
            </Button>
            <span aria-live="polite">
              Page {data.page} of {pageCount}
            </span>
            <Button variant="secondary" onClick={() => setPage(data.page + 1)} disabled={data.page >= pageCount}>
              Next
            </Button>
          </nav>
        )}
      </Card>
      {data.canManage && <CatalogueBackupCard session={session} fetchImpl={fetchImpl} onImported={() => reload('Settings loaded. Prices below are recalculated.')} />}
    </>
  );
}

function amounts(usd: number | null, ngn: number | null) {
  return (
    <>
      {formatMinor(usd ?? 0, 'USD')}
      {ngn !== null && (
        <>
          <br />
          <span className="pc-muted">{formatMinor(ngn, 'NGN')}</span>
        </>
      )}
    </>
  );
}

const hasOverride = (row: ExtensionRow): boolean =>
  PRICE_ACTIONS.some((a) => (row.overrides.usd[a] ?? null) !== null || (row.overrides.ngn[a] ?? null) !== null);

function ExtensionRowView({ row, canManage, priced, onEdit }: { row: ExtensionRow; canManage: boolean; priced: boolean; onEdit: () => void }) {
  const cell = (action: string) => {
    const usd = row.usd?.[action] ?? null;
    if (usd !== null) return amounts(usd, row.ngn?.[action] ?? null);
    const cost = row.cost?.[action] ?? null;
    if (!priced && cost !== null) {
      return (
        <span className="pc-ext__cost" title="Registrar cost, before your markup">
          {formatMinor(cost, 'USD')}
          <br />
          <small>cost</small>
        </span>
      );
    }
    return <span className="pc-muted">—</span>;
  };
  return (
    <tr>
      <td>
        <span className="pc-ext__name">.{row.tld}</span>{' '}
        {row.featuredOrder !== null && <Badge tone="info">Featured {row.featuredOrder}</Badge>}{' '}
        <PromoLabel label={row.label} />{' '}
        {hasOverride(row) && <Badge tone={row.overridesIgnored.length > 0 ? 'danger' : 'success'}>{row.overridesIgnored.length > 0 ? 'Custom price paused' : 'Custom price'}</Badge>}
        <br />
        <small className="pc-muted">{CATEGORY_NAMES[row.categoryShown] ?? row.categoryShown}</small>
      </td>
      <td>
        {row.chosenRegistrar ? (REGISTRARS[row.chosenRegistrar] ?? row.chosenRegistrar) : <span className="pc-muted">No fresh price</span>}
        {row.pinnedRegistrar && !row.pinUnavailable && <span className="pc-muted"> (pinned)</span>}
        {row.pinUnavailable && <Badge tone="danger">Pin unavailable</Badge>}
      </td>
      <td>{cell('register')}</td>
      <td>{cell('renew')}</td>
      <td>{cell('transfer')}</td>
      <td>{cell('restore')}</td>
      <td>
        {row.graceDays} / {row.redemptionDays} days
      </td>
      {canManage && (
        <td>
          <Button variant="secondary" onClick={onEdit} aria-haspopup="dialog">
            Edit
          </Button>
        </td>
      )}
    </tr>
  );
}

/** Edit one extension in a dialog: who registers it, how it is shown, and its lifecycle. */
function ExtensionEditor({
  row,
  data,
  session,
  fetchImpl,
  onClose,
  onSaved,
}: {
  row: ExtensionRow | null;
  data: DomainExtensions;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onClose: () => void;
  onSaved: (tld: string) => void;
}) {
  // Keep the last row while the dialog animates closed.
  const [shown, setShown] = useState<ExtensionRow | null>(row);
  if (row && row !== shown) setShown(row);
  return (
    <Modal
      open={row !== null}
      title={shown ? `Edit .${shown.tld}` : 'Edit extension'}
      description="Changes apply to new orders only. Existing domains keep their registrar and price."
      onClose={onClose}
      size="lg"
    >
      {shown && <ExtensionForm key={shown.tld} row={shown} data={data} session={session} fetchImpl={fetchImpl} onCancel={onClose} onSaved={() => onSaved(shown.tld)} />}
    </Modal>
  );
}

function ExtensionForm({
  row,
  data,
  session,
  fetchImpl,
  onCancel,
  onSaved,
}: {
  row: ExtensionRow;
  data: DomainExtensions;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onSaved: () => void;
}) {
  const [pin, setPin] = useState(row.pinnedRegistrar ?? '');
  const [order, setOrder] = useState(row.featuredOrder === null ? '' : String(row.featuredOrder));
  const [label, setLabel] = useState(row.label);
  const [graceDays, setGraceDays] = useState(String(row.graceDays));
  const [graceFee, setGraceFee] = useState(minorToAmount(row.graceFee));
  const [redemptionDays, setRedemptionDays] = useState(String(row.redemptionDays));
  const [redemptionFee, setRedemptionFee] = useState(row.redemptionFee === null ? '' : minorToAmount(row.redemptionFee));
  const [category, setCategory] = useState(row.category ?? '');
  const [prices, setPrices] = useState<Record<'usd' | 'ngn', Record<string, string>>>(() => {
    const text = (v: number | null | undefined) => (v === null || v === undefined ? '' : minorToAmount(v));
    return {
      usd: Object.fromEntries(PRICE_ACTIONS.map((a) => [a, text(row.overrides.usd[a])])),
      ngn: Object.fromEntries(PRICE_ACTIONS.map((a) => [a, text(row.overrides.ngn[a])])),
    };
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const priceInput: ExtensionPriceInput = { usd: {}, ngn: {} };
    for (const currency of ['usd', 'ngn'] as const) {
      for (const action of PRICE_ACTIONS) {
        const text = (prices[currency][action] ?? '').trim();
        const value = text === '' ? null : amountToMinor(text);
        if (text !== '' && (value === null || value === 0)) {
          setError('Enter prices like 12 or 12.99, or leave them empty to use your markup.');
          return;
        }
        priceInput[currency][action] = value;
      }
    }
    const whole = (text: string, max: number): number | null | undefined => {
      if (text.trim() === '') return null;
      return /^\d{1,3}$/.test(text.trim()) && Number(text) <= max ? Number(text) : undefined;
    };
    const orderValue = whole(order, 999);
    const grace = whole(graceDays, 90);
    const redemption = whole(redemptionDays, 90);
    const graceFeeMinor = graceFee.trim() === '' ? 0 : amountToMinor(graceFee);
    const redemptionFeeMinor = redemptionFee.trim() === '' ? null : amountToMinor(redemptionFee);
    if (orderValue === undefined || orderValue === 0 || grace === undefined || redemption === undefined || graceFeeMinor === null || (redemptionFee.trim() !== '' && redemptionFeeMinor === null)) {
      setError('Use whole days from 0 to 90, a featured position from 1 to 999 (or leave it empty), and amounts like 80 or 80.50.');
      return;
    }
    setSaving(true);
    setError(null);
    const result = await saveExtensionSettings(
      session.csrfToken,
      {
        tld: row.tld,
        pinned_registrar: pin === '' ? null : pin,
        featured_order: orderValue,
        label,
        grace_days: grace,
        grace_fee: graceFeeMinor,
        redemption_days: redemption,
        redemption_fee: redemptionFeeMinor,
        category: category === '' ? null : category,
        prices: priceInput,
      },
      fetchImpl,
    );
    setSaving(false);
    if (result.kind === 'ok') onSaved();
    else setError(failure(result, `.${row.tld} could not be saved.`));
  }

  return (
    <form onSubmit={(e) => void submit(e)} aria-label={`Edit .${row.tld}`}>
      <fieldset className="pc-form-section">
        <legend>Registrar</legend>
        <div className="pc-form-grid">
          <div className="pc-field pc-form-grid__wide">
            <label className="pc-field__label" htmlFor={`pin-${row.tld}`}>
              Registrar for new registrations
            </label>
            <select id={`pin-${row.tld}`} className="pc-input" value={pin} onChange={(e) => setPin(e.target.value)}>
              <option value="">Cheapest automatically</option>
              {data.registrars.map((r) => (
                <option key={r} value={r}>
                  Always {REGISTRARS[r] ?? r}
                </option>
              ))}
            </select>
          </div>
        </div>
        {row.offers.length === 0 ? (
          <p className="pc-muted">No registrar has a fresh price list for this extension.</p>
        ) : (
          <table className="pc-table pc-ext__offers" aria-label={`Registrar costs for .${row.tld}`}>
            <thead>
              <tr>
                <th scope="col">Registrar</th>
                <th scope="col">Register cost</th>
                <th scope="col">Renew cost</th>
              </tr>
            </thead>
            <tbody>
              {row.offers.map((o) => (
                <tr key={o.registrar}>
                  <td>
                    {REGISTRARS[o.registrar] ?? o.registrar} {o.registrar === row.chosenRegistrar && <Badge tone="success">Chosen</Badge>}
                  </td>
                  <td>{formatMinor(o.register, o.currency)}</td>
                  <td>{formatMinor(o.renew, o.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </fieldset>

      <fieldset className="pc-form-section">
        <legend>How it is shown</legend>
        <div className="pc-form-grid">
          <TextField label="Featured position" hint="1 shows first. Leave empty to not feature it." inputMode="numeric" value={order} onChange={(e) => setOrder(e.target.value)} />
          <div className="pc-field">
            <label className="pc-field__label" htmlFor={`label-${row.tld}`}>
              Label
            </label>
            <select id={`label-${row.tld}`} className="pc-input" value={label} onChange={(e) => setLabel(e.target.value)}>
              {data.labels.map((l) => (
                <option key={l} value={l}>
                  {LABELS[l] ?? l}
                </option>
              ))}
            </select>
          </div>
          <div className="pc-field pc-form-grid__wide">
            <label className="pc-field__label" htmlFor={`category-${row.tld}`}>
              Category on the price list
            </label>
            <select id={`category-${row.tld}`} className="pc-input" value={category} onChange={(e) => setCategory(e.target.value)}>
              <option value="">Automatic ({CATEGORY_NAMES[row.categoryShown] ?? row.categoryShown})</option>
              {data.categories.map((c) => (
                <option key={c} value={c}>
                  {CATEGORY_NAMES[c] ?? c}
                </option>
              ))}
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset className="pc-form-section">
        <legend>Your prices (one year)</legend>
        <p className="pc-muted pc-small">
          Leave a box empty to use your markup price (shown in grey). A price below the registrar cost plus your minimum margin is refused, and if the cost later rises
          above it, the markup price is charged until you change it.
        </p>
        {row.overridesIgnored.length > 0 && (
          <Alert tone="danger">
            Not charged right now: {row.overridesIgnored.map((k) => `${ACTION_NAMES[k.split('.')[1] ?? ''] ?? k} (${k.split('.')[0]?.toUpperCase()})`).join(', ')}. The cost has risen
            above it, so the markup price is used.
          </Alert>
        )}
        <div className="pc-price-grid" role="group" aria-label={`Prices for .${row.tld}`}>
          <span />
          <span className="pc-price-grid__head">USD</span>
          <span className="pc-price-grid__head">NGN</span>
          {PRICE_ACTIONS.map((action) => (
            <PriceRow key={action} action={action} row={row} prices={prices} setPrices={setPrices} />
          ))}
        </div>
      </fieldset>

      <fieldset className="pc-form-section">
        <legend>After expiry</legend>
        <div className="pc-form-grid">
          <TextField label="Grace period (days)" hint="The customer can still renew normally." inputMode="numeric" value={graceDays} onChange={(e) => setGraceDays(e.target.value)} />
          <TextField label="Grace fee (USD)" hint="Added to a renewal made during grace." inputMode="decimal" value={graceFee} onChange={(e) => setGraceFee(e.target.value)} />
          <TextField label="Redemption period (days)" hint="Only a paid restore can recover the domain." inputMode="numeric" value={redemptionDays} onChange={(e) => setRedemptionDays(e.target.value)} />
          <TextField label="Redemption fee (USD)" hint="Leave empty to use the registrar's restore price." inputMode="decimal" value={redemptionFee} onChange={(e) => setRedemptionFee(e.target.value)} />
        </div>
      </fieldset>

      {error && <Alert tone="danger">{error}</Alert>}
      <div className="pc-modal__actions">
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" loading={saving}>
          Save .{row.tld}
        </Button>
      </div>
    </form>
  );
}

function PriceRow({
  action,
  row,
  prices,
  setPrices,
}: {
  action: string;
  row: ExtensionRow;
  prices: Record<'usd' | 'ngn', Record<string, string>>;
  setPrices: (update: (p: Record<'usd' | 'ngn', Record<string, string>>) => Record<'usd' | 'ngn', Record<string, string>>) => void;
}) {
  const auto = (currency: 'usd' | 'ngn') => {
    const v = (currency === 'usd' ? row.usd : row.ngn)?.[action] ?? null;
    return v === null ? 'Markup price' : `Auto ${minorToAmount(v)}`;
  };
  return (
    <>
      <span className="pc-price-grid__action">{ACTION_NAMES[action]}</span>
      {(['usd', 'ngn'] as const).map((currency) => (
        <input
          key={currency}
          className="pc-input"
          inputMode="decimal"
          aria-label={`${ACTION_NAMES[action]} price in ${currency.toUpperCase()}`}
          placeholder={auto(currency)}
          value={prices[currency][action] ?? ''}
          onChange={(e) => {
            const value = e.target.value;
            setPrices((p) => ({ ...p, [currency]: { ...p[currency], [action]: value } }));
          }}
        />
      ))}
    </>
  );
}

/** What the settings do to a sample USD 10.00 cost, so the numbers are easy to check before saving. */
export function pricingExample(markupBp: number, floorBp: number, usdStep: number): { price: number; floor: number } {
  const cost = 1000;
  const raw = Math.ceil((cost * (10000 + markupBp)) / 10000);
  const price = Math.ceil(raw / usdStep) * usdStep;
  const floor = Math.ceil((cost * (10000 + floorBp)) / 10000);
  return { price: Math.max(price, floor), floor };
}

function PricingSettingsCard({ data, session, fetchImpl, onSaved }: { data: DomainExtensions; session: StaffSession; fetchImpl?: typeof fetch | undefined; onSaved: () => void }) {
  const p = data.pricing;
  const [open, setOpen] = useState(false);
  const [markup, setMarkup] = useState(p ? bpToPercent(p.markupBp) : '');
  const [floor, setFloor] = useState(p ? bpToPercent(p.minMarginBp) : '');
  const [usdStep, setUsdStep] = useState(p ? minorToAmount(p.usdStep) : '0.01');
  const [ngnStep, setNgnStep] = useState(p ? minorToAmount(p.ngnStep) : '50');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const markupBp = percentToBp(markup);
  const floorBp = percentToBp(floor);
  const usdMinor = amountToMinor(usdStep);
  const example = markupBp !== null && floorBp !== null && usdMinor !== null && usdMinor > 0 ? pricingExample(markupBp, floorBp, usdMinor) : null;

  async function submit(event: FormEvent) {
    event.preventDefault();
    const ngn = amountToMinor(ngnStep);
    if (markupBp === null || floorBp === null || usdMinor === null || ngn === null || usdMinor < 1 || ngn < 1) {
      setError('Use percentages like 25 or 12.5, and rounding steps like 0.01 or 50.');
      return;
    }
    if (floorBp > markupBp) {
      setError('The minimum margin cannot be higher than the markup.');
      return;
    }
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    setSaving(true);
    setError(null);
    const result = await saveDomainPricing(session.csrfToken, { markup_bp: markupBp, min_margin_bp: floorBp, usd_step: usdMinor, ngn_step: ngn, code }, fetchImpl);
    setSaving(false);
    setCode('');
    if (result.kind === 'ok') {
      setOpen(false);
      onSaved();
    } else setError(failure(result, 'The pricing settings could not be saved.'));
  }

  return (
    <Card title="Pricing settings">
      {p ? (
        <p>
          Markup {bpToPercent(p.markupBp)}% on the registrar cost; no price below cost + {bpToPercent(p.minMarginBp)}%. Prices round up to{' '}
          {formatMinor(p.usdStep, 'USD')} and {formatMinor(p.ngnStep, 'NGN')}.
        </p>
      ) : (
        <Alert tone="warning">No pricing settings yet, so nothing is priced. Set the markup and minimum margin to start selling.</Alert>
      )}
      <p className="pc-muted">
        {data.fx ? `Naira prices use the exchange rate from ${data.fx.source}, ${formatWhen(data.fx.fetchedAt)}.` : 'Waiting for the first exchange rate; it is fetched automatically.'}
      </p>
      {data.canManage && (
        <Button variant="secondary" onClick={() => setOpen(true)} aria-haspopup="dialog">
          {p ? 'Change pricing settings' : 'Set pricing settings'}
        </Button>
      )}
      <Modal
        open={open}
        title="Pricing settings"
        description="These rules turn each registrar's cost into the price customers pay, for every extension at once."
        onClose={() => setOpen(false)}
      >
        <form onSubmit={(e) => void submit(e)} aria-label="Pricing settings">
          <fieldset className="pc-form-section">
            <legend>Your margin</legend>
            <div className="pc-form-grid">
              <TextField
                label="Markup (%)"
                hint="What you add on top of the registrar cost. 20 means a USD 10.00 cost sells for USD 12.00."
                inputMode="decimal"
                value={markup}
                onChange={(e) => setMarkup(e.target.value)}
              />
              <TextField
                label="Minimum margin (%)"
                hint="Safety floor. If any price would fall below cost plus this, that action is not sold. Keep it at or below the markup, for example 10."
                inputMode="decimal"
                value={floor}
                onChange={(e) => setFloor(e.target.value)}
              />
            </div>
          </fieldset>
          <fieldset className="pc-form-section">
            <legend>Rounding</legend>
            <div className="pc-form-grid">
              <TextField label="Round USD prices up to (USD)" hint="0.01 keeps cents; 1 gives whole dollars like 13.00." inputMode="decimal" value={usdStep} onChange={(e) => setUsdStep(e.target.value)} />
              <TextField label="Round naira prices up to (NGN)" hint="50 gives prices like ₦17,550; 100 gives ₦17,600." inputMode="decimal" value={ngnStep} onChange={(e) => setNgnStep(e.target.value)} />
            </div>
          </fieldset>
          {example && (
            <p className="pc-ext__example" aria-live="polite">
              Example: a registrar cost of USD 10.00 sells for <strong>{formatMinor(example.price, 'USD')}</strong>. Nothing is sold below {formatMinor(example.floor, 'USD')}.
            </p>
          )}
          <fieldset className="pc-form-section">
            <legend>Confirm</legend>
            <TextField label="Authenticator code" hint="From your authenticator app." autoComplete="one-time-code" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} />
          </fieldset>
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="pc-modal__actions">
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              Save pricing settings
            </Button>
          </div>
        </form>
      </Modal>
    </Card>
  );
}
