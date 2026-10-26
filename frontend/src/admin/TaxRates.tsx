import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Modal, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import { formatWhen } from '../lib/activity';
import { fetchTaxRates, saveTaxRate, type StaffSession, type TaxRates } from './adminApi';
import { percentToBp } from './DomainExtensions';

/** Basis points → "7.5%" without floating point. */
export function formatRate(bp: number): string {
  const fraction = bp % 100 === 0 ? '' : `.${String(bp % 100).padStart(2, '0').replace(/0$/, '')}`;
  return `${Math.trunc(bp / 100)}${fraction}%`;
}

/** A start time as a calendar date in UTC, e.g. "1 Jan 2027". */
function formatDay(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return 'Unknown date';
  return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(date);
}

function failure(result: ApiResult): string {
  if (result.kind === 'throttled') return `Too many attempts. Try again ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'rejected' && result.code === 'step_up_failed') return 'That authenticator code is not right. Use the newest code from your app.';
  if (result.kind === 'rejected' && result.code === 'start_in_past') return 'A start date must be after today. Choose "Now" to change the rate straight away.';
  if (result.kind === 'rejected' && result.code === 'duplicate_start') return 'This country already has a rate starting on that date. Pick another date.';
  if (result.kind === 'rejected' && result.code === 'invalid') return 'Some values are out of range. Check them and try again.';
  return 'The rate could not be saved. Try again.';
}

/**
 * Tax rates (commerce threat model CD-28): the rate in force per billing
 * country, scheduled changes and history. A change adds a new rate from now
 * or a future date and needs a fresh authenticator code; orders already
 * placed keep the tax they were charged. Text only.
 */
export function TaxRatesPage({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [data, setData] = useState<TaxRates | 'loading' | 'failed'>('loading');
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    void fetchTaxRates(fetchImpl).then((result) => {
      if (live) setData(result.kind === 'ok' ? result.data : 'failed');
    });
    return () => {
      live = false;
    };
  }, [fetchImpl, version]);

  if (data === 'loading') return <Card title="Tax rates">Loading tax rates…</Card>;
  if (data === 'failed') return <Card title="Tax rates"><Alert tone="danger">The tax rates could not be loaded. Refresh to try again.</Alert></Card>;

  return (
    <>
      <div className="pc-page-header">
        <div>
          <h1>Tax rates</h1>
          <p className="pc-muted">
            The tax added at checkout, by the country in the customer's billing address. Countries not listed use "Everyone else". A change adds a new rate
            from now or a future date; orders already placed keep the tax they were charged.
          </p>
        </div>
        {data.canManage && (
          <Button onClick={() => setEditing('')} aria-haspopup="dialog">
            Add or change a rate
          </Button>
        )}
      </div>
      <Card title="Rates in force">
        <div className="pc-table-scroll">
          <table className="pc-table" aria-label="Tax rates in force">
            <thead>
              <tr>
                <th scope="col">Country</th>
                <th scope="col">Tax</th>
                <th scope="col">Rate</th>
                <th scope="col">Since</th>
                <th scope="col">Scheduled change</th>
                {data.canManage && <th scope="col"><span className="pc-visually-hidden">Change</span></th>}
              </tr>
            </thead>
            <tbody>
              {data.countries.map((c) => (
                <tr key={c.country}>
                  <th scope="row">{c.countryName}</th>
                  <td>{c.current?.taxName ?? '—'}</td>
                  <td className="pc-num">{c.current ? formatRate(c.current.rateBp) : '—'}</td>
                  <td>{c.current ? formatDay(c.current.starts) : 'Not started yet'}</td>
                  <td>
                    {c.scheduled.length === 0
                      ? '—'
                      : c.scheduled.map((s) => (
                          <div key={s.starts}>
                            <Badge tone="info">{formatRate(s.rateBp)}</Badge> from {formatDay(s.starts)}
                          </div>
                        ))}
                  </td>
                  {data.canManage && (
                    <td>
                      <Button variant="secondary" onClick={() => setEditing(c.country)} aria-label={`Change the rate for ${c.countryName}`}>
                        Change
                      </Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <Card title="History">
        <div className="pc-table-scroll">
          <table className="pc-table" aria-label="Tax rate history">
            <thead>
              <tr>
                <th scope="col">Starts</th>
                <th scope="col">Country</th>
                <th scope="col">Tax</th>
                <th scope="col">Rate</th>
                <th scope="col">Added by</th>
              </tr>
            </thead>
            <tbody>
              {data.history.map((h) => (
                <tr key={`${h.country}-${h.starts}`}>
                  <td>{formatWhen(h.starts)}</td>
                  <td>{data.countries.find((c) => c.country === h.country)?.countryName ?? h.country}</td>
                  <td>{h.taxName}</td>
                  <td className="pc-num">{formatRate(h.rateBp)}</td>
                  <td>{h.addedBy === 'migration' ? 'Launch setting' : `Staff ${h.addedBy.slice(0, 8)}`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {data.canManage && (
        <Modal
          open={editing !== null}
          title="Add or change a tax rate"
          description="The new rate applies to orders placed from its start. Nothing already charged changes."
          onClose={() => setEditing(null)}
        >
          {editing !== null && (
            <RateForm
              key={editing}
              data={data}
              country={editing}
              session={session}
              fetchImpl={fetchImpl}
              onCancel={() => setEditing(null)}
              onSaved={() => {
                setEditing(null);
                setVersion((v) => v + 1);
              }}
            />
          )}
        </Modal>
      )}
    </>
  );
}

function RateForm({
  data,
  country: initialCountry,
  session,
  fetchImpl,
  onCancel,
  onSaved,
}: {
  data: TaxRates;
  country: string;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onSaved: () => void;
}) {
  const existing = data.countries.find((c) => c.country === initialCountry);
  const [country, setCountry] = useState(initialCountry || 'NG');
  const [taxName, setTaxName] = useState(existing?.current?.taxName ?? 'VAT');
  const [rate, setRate] = useState(existing?.current ? formatRate(existing.current.rateBp).replace('%', '') : '');
  const [when, setWhen] = useState<'now' | 'date'>('now');
  const [date, setDate] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const rateBp = percentToBp(rate);
    if (rateBp === null || rateBp > 10000) {
      setError('Enter the rate as a percentage from 0 to 100, like 7.5.');
      return;
    }
    if (taxName.trim() === '') {
      setError('Enter the tax name, like VAT.');
      return;
    }
    if (when === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      setError('Choose the date the new rate starts.');
      return;
    }
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    setSaving(true);
    setError(null);
    const result = await saveTaxRate(
      session.csrfToken,
      { country, tax_name: taxName.trim(), rate_bp: rateBp, starts: when === 'now' ? 'now' : date, code },
      fetchImpl,
    );
    setSaving(false);
    setCode('');
    if (result.kind === 'ok') onSaved();
    else setError(failure(result));
  }

  return (
    <form onSubmit={(e) => void submit(e)} aria-label="Tax rate">
      <fieldset className="pc-form-section">
        <legend>Rate</legend>
        <div className="pc-form-grid">
          <div className="pc-field pc-form-grid__wide">
            <label className="pc-field__label" htmlFor="tax-country">
              Billing country
            </label>
            <select id="tax-country" className="pc-input" value={country} onChange={(e) => setCountry(e.target.value)}>
              {data.countryOptions.map((o) => (
                <option key={o.code} value={o.code}>
                  {o.name}
                </option>
              ))}
            </select>
          </div>
          <TextField label="Tax name" hint="Shown on invoices, for example VAT." maxLength={40} value={taxName} onChange={(e) => setTaxName(e.target.value)} />
          <TextField label="Rate (%)" hint="For example 7.5. Use 0 for no tax." inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} />
        </div>
      </fieldset>
      <fieldset className="pc-form-section">
        <legend>Starts</legend>
        <div className="pc-field">
          <label className="pc-field__label" htmlFor="tax-when">
            When the new rate starts
          </label>
          <select id="tax-when" className="pc-input" value={when} onChange={(e) => setWhen(e.target.value === 'date' ? 'date' : 'now')}>
            <option value="now">Now</option>
            <option value="date">On a later date (midnight UTC)</option>
          </select>
        </div>
        {when === 'date' && <TextField label="Start date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />}
        <p className="pc-muted">Customers who are checking out when the rate changes see the new total before they pay.</p>
      </fieldset>
      <fieldset className="pc-form-section">
        <legend>Confirm</legend>
        <TextField label="Authenticator code" hint="From your authenticator app." autoComplete="one-time-code" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} />
      </fieldset>
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="pc-modal__actions">
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" loading={saving}>
          Save rate
        </Button>
      </div>
    </form>
  );
}
