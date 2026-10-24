# Domain cost prices — runbook

How PaxofiCloud gets the cost of every domain extension from the registrar
(DomainNameAPI today). Design: `docs/security/THREAT-MODEL-DOMAIN-REGISTRAR.md`
and CD-13 in `docs/security/THREAT-MODEL-COMMERCE.md`.

## What runs, and when

- The `provider-worker` container checks about once a minute whether the
  daily sync is due. It runs at **02:30 WAT** each day, once.
- It reads the full extension list with one-year register, renew, transfer
  and restore costs, using the credential entered on the admin console's
  Provider connections page.
- Every result is stored as a **snapshot**. Snapshots and run records are
  append-only: nothing is edited or deleted.
- A plausible list is **accepted** and becomes the current cost list.
- An implausible list is **quarantined** and changes nothing: the list is
  empty, more than 20% of extensions disappeared, or more than 20% of
  extensions changed register or renew cost by more than 50%.
- A failed run stores nothing; the last accepted costs stay in use.

Staging uses the Live API key (CEO decision, 24 Oct 2026) but can only read:
every registrar call that spends money or changes a domain is refused outside
production (DR-4).

## Admin console: Domain costs (DR-12, DR-13)

The **Domain costs** card in the staff console shows the current cost of every
extension (one year; register, renew, transfer, restore, allowed years), when
it was fetched, a summary of any held-back (quarantined) list, and the last 10
runs. It is internal: only staff with `catalogue.view` (Catalogue, Finance,
Super Admin) see it, and only on the admin host.

**Sync now** (Catalogue and Super Admin, `catalogue.manage`) asks the provider
worker for a fresh list. The page never talks to the registrar itself: it adds a
request, the worker picks it up within a few seconds and runs the same sync as
the daily one (`trigger=manual`), and the card refreshes itself until the
request is picked up. Requests are limited to one per provider every 10
minutes, whoever asks; the page says when the next one is allowed. Every
request and refusal is in the audit log.

**Not on sale yet** lists the extensions the registrar sent that the last
accepted sync could not price, grouped by reason (DR-14): for example a price
in a currency we do not accept yet (the currency code is shown), or no
one-year price (the shortest term on offer is shown). Each group also says
which field failed and how (DR-15), e.g. "registration price is 0", "no
currency given" or "minimum 1, maximum 0 years". Each sync replaces this
list; nothing on it is sold until a later sync can read it.

Only the registration and renewal prices are required. An unreadable transfer
or restore price (or one in a different currency) is left empty and the
extension is still priced; the cost list shows "—" for it, and that action is
not sold online for the extension until a later sync reads a price (DR-15).

**Fields the registrar sends** lists every field name the last price list
contained, how many extensions had it, the kinds of value seen and, for
whole numbers, their range (DR-16). Text values are never stored. Use it to
check what the registrar provides (for example grace or redemption periods)
before any change relies on a field.

If a request stays "on its way" for more than a minute, the provider worker
is not running: run the Staging Deploy `status` action.

## Alerts

Every active staff manager is emailed ("Price sync needs attention") when:

| Problem | When |
|---|---|
| Credential refused | The registrar answered 401/403 or the stored credential is unusable. Not retried. One email per streak. Fix: re-enter the credential on Provider connections. |
| Repeated failures | The third failed run in a row (rate limits, provider errors, network). Each run already retried up to 3 times (2 s, 8 s, 30 s). |
| Quarantined | Every quarantined list. Check the registrar's panel; the Domain costs card shows the held list's summary. A release flow for held lists comes with markup and repricing. |

## Commands (inside the provider worker)

| Command | What it does | Exit codes |
|---|---|---|
| `prices:sync` | Runs a sync now (`trigger=cli`). Prints provider, outcome, reason and attempts. | 0 accepted, 1 failed, 2 quarantined, 3 another run in progress |
| `prices:status` | Size of the newest accepted list and the last five runs. Codes, times and counts only. | 0 |

Settings (environment): `DOMAINNAMEAPI_ENVIRONMENT` = `live` (default) or
`ote`; production refuses `ote`. `REGISTRAR_WRITES=enabled` has an effect only
in production.
