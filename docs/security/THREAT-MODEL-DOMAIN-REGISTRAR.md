# Threat Model — Domain registrar adapter (DomainNameAPI) and domain cost sync

| | |
|---|---|
| Epics | INT-02 Registrar, BE-02 Pricing (provider cost sync) |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | PRC-011 (provider cost sync), PRC-012 (markup and repricing), DOM-001, DOM-003, SEC-004/005 (provider credentials), SEC-012 (outbound calls) |
| Standard | OWASP ASVS 5.0 — **Level 3** (provider credentials and money, CLAUDE.md §5) |
| Status | Written **before** its code (24 Oct 2026). Builds on THREAT-MODEL-COMMERCE.md §6.5 (CD-13 to CD-16: snapshots, quarantine, markup, repricing) and THREAT-MODEL-PROVIDER-CONNECTIONS.md (PC-1 to PC-8: how the credential gets in). |

## 1. Scope

DomainNameAPI is registrar #1. This model covers:

- **The registrar adapter**: how PaxofiCloud calls the DomainNameAPI REST API, for both reads (extension list and cost prices, availability, reseller balance, domain details) and writes (register, renew, transfer, name servers, contacts, locks, privacy).
- **The domain cost sync**: a worker job that pulls every extension DomainNameAPI sells with its cost prices and writes a cost snapshot (CD-13).
- **The staging write guard**: the CEO decided on 24 Oct 2026 that staging uses the **Live** API key, so staging sees real cost prices. Staging must therefore be unable to spend money or change real domains.

Out of scope: entering the credential (THREAT-MODEL-PROVIDER-CONNECTIONS.md), markup, repricing and auto-sell (CD-14, CD-15, CD-13), and the customer domain search and WHOIS/RDAP lookup (own model before that code). Registration, renewal and transfer flows (orders, payments, provisioning jobs) get their own sections when Sprint 4 builds them; this model fixes the adapter rules they must use.

## 2. What we know about the API (CO-6)

Source: DomainNameAPI's official PHP client `domainreseller/php-dna` v4.0.3 (22 Oct 2026), which says it is built on the REST gateway. The full Swagger reference is behind the reseller-panel login, so field-level details below are **inferred from the client's code**, not from a specification. The first real run on staging confirms them (residual R-1).

| Fact | Value |
|---|---|
| Transport | HTTPS, JSON |
| Live base URL | `https://api.domainresellerapi.com/api/v1` |
| Test (OTE) base URL | `https://ote.domainresellerapi.com/api/v1` |
| Authentication | Header `X-API-KEY: <api key>` and header `__reseller: <reseller id>`. No username or password. |
| Extension list with cost prices | `GET products/tlds?MaxResultCount=<n>&SkipCount=<offset>` (paged). Each item has `name`, `status`, `minRegistrationPeriod`, `maxRegistrationPeriod`, `constraints.minLength`, `constraints.maxLenght` (sic) and `prices` with `register`, `renew`, `transfer`, `restore` entries of `price`, `currency`, `period`. |
| Availability | `POST domains/bulk-search` |
| Reseller balance | `GET deposit/accounts/me` |
| Writes | e.g. `POST domains/register-with-contacts`, `POST domains/renew`, `POST domains/transfer`, `PUT domains/dns/name-server`, `PUT domains/contacts/update`, `POST domains/lock`, `POST domains/unlock`, `POST domains/privacy`, `POST/PUT/DELETE domains/dns/host` |

## 3. Assets

| Asset | Why it matters |
|---|---|
| Reseller API key and Reseller ID | Full control of the registrar account: registering, renewing and transferring domains spends the reseller balance; changing name servers or contacts can hijack a customer's domain |
| Reseller balance | Real money |
| Customer domains | Name servers, contacts and locks decide who controls a customer's website and email |
| Registrant contact data | Personal data under NDPA 2023 |
| Cost prices | Drive every domain selling price; wrong costs mean selling at a loss (C-25) |

## 4. Data flow and trust boundaries

```
provider-worker (KEK, data + egress networks; the only holder of the credential, PC-6)
   load credential domainnameapi-<env> → DomainNameApiClient
   ──(ProviderHttpClient: allow-listed host, own DNS + public-IP pinning, TLS 1.2+, no proxy, no redirects, timeouts, size caps)──▶ api.domainresellerapi.com
   ◀── JSON (untrusted) ── strict parse → cost snapshot (append-only) → quarantine check → markup/repricing (CD-14/15)
Admin console "Sync now" → queues a sync request row (catalogue.manage) → provider-worker picks it up
```

