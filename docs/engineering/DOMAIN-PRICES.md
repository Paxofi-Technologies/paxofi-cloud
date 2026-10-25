# Domain cost prices — runbook

How PaxofiCloud gets the cost of every domain extension from each registrar
(DomainNameAPI, Namecheap and Dynadot). Design: `docs/security/THREAT-MODEL-DOMAIN-REGISTRAR.md`,
`docs/security/THREAT-MODEL-DOMAIN-PRICING.md` (NC-1 to NC-9, DY-1 to DY-9) and CD-13 in
`docs/security/THREAT-MODEL-COMMERCE.md`.

## What runs, and when

- The `provider-worker` container checks about once a minute whether each
  registrar's daily sync is due. Each runs at **02:30 WAT** each day, once.
- DomainNameAPI always runs. Namecheap and Dynadot each run only once their connection has been
  entered under API providers → Domain registrars (so an unused registrar never raises alerts);
  the worker notices a new connection within a minute.
- Namecheap's price sheet is read as four calls (register, renew, transfer,
  reactivate = restore), each up to 16 MiB, parsed as a stream. Its cost for a
  term is the **higher** of `Price` and `YourPrice`, plus the ICANN fee; a
  promotional one-year price shows the status `promo` and never lowers the cost.
- Dynadot's list is read through its RESTful API (`GET /restful/v2/domains/get_tld_price`),
  never the legacy API3 (its key would sit in the URL). The key is the bearer token and every
  call is signed with the API secret (`X-Request-ID` + `X-Signature`). Pages are fetched one at
  a time about a second apart (Dynadot allows one request at a time); a 429 waits 2, 4 and 8
  seconds before giving up. The one-year cost is the first entry of each cumulative price array;
  a first year cheaper than the renewal shows `promo`. Dynadot sends **no restore price**, so
  restore through Dynadot is not sold until staff set it. `DYNADOT_ENVIRONMENT=sandbox` uses the
  sandbox (never in production).
- It reads the full extension list with one-year register, renew, transfer
  and restore costs, using the credential entered on the admin console's
  API providers → Domain registrars page.
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
| Credential refused | The registrar answered 401/403, Namecheap returned a global-parameter error (API user, key or **client IP not whitelisted**), or the stored credential is unusable. Not retried. One email per streak. Fix: re-enter the credential under API providers → Domain registrars; for Namecheap also check the whitelisted IP (it changes whenever the staging server is replaced). |
| Repeated failures | The third failed run in a row (rate limits, provider errors, network). Each run already retried up to 3 times (2 s, 8 s, 30 s). |
| Quarantined | Every quarantined list. Check the registrar's panel; the Domain costs card shows the held list's summary. A release flow for held lists comes with markup and repricing. |

## Commands (inside the provider worker)

| Command | What it does | Exit codes |
|---|---|---|
| `prices:sync [--provider=domainnameapi\|namecheap\|dynadot]` | Runs a sync now for one registrar (`trigger=cli`; default DomainNameAPI). Prints provider, outcome, reason and attempts. | 0 accepted, 1 failed, 2 quarantined, 3 another run in progress |
| `prices:status` | Per registrar: size of the newest accepted list and the last five runs. Codes, times and counts only. | 0 |

Settings (environment): `DOMAINNAMEAPI_ENVIRONMENT` = `live` (default) or
`ote`; `NAMECHEAP_ENVIRONMENT` = `live` (default) or `sandbox`. Production
refuses `ote` and `sandbox`. `REGISTRAR_WRITES=enabled` has an effect only in
production.

The Domain costs page has one tab per registrar; "Sync now" asks only the
registrar on screen.

## Spaceship price check (SP-3, SP-7)

Spaceship's API has no price list, so Spaceship is not part of the daily sync.
The **Spaceship** tab of Domain costs runs a price check instead: staff with
`catalogue.manage` press **Run price check** (at most once per 10 minutes,
audited), and the provider worker sends **one** availability request for a
made-up, random name (`paxofi-price-check-<16 hex>.<tld>`) in each of 20 common
extensions. Nothing is registered: the client has a single read operation and
the key has only `domains:read`. The button stays disabled until a Spaceship
connection exists.

The tab shows, per extension, Spaceship's answer, the one-year register price
if it showed one, and any other operations that carried a price. Test names
and Spaceship's text are never stored. The answer decides the next step:

- **Prices shown for ordinary names:** a daily automatic Spaceship sync can be
  built (SP-4).
- **No prices shown:** Spaceship becomes a fallback for a short, staff-chosen
  list of extensions with hand-entered costs that expire after 30 days (SP-5).

A failed check shows its reason; "the registrar refused the API key" usually
means the key lacks the `domains:read` scope.

| Command | What it does | Exit codes |
|---|---|---|
| `prices:probe-spaceship` | Runs the price check now (`trigger=cli`). Prints counts or the failure reason. | 0 done, 1 failed or not configured |
