import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, Checkbox, Modal, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import {
  fetchDomainSearchAdmin,
  saveDomainLookup,
  saveDomainSearchSettings,
  type DomainSearchAdminView,
  type DomainSearchLookup,
  type StaffSession,
} from './adminApi';

const MODES: Record<string, string> = {
  auto: 'Automatic (DNS, registrar, RDAP, WHOIS)',
  rdap: 'RDAP only',
  whois: 'WHOIS only',
  registrar: 'Registrar only',
};

function failure(result: ApiResult): string {
  if (result.kind === 'throttled') return `Too many attempts. Try again ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'rejected' && result.code === 'step_up_failed') return 'That authenticator code is not right. Use the newest code from your app.';
  if (result.kind === 'rejected' && result.code === 'not_on_sale') return 'One of those extensions is not on sale. Sync domain costs first, or remove it.';
  if (result.kind === 'rejected' && result.code === 'invalid') return 'Some values are not valid. Check them and try again.';
  return 'The change could not be saved. Try again.';
}

/** "get, my, try" → ["get", "my", "try"]. */
export function words(value: string): string[] {
  return value
    .split(/[\s,]+/)
    .map((w) => w.trim().toLowerCase())
    .filter((w) => w !== '');
}

/**
 * Domain search (THREAT-MODEL-DOMAIN-SEARCH.md DS-21 to DS-23): which
 * extensions every customer search checks, the name ideas, and how each
 * extension's availability is looked up. Text only.
 */
export function DomainSearchPage({ session, fetchImpl }: { session: StaffSession; fetchImpl?: typeof fetch | undefined }) {
  const [data, setData] = useState<DomainSearchAdminView | 'loading' | 'failed'>('loading');
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState<DomainSearchLookup | null>(null);

  useEffect(() => {
    let live = true;
    void fetchDomainSearchAdmin(fetchImpl).then((result) => {
      if (live) setData(result.kind === 'ok' ? result.data : 'failed');
    });
    return () => {
      live = false;
    };
  }, [fetchImpl, version]);

  if (data === 'loading') return <Card title="Domain search">Loading domain search settings…</Card>;
  if (data === 'failed')
    return (
      <Card title="Domain search">
        <Alert tone="danger">The domain search settings could not be loaded. Refresh to try again.</Alert>
      </Card>
    );

  return (
    <>
      <div className="pc-page-header">
        <div>
          <h1>Domain search</h1>
          <p className="pc-muted">
            What customers see when they search for a domain: the extensions checked with every search, the name ideas, and how each extension's availability is
            looked up. The list includes every extension on sale from all registrars.
          </p>
        </div>
      </div>
      {data.onSale === 0 && (
        <Alert tone="warning">
          No extension has a selling price yet, so customers see no prices and nothing can be chosen here. Enter the pricing settings on Domain
          extensions, and check that Domain costs shows a recent price list and accepted exchange rates.
        </Alert>
      )}
      <SettingsCard key={`s-${version}`} data={data} session={session} fetchImpl={fetchImpl} onSaved={() => setVersion((v) => v + 1)} />
      <Card title="How each extension is checked">
        <p className="pc-muted pc-small">
          Automatic tries DNS first (a name with nameservers is taken), then the registrar, then RDAP, then WHOIS. WHOIS servers are found from IANA unless you
          set one. Anything unclear shows as "Couldn't check", never as available, and checkout always confirms with the registrar.
        </p>
        <div className="pc-table-scroll">
          <table className="pc-table" aria-label="Lookup per extension">
            <thead>
              <tr>
                <th scope="col">Extension</th>
                <th scope="col">Checked by</th>
                <th scope="col">WHOIS server</th>
                <th scope="col">"No match" phrase</th>
                {data.canManage && (
                  <th scope="col">
                    <span className="pc-visually-hidden">Change</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {data.extensions.map((e) => (
                <tr key={e.tld}>
                  <th scope="row">
                    .{e.tld} {e.inSearch && <Badge tone="info">In search</Badge>}
                  </th>
                  <td>{MODES[e.mode] ?? e.mode}</td>
                  <td>{e.whoisServer ?? <span className="pc-muted">From IANA</span>}</td>
                  <td>{e.whoisPhrase ?? <span className="pc-muted">Built-in</span>}</td>
                  {data.canManage && (
                    <td>
                      <Button variant="secondary" size="sm" onClick={() => setEditing(e)} aria-label={`Change how .${e.tld} is checked`}>
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
      {data.canManage && (
        <Modal
          open={editing !== null}
          title={editing ? `How .${editing.tld} is checked` : 'Lookup'}
          description="A new WHOIS server needs your authenticator code."
          onClose={() => setEditing(null)}
        >
          {editing && (
            <LookupForm
              key={editing.tld}
              lookup={editing}
              modes={data.modes}
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

function SettingsCard({
  data,
  session,
  fetchImpl,
  onSaved,
}: {
  data: DomainSearchAdminView;
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onSaved: () => void;
}) {
  const [chosen, setChosen] = useState<string[]>(data.settings.searchTlds);
  const [filter, setFilter] = useState('');
  const [ideas, setIdeas] = useState(String(data.settings.maxIdeas));
  const [prefixes, setPrefixes] = useState(data.settings.prefixes.join(', '));
  const [suffixes, setSuffixes] = useState(data.settings.suffixes.join(', '));
  const [error, setError] = useState<string | undefined>();
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const readOnly = !data.canManage;
  const needle = filter.trim().toLowerCase().replace(/^\./, '');
  // Extensions with a selling price first; the rest are listed but cannot be chosen.
  const shown = data.extensions
    .filter((e) => needle === '' || e.tld.includes(needle))
    .sort((a, b) => Number(b.onSale) - Number(a.onSale) || a.tld.localeCompare(b.tld));

  function toggle(tld: string) {
    setSaved(false);
    setChosen((current) => (current.includes(tld) ? current.filter((t) => t !== tld) : current.length >= data.limits.searchTlds ? current : [...current, tld]));
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const maxIdeas = Number(ideas);
    if (!Number.isInteger(maxIdeas) || maxIdeas < 0 || maxIdeas > data.limits.maxIdeas) {
      setError(`Ideas shown must be a whole number from 0 to ${data.limits.maxIdeas}.`);
      return;
    }
    setBusy(true);
    setError(undefined);
    const result = await saveDomainSearchSettings(
      session.csrfToken,
      {
        search_tlds: chosen,
        max_ideas: maxIdeas,
        prefixes: words(prefixes).slice(0, data.limits.words),
        suffixes: words(suffixes).slice(0, data.limits.words),
      },
      fetchImpl,
    );
    setBusy(false);
    if (result.kind === 'ok') {
      setSaved(true);
      onSaved();
    } else setError(failure(result));
  }

  return (
    <Card title="Search extensions and name ideas">
      <form onSubmit={(e) => void save(e)} className="pc-stack" noValidate>
        <p className="pc-muted pc-small">
          Every search checks the extension the customer typed plus the ones ticked here, in the order you tick them (the first {data.limits.perSearch} are
          used). {data.chosen ? '' : 'Nothing has been chosen yet, so the featured extensions plus .ng and .com.ng are used.'}
        </p>
        <p>
          {data.onSale} of {data.extensions.length} extensions have a selling price. <strong>{chosen.length}</strong> of {data.limits.searchTlds} chosen
          {chosen.length > 0 ? `: ${chosen.map((t) => `.${t}`).join(', ')}` : ''}
        </p>
        {!readOnly && (
          <div className="pc-row">
            <TextField label="Find an extension" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder=".ng" autoComplete="off" />
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() =>
                setChosen(
                  data.extensions
                    .filter((e) => e.featured)
                    .map((e) => e.tld)
                    .slice(0, data.limits.searchTlds),
                )
              }
            >
              Use featured
            </Button>
            <Button type="button" variant="secondary" size="sm" onClick={() => setChosen([])}>
              Clear
            </Button>
          </div>
        )}
        <div className="pc-check-grid" role="group" aria-label="Search extensions">
          {shown.slice(0, 200).map((e) => (
            <Checkbox
              key={e.tld}
              label={e.onSale ? `.${e.tld}` : `.${e.tld} (not priced)`}
              checked={chosen.includes(e.tld)}
              disabled={readOnly || (!e.onSale && !chosen.includes(e.tld))}
              onChange={() => toggle(e.tld)}
            />
          ))}
        </div>
        {shown.length > 200 && <p className="pc-muted pc-small">Showing 200 of {shown.length}. Type to narrow the list.</p>}
        <TextField
          label="Name ideas shown"
          hint={`0 turns ideas off; at most ${data.limits.maxIdeas}. Only ideas confirmed available are shown.`}
          inputMode="numeric"
          value={ideas}
          disabled={readOnly}
          onChange={(e) => setIdeas(e.target.value)}
        />
        <TextField
          label="Words before the name"
          hint="Separated by commas, 2-12 letters or digits each, e.g. get, my, try"
          value={prefixes}
          disabled={readOnly}
          onChange={(e) => setPrefixes(e.target.value)}
        />
        <TextField
          label="Words after the name"
          hint="Separated by commas, e.g. hq, app, online, ng"
          value={suffixes}
          disabled={readOnly}
          onChange={(e) => setSuffixes(e.target.value)}
        />
        {error && <Alert tone="danger">{error}</Alert>}
        {saved && <Alert tone="success">Saved. New searches use these settings straight away.</Alert>}
        {!readOnly && (
          <div>
            <Button type="submit" loading={busy}>
              Save search settings
            </Button>
          </div>
        )}
      </form>
    </Card>
  );
}

function LookupForm({
  lookup,
  modes,
  session,
  fetchImpl,
  onCancel,
  onSaved,
}: {
  lookup: DomainSearchLookup;
  modes: string[];
  session: StaffSession;
  fetchImpl?: typeof fetch | undefined;
  onCancel: () => void;
  onSaved: () => void;
}) {
  const [mode, setMode] = useState(lookup.mode);
  const [server, setServer] = useState(lookup.whoisServer ?? '');
  const [phrase, setPhrase] = useState(lookup.whoisPhrase ?? '');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);
  const serverChanged = server.trim() !== '' && server.trim().toLowerCase() !== (lookup.whoisServer ?? '');

  async function save(event: FormEvent) {
    event.preventDefault();
    if (serverChanged && !/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    setBusy(true);
    setError(undefined);
    const body: {
      tld: string;
      mode: string;
      whois_server: string | null;
      whois_phrase: string | null;
      code?: string;
    } = {
      tld: lookup.tld,
      mode,
      whois_server: server.trim() === '' ? null : server.trim(),
      whois_phrase: phrase.trim() === '' ? null : phrase.trim(),
    };
    if (serverChanged) body.code = code;
    const result = await saveDomainLookup(session.csrfToken, body, fetchImpl);
    setBusy(false);
    if (result.kind === 'ok') onSaved();
    else setError(failure(result));
  }

  return (
    <form onSubmit={(e) => void save(e)} className="pc-stack" noValidate>
      <div className="pc-field">
        <label className="pc-field__label" htmlFor={`mode-${lookup.tld}`}>
          Checked by
        </label>
        <select id={`mode-${lookup.tld}`} className="pc-input" value={mode} onChange={(e) => setMode(e.target.value)}>
          {modes.map((m) => (
            <option key={m} value={m}>
              {MODES[m] ?? m}
            </option>
          ))}
        </select>
      </div>
      <TextField
        label="WHOIS server"
        hint="Leave empty to use the server IANA lists. A host name only, like whois.nic.net.ng; port 43 is always used."
        value={server}
        autoComplete="off"
        spellCheck={false}
        onChange={(e) => setServer(e.target.value)}
      />
      <TextField
        label='"No match" phrase'
        hint="Optional. Text at the start of a line in this registry's reply when a name is free, e.g. No Object Found. 3-100 characters."
        value={phrase}
        autoComplete="off"
        onChange={(e) => setPhrase(e.target.value)}
      />
      {serverChanged && (
        <TextField
          label="Authenticator code"
          hint="Needed to set a new WHOIS server."
          autoComplete="one-time-code"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
      )}
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="pc-row">
        <Button type="submit" loading={busy}>
          Save
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
