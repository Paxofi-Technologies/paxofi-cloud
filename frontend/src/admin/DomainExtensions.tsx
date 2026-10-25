import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import { formatWhen } from '../lib/activity';
import {
  fetchDomainExtensions,
  formatMinor,
  saveDomainPricing,
  saveExtensionSettings,
  type DomainExtensions,
  type ExtensionRow,
  type StaffSession,
} from './adminApi';

const REGISTRARS: Record<string, string> = { domainnameapi: 'DomainNameAPI', namecheap: 'Namecheap', dynadot: 'Dynadot', spaceship: 'Spaceship' };
const LABELS: Record<string, string> = { none: 'No label', hot: 'HOT', new: 'NEW', sale: 'SALE' };

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
            <caption className="pc-muted">One-year prices. {data.total === 0 ? 'No extension matches.' : `${data.total} extensions.`}</caption>
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
                <ExtensionRowView
                  key={row.tld}
                  row={row}
                  data={data}
                  editing={editing === row.tld}
                  onEdit={() => setEditing(editing === row.tld ? null : row.tld)}
                  session={session}
                  fetchImpl={fetchImpl}
                  onSaved={() => reload(`.${row.tld} saved.`)}
                />
              ))}
            </tbody>
          </table>
        </div>
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
    </>
  );
}

function price(prices: Record<string, number | null> | null, action: string, currency: string): string {
  const value = prices?.[action] ?? null;
  return value === null ? '—' : formatMinor(value, currency);
}

function ExtensionRowView({
  row,
  data,
  editing,
  onEdit,
  session,
  fetchImpl,
  onSaved,
}: {
  row: ExtensionRow;
  data: DomainExtensions;
  editing: boolean;
  onEdit: () => void;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onSaved: () => void;
}) {
  const cell = (action: string) =>
    (row.usd?.[action] ?? null) !== null ? (
      <>
        {price(row.usd, action, 'USD')}
        <br />
        <span className="pc-muted">{price(row.ngn, action, 'NGN')}</span>
      </>
    ) : (
      '—'
    );
  return (
    <>
      <tr>
        <td>
          .{row.tld}{' '}
          {row.featuredOrder !== null && <Badge tone="info">Featured {row.featuredOrder}</Badge>}{' '}
          {row.label !== 'none' && <Badge tone="warning">{LABELS[row.label] ?? row.label}</Badge>}
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
        {data.canManage && (
          <td>
            <Button variant="secondary" onClick={onEdit} aria-expanded={editing}>
              {editing ? 'Close' : 'Edit'}
            </Button>
          </td>
        )}
      </tr>
      {editing && (
        <tr>
          <td colSpan={8}>
            <ExtensionEditor row={row} data={data} session={session} fetchImpl={fetchImpl} onSaved={onSaved} />
          </td>
        </tr>
      )}
    </>
  );
}

function ExtensionEditor({
  row,
  data,
  session,
  fetchImpl,
  onSaved,
}: {
  row: ExtensionRow;
  data: DomainExtensions;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onSaved: () => void;
}) {
  const [pin, setPin] = useState(row.pinnedRegistrar ?? '');
  const [order, setOrder] = useState(row.featuredOrder === null ? '' : String(row.featuredOrder));
  const [label, setLabel] = useState(row.label);
  const [graceDays, setGraceDays] = useState(String(row.graceDays));
  const [graceFee, setGraceFee] = useState(minorToAmount(row.graceFee));
  const [redemptionDays, setRedemptionDays] = useState(String(row.redemptionDays));
  const [redemptionFee, setRedemptionFee] = useState(row.redemptionFee === null ? '' : minorToAmount(row.redemptionFee));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
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
      },
      fetchImpl,
    );
    setSaving(false);
    if (result.kind === 'ok') onSaved();
    else setError(failure(result, `.${row.tld} could not be saved.`));
  }

  return (
    <form className="pc-admin__form" onSubmit={(e) => void submit(e)} aria-label={`Edit .${row.tld}`}>
      <p className="pc-muted">
        Offers now:{' '}
        {row.offers.length === 0
          ? 'none with a fresh price list.'
          : row.offers.map((o) => `${REGISTRARS[o.registrar] ?? o.registrar} ${formatMinor(o.register, o.currency)}`).join(' · ')}
      </p>
      <div className="pc-field">
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
      <TextField label="Featured position" hint="1 shows first. Empty means not featured." inputMode="numeric" value={order} onChange={(e) => setOrder(e.target.value)} />
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
      <TextField label="Grace period (days)" inputMode="numeric" value={graceDays} onChange={(e) => setGraceDays(e.target.value)} />
      <TextField label="Grace fee (USD, added to renewal)" inputMode="decimal" value={graceFee} onChange={(e) => setGraceFee(e.target.value)} />
      <TextField label="Redemption period (days)" inputMode="numeric" value={redemptionDays} onChange={(e) => setRedemptionDays(e.target.value)} />
      <TextField
        label="Redemption fee (USD)"
        hint="Empty uses the registrar's restore price."
        inputMode="decimal"
        value={redemptionFee}
        onChange={(e) => setRedemptionFee(e.target.value)}
      />
      {error && <Alert tone="danger">{error}</Alert>}
      <Button type="submit" loading={saving}>
        Save .{row.tld}
      </Button>
    </form>
  );
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

  async function submit(event: FormEvent) {
    event.preventDefault();
    const markupBp = percentToBp(markup);
    const floorBp = percentToBp(floor);
    const usd = amountToMinor(usdStep);
    const ngn = amountToMinor(ngnStep);
    if (markupBp === null || floorBp === null || usd === null || ngn === null || usd < 1 || ngn < 1) {
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
    const result = await saveDomainPricing(session.csrfToken, { markup_bp: markupBp, min_margin_bp: floorBp, usd_step: usd, ngn_step: ngn, code }, fetchImpl);
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
      {data.canManage && !open && (
        <Button variant="secondary" onClick={() => setOpen(true)}>
          {p ? 'Change pricing settings' : 'Set pricing settings'}
        </Button>
      )}
      {open && (
        <form className="pc-admin__form" onSubmit={(e) => void submit(e)} aria-label="Pricing settings">
          <TextField label="Markup (%)" hint="Added to the registrar cost." inputMode="decimal" value={markup} onChange={(e) => setMarkup(e.target.value)} />
          <TextField label="Minimum margin (%)" hint="No price may fall below cost plus this; such an action stops being sold." inputMode="decimal" value={floor} onChange={(e) => setFloor(e.target.value)} />
          <TextField label="Round USD prices up to (USD)" inputMode="decimal" value={usdStep} onChange={(e) => setUsdStep(e.target.value)} />
          <TextField label="Round naira prices up to (NGN)" inputMode="decimal" value={ngnStep} onChange={(e) => setNgnStep(e.target.value)} />
          <TextField label="Authenticator code" autoComplete="one-time-code" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} />
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="pc-admin__row">
            <Button type="submit" loading={saving}>
              Save pricing settings
            </Button>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </Card>
  );
}
