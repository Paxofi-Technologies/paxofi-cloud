import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { StaffSession } from './adminApi';
import { CatalogueBackupCard } from './CatalogueBackup';

const session = { id: 's1', csrfToken: 'csrf' } as unknown as StaffSession;

describe('CatalogueBackupCard', () => {
  it('loads a settings file with a code and shows what was loaded and skipped', async () => {
    const user = userEvent.setup();
    const posted: unknown[] = [];
    const fetchImpl = vi.fn((_path: string, init?: RequestInit) => {
      posted.push(JSON.parse(String(init?.body)));
      return Promise.resolve(
        new Response(
          JSON.stringify({ pricing: true, extensions_saved: 2, extensions_skipped: [{ tld: 'xyz', reason: 'below_floor' }], search: true, lookups_saved: 1 }),
          { status: 200, headers: { 'content-type': 'application/json' } },
        ),
      );
    }) as unknown as typeof fetch;
    const onImported = vi.fn();
    render(<CatalogueBackupCard session={session} fetchImpl={fetchImpl} onImported={onImported} />);

    const file = new File([JSON.stringify({ format: 'paxoficloud.catalogue-settings', version: 1 })], 'settings.json', { type: 'application/json' });
    await user.upload(screen.getByLabelText('Settings file to load'), file);
    await user.click(screen.getByRole('button', { name: 'Load settings' }));
    expect(await screen.findByText(/6-digit code/)).toBeTruthy();
    expect(posted).toEqual([]);

    await user.type(screen.getByLabelText('Authenticator code to load the file'), '123456');
    await user.click(screen.getByRole('button', { name: 'Load settings' }));

    expect(await screen.findByText(/Loaded: pricing settings, 2 extension\(s\), search settings, 1 lookup\(s\)\. Skipped: \.xyz \(price below the floor\)\./)).toBeTruthy();
    expect(posted).toEqual([{ settings: { format: 'paxoficloud.catalogue-settings', version: 1 }, code: '123456' }]);
    expect(onImported).toHaveBeenCalled();
  });

  it('refuses a file that is not JSON without calling the server', async () => {
    const user = userEvent.setup();
    const fetchImpl = vi.fn() as unknown as typeof fetch;
    render(<CatalogueBackupCard session={session} fetchImpl={fetchImpl} onImported={() => undefined} />);

    await user.upload(screen.getByLabelText('Settings file to load'), new File(['not json'], 'x.json', { type: 'application/json' }));
    await user.type(screen.getByLabelText('Authenticator code to load the file'), '123456');
    await user.click(screen.getByRole('button', { name: 'Load settings' }));

    expect(await screen.findByText(/not valid JSON/)).toBeTruthy();
    expect(fetchImpl).not.toHaveBeenCalled();
  });
});