Boundaries:
1. The registrar's responses are **untrusted input**, even though the registrar is a supplier.
2. Only the provider worker holds the credential and talks to the registrar. The web tier never calls the registrar with the credential.
3. Staging is not production: it may read, it may not spend.

## 5. Design decisions

| ID | Decision |
|---|---|
| DR-1 | **Own adapter, not the vendor library.** `php-dna` sends error reports, and 4% of successful calls, to a third-party error tracker, including "the API request and response, including the domain's contact details", and looks up the server IP from a third-party service. That breaks NDPA data minimisation and our rule against uncontrolled external calls. We call the REST API directly through `ProviderHttpClient`. |
| DR-2 | **Base URL is fixed in code, chosen by setting.** `DOMAINNAMEAPI_ENVIRONMENT` = `live` or `ote`, default `live`. Production refuses `ote`. Every snapshot records which environment it came from, and prices from `ote` are never used to price the catalogue in production. |
| DR-3 | **Credential handling.** The stored secret is the JSON `{reseller_id, api_key}` (PR #97). It is opened only in the provider worker, validated (Reseller ID: letters, digits and `-`, up to 64; key: printable, no spaces, up to 256) and sent only in the two headers. `ProviderRequest` allows the `__reseller` header with a strict value pattern, so no header injection is possible. Secrets are `#[\SensitiveParameter]`, never logged, never in errors, never in audit events. |
| DR-4 | **Registrar write guard.** Every operation that can spend money or change a domain (register, renew, transfer, restore, name servers, child name servers, contacts, lock or unlock, privacy, zone and forwarding changes) is declared as a *write* and passes `RegistrarWriteGuard` **before any HTTP call**. Writes are allowed only in **production** and only when `REGISTRAR_WRITES=enabled`; in every other environment, including staging with the Live key (CEO decision 24 Oct 2026), the setting is ignored and writes are refused. A refused write is audited (`registrar.write_refused`) and fails closed. A coverage test lists every adapter method and fails if a write method does not pass the guard. |
| DR-5 | **Exact decimals.** Prices are read as decimal **text**: a small JSON reader returns numbers as strings, never floats. Prices with an exponent, a sign, more than 8 decimal places, or of one billion or more, are refused. Costs are converted to minor units by rounding **up** to the cent (or kobo), so a stored cost is never below the real one. |
| DR-6 | **Bounded paging.** Page size 100, at most 30 pages (3,000 extensions; DomainNameAPI advertises about 800). Paging stops at the first short page. Each response is capped at 1 MiB by the client. Going past the page limit stops the run as `too_many_pages` without writing a snapshot. |
| DR-7 | **Strict item validation.** An extension name must be a lower-case DNS label sequence (punycode allowed, e.g. `com.ng`, `xn--...`), up to 63 per label. `register` and `renew` prices are required; `transfer` and `restore` are optional. Currencies must be USD, EUR or NGN. Periods are whole years, 1 to 10. A bad item is skipped and counted with a fixed reason; it never stops the run. Duplicates are refused. |
| DR-8 | **Snapshot, quarantine and failure handling** follow CD-13: one append-only snapshot per successful run; quarantine when more than 20% of known extensions disappear or more than 20% change cost by more than 50%; a failed run writes nothing and keeps the last good prices. After 3 failed scheduled runs in a row, staff managers are alerted. |
| DR-9 | **Errors.** The adapter maps results to fixed reason codes (`unauthorised`, `rate_limited`, `provider_error`, `malformed`, `transport`). HTTP 401/403 means the credential is wrong or revoked: the run stops, staff are alerted, and it is not retried until the credential is replaced. 429 and 5xx get at most 3 retries with backoff (2 s, 8 s, 30 s). Bodies and headers are never logged. |
| DR-10 | **One sync at a time.** A Redis lock per provider (TTL 15 minutes) stops overlapping runs. "Sync now" is limited to one request per provider per 10 minutes and needs `catalogue.manage`; it is audited. The schedule is daily at 02:30 WAT. |
| DR-11 | **Egress.** Only the provider worker gets the egress network, and the client only allows the two DomainNameAPI hosts for this adapter. No redirects are followed. |
| DR-12 | **"Sync now" is a queued request, not a call.** The admin API never calls the registrar and never touches the credential. A staff member with `catalogue.manage` inserts a row into `provider_sync_requests` (provider, kind, who, when). The provider worker checks for unclaimed rows every few seconds, claims them all in one `UPDATE … WHERE picked_up_at IS NULL` (so two workers cannot both claim one), and runs the normal sync with trigger `manual`: the same lock, retries, quarantine and alerts as the schedule. Requests are limited to **one per provider per 10 minutes**, counted from the newest request, whoever made it. A refusal says when the next request is allowed. Every request and every refusal is audited (`provider_prices.sync_requested`, `provider_prices.sync_request_refused`). The runtime user may SELECT, INSERT and UPDATE that table (UPDATE only so the worker can mark a claim), never DELETE. |
| DR-13 | **Who sees costs.** The cost list and the run history are internal (commerce CD-19). They are served only on the admin host, under `/admin-api`, to staff with `catalogue.view` (Catalogue, Finance and Super Admin). Responses are `no-store` like every admin response. The customer API never exposes cost data. The list is the newest **accepted** snapshot; a newer quarantined snapshot is shown only as a summary (time, counts, reason), never as prices, so nobody prices from it by mistake. Amounts are integer minor units with their currency, never floats. |
| DR-14 | **Skipped items are recorded, not trusted.** For every item a sync skips (DR-7), the snapshot keeps the extension name, the fixed reason and a short detail (the currency code it was priced in, or the shortest registration period it offers), so staff can see what we are not selling and why. The name is kept only if it matches `[a-z0-9][a-z0-9.-]{0,126}` after lower-casing; the detail only if it matches `[A-Za-z0-9_:]{1,16}`; anything else is stored as empty. At most 1,000 skipped items per snapshot. Rows are append-only (SELECT/INSERT), shown only with the cost list (DR-13), and rendered as plain text. |
| DR-15 | **An unreadable optional price drops that price, not the extension.** `register` and `renew` stay required: if either is missing or unreadable the item is skipped. If `transfer` or `restore` is unreadable (zero, negative, exponent, wrong type, unknown or missing currency) or quoted in a different currency from `register`, that one price is stored as empty and the extension is still priced from register and renew. An empty transfer or restore price means that action is not sold online for the extension until a later sync reads a price, so the failure stays closed for that action. Every skip records which field failed and how, in the DR-14 detail: `<field>:<kind>` for prices (fields `reg`, `renew`; kinds `missing`, `zero`, `negative`, `format`, `type`, `entry`), `currency:<kind>` (`missing`, `type`, `format`) when there is no usable currency code, and `min:N_max:M` for unreadable periods (any value that is not one or two digits shown as `x`). |
| DR-16 | **Feed field inventory, names only.** To learn which fields the registrar sends (for example grace and redemption periods, CEO question 24 Oct 2026) without guessing, each sync records the field *paths* it saw across all items: top-level keys, `prices[].<type>` and `prices[].<type>.<key>`, three levels at most. Per path it keeps how many items had it, which kinds of value appeared (text, object, list, true/false, null) and, only when every value is a whole number of 1 to 5 digits, the smallest and largest. Text values are never stored. A key is kept only if it matches `[A-Za-z][A-Za-z0-9_]{0,47}`; anything else counts under `(other)`. At most 200 paths per snapshot. Rows are append-only, shown only with the cost list (DR-13) and rendered as text. The parser does not act on any new field until a later change, written against the recorded names, says how. |

## 6. Threats (STRIDE)

| ID | STRIDE | Threat | L | I | Mitigation | Test |
|---|---|---|---|---|---|---|
| DR-T1 | E | A staging bug or test registers, renews or transfers a real domain with the Live key and spends the reseller balance | 2 | 3 | DR-4 write guard, disabled on staging; checked before any HTTP call; audited | `RegistrarWriteGuardTest`, `RegistrarWriteCoverageTest` |
| DR-T2 | I | The vendor library leaks registrant contact data and request bodies to a third-party tracker | 3 | 3 | DR-1: vendor library not used | `composer.json` review (no `domainreseller/php-dna`) |
| DR-T3 | I | The API key or Reseller ID leaks through logs, exceptions or audit events | 2 | 3 | DR-3 sensitive parameters, fixed reason codes; tests scan logs and audit for the secret | `DomainNameApiClientTest` |
| DR-T4 | T | A malformed or hostile feed (huge, negative, zero, exponent, float rounding) mis-prices the catalogue | 2 | 3 | DR-5, DR-6, DR-7, DR-15; CD-13 quarantine; CD-14 margin floor; CD-15 approval above 10% | `DomainPriceFeedParserTest`, `PriceFeedQuarantineTest` |
| DR-T5 | S | DNS tampering or a redirect sends the credential to another host | 1 | 3 | `ProviderHttpClient`: own DNS, public-IP pinning, verified TLS, no redirects, fixed hosts | existing `ProviderHttpClientTest` |
| DR-T6 | T | A crafted Reseller ID injects extra HTTP headers | 1 | 2 | DR-3 value pattern in the catalogue and in `ProviderRequest` | `ProviderRequestTest` |
| DR-T7 | T | Test-environment prices end up in the production catalogue | 1 | 3 | DR-2: environment recorded per snapshot; production refuses `ote` | `DomainNameApiEnvironmentTest` |
| DR-T8 | D | Registrar outage or credential revoked; prices go stale | 2 | 2 | DR-8 keep last good snapshot; DR-9 stop on 401/403 and alert; alert after 3 failures | `DomainCostSyncTest` |
| DR-T9 | D | Runaway paging or retries hit the provider's rate limits or hang the worker | 2 | 1 | DR-6 page limit, DR-9 bounded retries, DR-10 lock and manual limit | `DomainCostSyncTest` |
| DR-T10 | R | Nobody can tell who triggered a sync or a refused write | 2 | 1 | DR-4, DR-10 audit events | `DomainCostSyncTest` |
| DR-T11 | I | Cost prices (our margins) leak to customers or to staff without a need, e.g. Support | 2 | 2 | DR-13: admin host only, `catalogue.view`, deny by default, denials audited; quarantined prices never listed | `AdminRouteCoverageTest`, `DomainCostAdminTest` |
| DR-T12 | D | Repeated "Sync now" clicks, or a stolen staff session, hammer the registrar and trip its rate limit, so the scheduled sync fails | 2 | 1 | DR-12: one request per provider per 10 minutes, claimed together, one lock; `catalogue.manage` only; audited | `DomainCostAdminTest`, `DomainCostSyncTest` |
| DR-T13 | E | The web tier is used to reach the registrar or the credential through "Sync now" | 1 | 3 | DR-12: the web tier only inserts a row; only the provider worker holds the KEK and egress (PC-6, DR-11) | `LayeringTest`; compose review |

## 7. Residual risks

| ID | Risk | Owner / action |
|---|---|---|
| R-1 | Response fields are inferred from the vendor client, not a published specification. If the real shape differs, the parser refuses items and the snapshot is empty or quarantined: no wrong price is published, but no prices arrive either. | Claude: run the first sync on staging and compare against the reseller panel's price list. |
| R-2 | Staging holds the Live key. If the write guard were bypassed, real money could be spent. | CEO: keep a small reseller balance until launch. Claude: writes are impossible outside production whatever the setting; coverage test proves every write operation is refused before the credential is loaded. |
| R-3 | The API key is not scoped: DomainNameAPI does not appear to offer read-only keys. | Re-check when DomainNameAPI documents key scopes; move staging to a read-only key if one appears. |

## 8. Review log

| Date | Change |
|---|---|
| 24 Oct 2026 | First version, before code. CEO decision: staging uses the Live key, read only. |
| 24 Oct 2026 | DR-12 "Sync now" request queue and DR-13 cost visibility, with DR-T11 to DR-T13, before the admin screen's code. |
| 24 Oct 2026 | DR-14 skipped-item details (the first live sync skipped 102 items), before code. |
| 24 Oct 2026 | DR-15 after the first DR-14 sync: 68 ccTLDs (.uk, .au, .jp, .tr, …) were skipped for one unreadable price; optional prices no longer block an extension, and every skip names the failing field, before code. |
| 24 Oct 2026 | DR-16 feed field inventory (grace/redemption periods not synced; record field names and whole-number ranges, never text values), before code. |
| 24 Oct 2026 | Namecheap (registrar #2), registrar choice, USD base, loss guard and grace/redemption are modelled in THREAT-MODEL-DOMAIN-PRICING.md (NC-1 to NC-9, DX-1 to DX-13); its rules extend DR-4, DR-7, DR-12 and DR-14 to DR-16 to every registrar. |
