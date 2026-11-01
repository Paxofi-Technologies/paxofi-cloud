import { useState, type FormEvent } from 'react';
import { Alert, Button, Card, TextField } from '../components';
import { describeWait, type ApiResult } from '../lib/api';
import { exportCatalogueSettings, importCatalogueSettings, readImportSummary, type CatalogueImportSummary, type StaffSession } from './adminApi';

const MAX_FILE_BYTES = 900 * 1024;

function failure(result: ApiResult): string {
  if (result.kind === 'throttled') return `Too many attempts. Try again ${describeWait(result.retryAfterSeconds)}.`;
  if (result.kind === 'rejected' && result.code === 'step_up_failed') return 'That authenticator code is not right. Use the newest code from your app.';
  if (result.kind === 'rejected' && result.code === 'invalid') return 'That file is not a PaxofiCloud catalogue settings file, or part of it is not valid. Nothing was changed.';
  return 'The settings could not be imported. Nothing was changed.';
}

/**
 * Catalogue settings backup (THREAT-MODEL-DOMAIN-PRICING.md DX-15): download
 * the pricing settings, extension settings, search settings and lookups as a
 * file, and load them back after a rebuild. The file holds our margins, so
 * keep it private. Import needs a fresh authenticator code.
 */
export function CatalogueBackupCard({ session, fetchImpl, onImported }: { session: StaffSession; fetchImpl?: typeof fetch | undefined; onImported: () => void }) {
  const [busy, setBusy] = useState<'export' | 'import' | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [summary, setSummary] = useState<CatalogueImportSummary | null>(null);

  async function download() {
    setBusy('export');
    setError(undefined);
    const text = await exportCatalogueSettings(fetchImpl);
    setBusy(null);
    if (text === null) {
      setError('The settings could not be exported. Try again.');
      return;
    }
    const url = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `paxoficloud-catalogue-settings-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
  }

  async function upload(event: FormEvent) {
    event.preventDefault();
    setSummary(null);
    if (!file) {
      setError('Choose the settings file first.');
      return;
    }
    if (file.size > MAX_FILE_BYTES) {
      setError('That file is too large to be a catalogue settings file.');
      return;
    }
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    let settings: unknown;
    try {
      settings = JSON.parse(await file.text());
    } catch {
      setError('That file is not valid JSON. Nothing was changed.');
      return;
    }
    setBusy('import');
    setError(undefined);
    const result = await importCatalogueSettings(session.csrfToken, settings, code, fetchImpl);
    setBusy(null);
    if (result.kind === 'ok') {
      setSummary(readImportSummary(result.data));
      setCode('');
      onImported();
    } else setError(failure(result));
  }

  return (
    <Card title="Back up catalogue settings">
      <p className="pc-muted">
        Download the pricing settings, every extension's settings (pin, featured order, label, category, grace, redemption, price overrides), the domain search
        settings and lookups as one file, and load it back after a rebuild. No costs, credentials or customer data are included. The file holds your margins:
        keep it private.
      </p>
      <div>
        <Button type="button" variant="secondary" loading={busy === 'export'} onClick={() => void download()}>
          Download settings file
        </Button>
      </div>
      <form onSubmit={(e) => void upload(e)} className="pc-stack" noValidate>
        <div className="pc-field">
          <label className="pc-field__label" htmlFor="catalogue-settings-file">
            Settings file to load
          </label>
          <input
            id="catalogue-settings-file"
            className="pc-input"
            type="file"
            accept="application/json,.json"
            onChange={(e) => {
              setFile(e.target.files?.[0] ?? null);
              setSummary(null);
            }}
          />
        </div>
        <TextField
          label="Authenticator code to load the file"
          hint="Loading replaces the pricing settings and the listed extensions' settings."
          autoComplete="one-time-code"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
        {error && <Alert tone="danger">{error}</Alert>}
        {summary && (
          <Alert tone={summary.extensionsSkipped.length > 0 ? 'warning' : 'success'}>
            Loaded: {summary.pricing ? 'pricing settings, ' : ''}
            {summary.extensionsSaved} extension(s){summary.search ? ', search settings' : ''}, {summary.lookupsSaved} lookup(s).
            {summary.extensionsSkipped.length > 0 &&
              ` Skipped: ${summary.extensionsSkipped.map((s) => `.${s.tld} (${s.reason === 'below_floor' ? 'price below the floor' : s.reason})`).join(', ')}.`}
          </Alert>
        )}
        <div>
          <Button type="submit" loading={busy === 'import'}>
            Load settings
          </Button>
        </div>
      </form>
    </Card>
  );
}
