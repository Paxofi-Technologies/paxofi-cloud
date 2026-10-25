# Threat Model — Multi-registrar domain pricing (Namecheap, registrar choice, USD base, loss guard, grace and redemption)

| | |
|---|---|
| Epics | INT-02 Registrar, BE-02 Pricing, FE admin console (Domain extensions) |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | PRC-002, PRC-011, PRC-012, PRC-013, DOM-001, DOM-003; new in change record CR-004: PRC-014 (USD base and price lock), PRC-015 (loss guard), DOM-018 (registrar choice), DOM-019 (grace and redemption), DOM-020 (featured and labels), DOM-021 (Namecheap) |
| Standard | OWASP ASVS 5.0 — **Level 3** (money and provider credentials, CLAUDE.md §5) |
| Status | Written **before** its code (24 Oct 2026). Builds on THREAT-MODEL-COMMERCE.md §6.5 (CD-13 to CD-19), THREAT-MODEL-DOMAIN-REGISTRAR.md (DR-1 to DR-16) and THREAT-MODEL-PROVIDER-CONNECTIONS.md (PC-1 to PC-8). |

## 1. Scope

The CEO asked on 24 Oct 2026 for: Namecheap as registrar #2 now (ConnectReseller after it); one way to manage extensions that several registrars sell; grace and redemption periods with fees for each extension; and a currency model in which no money is lost to exchange rates. CEO decisions the same day:

| Decision | Choice |
|---|---|
| Base currency | **USD** for domains and servers (what registrars and server providers charge us). NGN prices are derived from the daily rate. |
| Registrar for new registrations | **The cheapest healthy registrar by default; staff can pin one per extension.** |
| Namecheap on staging | **Live account, read only** (prices only), like DomainNameAPI (DR-4). |
| Order of work | This model and CR-004, then the Namecheap price sync, then the Domain extensions page, then currencies and the loss guard. |

This model covers:

- **The Namecheap price adapter** (§2, NC-1 to NC-9).
- **Registrar offers and registrar choice** (DX-2 to DX-4).
- **One selling price per extension, USD base, NGN derived, price lock at order time** (DX-1, DX-5, DX-6).
- **The loss guard** before any registrar spend (DX-7).
- **Customer account currency** (DX-8).
- **Grace and redemption periods and fees** (DX-9).
- **Featured extensions and labels** (DX-10), permissions, audit and visibility (DX-11 to DX-13).

Out of scope: registration, renewal and transfer jobs themselves (Sprint 4 provisioning model; they must call the DX-7 guard), the customer domain search and WHOIS/RDAP (own model), and the ConnectReseller adapter (it follows NC-1 to NC-9 with its own facts section, added before its code).

## 2. What we know about the Namecheap API

Namecheap's documentation site refuses automated reads, so these facts come from **Namecheap's official Go SDK** (`github.com/namecheap/go-namecheap-sdk`, which carries the API documentation and a recorded `getPricing` response). The first real run on staging confirms them (residual R-1).

| Fact | Value |
|---|---|
| Transport | HTTPS, XML response. Requests are `POST` with a form-encoded body (the SDK sends every call this way). |
| Production URL | `https://api.namecheap.com/xml.response` |
| Sandbox URL | `https://api.sandbox.namecheap.com/xml.response` (separate account) |
| Authentication | `ApiUser`, `ApiKey`, `UserName` and `ClientIp` on every call. `ClientIp` is the IPv4 address of the calling server, and that address must be **whitelisted** in the Namecheap account. |
| Price list | `Command=namecheap.users.getPricing`, `ProductType=DOMAIN`; optional `ActionName` (`REGISTER`, `RENEW`, `TRANSFER`, `REACTIVATE`), `ProductName`, `ProductCategory`, `PromotionCode`. |
| Response shape | `ApiResponse[@Status]` → `Errors/Error[@Number]` → `CommandResponse/UserGetPricingResult/ProductType[@Name="domains"]/ProductCategory[@Name]/Product[@Name]/Price[@Duration, @DurationType, @Price, @RegularPrice, @YourPrice, @Currency, @PromotionPrice, …]`. Category names arrive in lower case (`register`, `renew`, `transfer`, `reactivate`). |
| Price meaning | `Price` is the server-resolved price (regular, account, special, promo or tier). `YourPrice` is the account price. `RegularPrice` is the public list price. A blank or `0.0` attribute means "not set". |
| Size | The full domain price sheet is large (about 1,500 extensions × several terms × 4 actions). The SDK advises fetching it once and caching it. |

## 3. Assets

| Asset | Why it matters |
|---|---|
| Namecheap API key, API user, user name | Full control of the Namecheap account and its balance; can register, renew and transfer domains and change their name servers |
| Registrar balances | Real money at each registrar |
| Cost prices from each registrar | Drive every selling price; a wrong or too-low cost means selling at a loss |
| Selling prices, overrides, registrar pins, grace and redemption fees | Decide what the customer pays and which registrar spends our money |
| Locked order prices and exchange-rate records | Evidence of what the customer agreed to pay; the base of the ledger |

## 4. Data flow and trust boundaries

```
provider-worker (KEK + egress; sole holder of registrar credentials, PC-6)
   ├─ DomainNameAPI adapter ──▶ api.domainresellerapi.com  (JSON, DR-1..DR-16)
   └─ Namecheap adapter     ──▶ api.namecheap.com          (XML, NC-1..NC-9)
        ▼ untrusted responses → strict parse → per-registrar cost snapshot (append-only, CD-13 quarantine)
   registrar offers (latest accepted snapshot per registrar) ─▶ registrar choice (DX-3) ─▶ derived USD price (DX-5)
                                                     daily FX set (CD-16) ─▶ derived NGN price (DX-5)
admin console (catalogue.manage): pins, overrides, grace/redemption, featured, labels — audited, never touches credentials
checkout: price + currency + FX set + cost basis locked on the order line (DX-6)
provisioning (Sprint 4): loss guard re-reads the holding/target registrar's cost before any spend (DX-7)
```

Boundaries:
1. Every registrar response is **untrusted input**.
2. Only the provider worker holds registrar credentials and talks to registrars (PC-6, DR-11).
3. Staging may read prices, never spend (DR-4).
4. Customers see selling prices only; costs, margins and pins are internal (CD-19, DR-13).

## 5. Design decisions

### 5.1 Namecheap adapter

| ID | Decision |
|---|---|
| NC-1 | **Own adapter, no SDK.** We call the XML API directly through `ProviderHttpClient`, like DR-1. No third-party library, no third-party IP lookup. |
| NC-2 | **Fixed endpoints.** `NAMECHEAP_ENVIRONMENT` = `live` or `sandbox`, default `live`. Production refuses `sandbox`. Each snapshot records its environment; sandbox prices never price the production catalogue. Only these two hosts are allow-listed for the adapter. |
| NC-3 | **Credential.** Stored as JSON `{api_user, username, api_key, client_ip}` through Provider connections (PC-1 to PC-8), opened only in the provider worker. Validation: `api_user` and `username` match `[A-Za-z0-9_.-]{1,64}`; `api_key` is printable with no spaces, up to 128 characters; `client_ip` must be a **public** IPv4 address (the staging or production egress address that the CEO whitelists in Namecheap). Values go **only in the POST body**, never in the URL, so they cannot land in access logs. They are sensitive parameters: never logged, never in errors or audit events. |
| NC-4 | **Safe XML.** The response is read with a streaming `XMLReader` and `LIBXML_NONET`; entity substitution and DTD loading are never enabled, and a response that contains a `DOCTYPE` is refused. Only the known elements and attributes are read; everything else is ignored. Depth is capped at 8. Attribute values are text and go through the DR-5 decimal rules (no floats, no exponent, at most 8 decimals, below one billion, rounded **up** to the cent). |
| NC-5 | **One call per action, bounded size.** The sheet is fetched as four calls, `ActionName` = `REGISTER`, `RENEW`, `TRANSFER`, `REACTIVATE`, each capped at **16 MiB** (a per-endpoint cap for this host only; every other provider keeps 1 MiB) and parsed as a stream, so memory stays flat. At most 5,000 products per call. `REGISTER` and `RENEW` are required for an extension; `TRANSFER` and `REACTIVATE` are optional (DR-15 rules). `REACTIVATE` is Namecheap's restore (redemption) price. |
| NC-6 | **Which number is our cost.** Money safety first: the cost of a term is the **higher** of the positive `Price` and `YourPrice` values, plus the tier's additional cost (`AdditionalCost`, the ICANN fee) when present and positive. `RegularPrice` is used only when neither is positive. A blank or zero everywhere skips that price with a DR-15 reason. A positive `PromotionPrice` is recorded as a flag (`promo`) so staff can see that a cost is promotional; we never price below the non-promotional cost because of a promotion we might lose before the customer renews. Category and product names are matched case-insensitively and normalised to lower case. |
| NC-7 | **Terms.** Only `DurationType="YEAR"`, durations 1 to 10. The one-year cost of each action is used now; all terms are stored for multi-year pricing later (the `.ai`/`.gr` case). Currency must be USD, EUR or NGN (CostCurrency); one currency per extension (DR-7). |
| NC-8 | **Errors.** `Status="ERROR"` maps to fixed reason codes, never the message text: an authentication or "IP not allowed" error is `unauthorised` (the run stops, staff are alerted once per streak, no retry until the connection is replaced; the alert tells staff to check the whitelisted IP), throttling is `rate_limited`, anything else `provider_error`. HTTP 429/5xx get at most 3 retries (2 s, 8 s, 30 s), DR-9. A run makes 4 calls, well inside Namecheap's published limits. |
| NC-9 | **Reuse, not copy.** The Namecheap feed writes into the same cost snapshot store, quarantine (CD-13), skipped-item and field-inventory records (DR-14, DR-16), schedule (daily 02:30 WAT), "Sync now" queue (DR-12) and write guard (DR-4) as DomainNameAPI, keyed by provider. |

### 5.2 Extensions, registrars and money

| ID | Decision |
|---|---|
| DX-1 | **Two currencies, never mixed.** Costs stay in the currency the registrar bills (USD for both registrars today). The **base currency for domains and servers is USD** (CEO decision). Comparisons between registrars convert each cost to USD with the current accepted FX set (CD-16), using bcmath and rounding up. No float ever touches money (PRC-001). |
| DX-2 | **Registrar offers.** An *offer* is one registrar's latest **accepted** cost snapshot entry for an extension. An offer is *healthy* when its registrar's latest accepted snapshot is under 72 hours old and its connection is not in a refused state. Quarantined or stale snapshots produce no healthy offers. |
| DX-3 | **Registrar choice.** For new registrations, the chosen registrar is the healthy offer with the lowest one-year `register` cost in USD; ties go to the registrar ranked first (CEO ranking, 24 Oct 2026: DomainNameAPI, Namecheap, Dynadot, Spaceship; registrars whose costs are entered by hand rank last). Staff with `catalogue.manage` may **pin** a registrar per extension (audited). If a pinned registrar is not healthy, the choice falls back to the cheapest healthy offer and the extension is flagged "pin not available". A choice change affects **new registrations only**: every registered domain records its holding registrar, and renewal, restore and transfer-out always go to the holder. |
| DX-4 | **Failover and the floor.** The failover order is the chosen registrar, then the other healthy offers by cost. A registrar may be used for an order (first choice or failover) only if its cost keeps the locked selling price above the margin floor (CD-14); otherwise it is skipped for that extension and the extension is flagged. The floor therefore holds whichever registrar ends up doing the work. |
| DX-5 | **One selling price per extension, action, term and currency.** The USD price is derived from the chosen registrar's cost (markup, rounding, floor: CD-14) and repriced under CD-15. The NGN price is derived from the USD price with the current FX set (CD-16) and rounded **up** to the NGN step. Staff may override any single price (audited, future-dated, PRC-005). An override below the floor is refused when saved. If a later cost or rate change pushes a live price (derived or overridden) below the floor, **that action stops being sold online** for the extension until staff fix it or the next derivation clears it, and staff are alerted. It fails closed. |
| DX-6 | **Price lock.** An order line stores the price, its currency, the FX set id, the registrar chosen and the cost basis (registrar cost in its own currency) at the moment the customer sees the checkout total. Payment never re-converts: the customer pays exactly the locked amount in their account currency. A quote is valid for 30 minutes; after that checkout re-quotes and shows the new total before payment. |
| DX-7 | **Loss guard before any registrar spend.** Before a register, renew, transfer or restore call, the provisioning job reads the target registrar's latest accepted cost for that action, converts it into the order currency with the current FX set, and compares it with the amount paid for that line (net of tax). If the cost is higher, or no accepted cost exists, the job is **held for review** (`provisioning.loss_hold`, audited, staff alerted) and the registrar is not called. Staff may approve a held job (`provisioning.job.manage`, audited with the shortfall) or refund. The guard runs after the write guard (DR-4) and never auto-proceeds. |
| DX-8 | **Customer account currency.** NGN or USD, chosen at sign-up (pre-filled NGN when the billing country is Nigeria, otherwise USD). It can change only when the account has no unpaid invoice and no credit balance; the change is audited. Invoices, renewals and credit are always in the account currency. |
| DX-9 | **Grace and redemption per extension.** Each extension has grace days (0–90), a grace fee (0 or more, added to the renew price during grace), redemption days (0–90) and a redemption fee. The redemption fee is derived from the holding registrar's `restore`/`reactivate` cost under DX-5 rules, or set by staff; each value records its source (`registrar` or `staff`). Defaults when no source exists: grace 30 days, grace fee 0, redemption 30 days. If no restore cost and no staff price exist, restore is **not sold online** for that extension (fails closed). Because grace lengths differ by registrar, a renewal or restore always goes through the DX-7 guard, which catches a domain that has moved into the more expensive state. Renewal and restore prices shown to a customer are those of the domain's **holding** registrar. |
| DX-10 | **Featured and labels.** Each extension has `featured` (with an order; CR-003 seeds .ng, .com.ng, .com, .org, .site) and a label from a fixed list: `none`, `hot`, `new`, `sale`. Stored as enums, rendered as text, exposed in the public catalogue. |
| DX-11 | **Permissions.** Costs, offers and margins: `catalogue.view`. Pins, overrides, grace and redemption, featured and labels: `catalogue.manage`. Base currency, FX settings and the margin floor: `settings.manage`. Approving a held job: `provisioning.job.manage`. Deny by default; every route is pinned in `AdminRouteCoverageTest`. *Amended 25 Oct 2026:* `settings.manage` is held only by Super Admin, which a sole founder cannot be granted (SEC-017 needs a second approver), so the domain pricing settings (markup, minimum margin, rounding steps) need `catalogue.manage` **plus a fresh authenticator code**, at most 5 tries per 15 minutes, audited. Margins are entered in the admin console only and never committed (the repository is public). |
| DX-12 | **Audit.** Every pin, override, grace or redemption change, featured or label change, account-currency change, loss hold and approval is audited with before and after values (integer minor units and currency codes only). *Amended 25 Oct 2026:* an audit event holds at most 8 short fields, so each change records the full state **after** it (pin, featured position, label, grace days.fee, redemption days.fee; or markup, margin, steps). The audit log is append-only and hash-chained, so the previous event for the same extension (or of the same type) is the state before. |
| DX-14 | **Staff prices per extension.** A Catalogue staff member may set their own one-year price for register, renew and transfer, in USD and/or NGN (integer minor units, 1 to 10^10; restore keeps the markup price). A USD price without an NGN price converts as usual. On save, each price must be at or above the floor (cost of the registrar that would take the order plus the minimum margin, in that currency at today's rate) and the action must be sold; otherwise the save is refused (`below_floor`). At every quote the floor is checked again: a price that is now below it is not charged, the markup price is used instead, and the admin page shows "Custom price paused". Saved with the extension settings, audited (state after, two short codes). |
| DX-13 | **Visibility.** Customers see selling prices, grace and redemption days and fees, featured flags and labels. They never see costs, registrar names, offers, pins or margins (CD-19). |

## 6. Threats (STRIDE)

| ID | STRIDE | Threat | L | I | Mitigation | Test evidence |
|---|---|---|---|---|---|---|
| DX-T1 | E | A staging bug spends real money at Namecheap with the live key | 2 | 3 | DR-4 write guard covers every Namecheap write; refused before the credential loads | `RegistrarWriteCoverageTest` extended to the Namecheap client |
| DX-T2 | I | The Namecheap API key leaks through a URL in a log, an error or an audit event | 2 | 3 | NC-3 POST body only, sensitive parameters, fixed reason codes | `NamecheapClientTest` (key absent from URL, logs, exceptions) |
| DX-T3 | T | Hostile XML (entity expansion, external entities, DOCTYPE, deep nesting, huge size) exhausts memory or reads local files | 2 | 3 | NC-4 streaming reader, no entities, DOCTYPE refused, depth cap; NC-5 16 MiB cap, 5,000 products | `NamecheapPriceParserTest` (billion laughs, XXE, depth, size) |
| DX-T4 | T | A promotional or zero price becomes our cost and we sell below what we later pay | 3 | 3 | NC-6 higher of Price and YourPrice, promo flag; DX-5 floor and stop-selling; DX-7 loss guard | `NamecheapPriceParserTest`, `ExtensionPricingTest`, `LossGuardTest` |
| DX-T5 | T | An exchange-rate move turns a naira price into a loss | 3 | 3 | DX-1 rounding up, CD-16 hold and hysteresis, DX-5 floor recheck after each FX update, DX-6 price lock, DX-7 guard | `ExtensionPricingTest`, `LossGuardTest` |
| DX-T6 | T | The failover registrar costs more than the price we charged | 2 | 3 | DX-4 failover only within the floor; DX-7 guard per target registrar | `RegistrarChoiceTest`, `LossGuardTest` |
| DX-T7 | T | A domain's renewal or restore is sent to the wrong registrar after the chosen registrar changes | 2 | 3 | DX-3 holding registrar recorded per domain; renew/restore/transfer-out only to the holder | `RegistrarChoiceTest` |
| DX-T8 | E | A staff member without the right permission pins a registrar, overrides a price or changes grace fees | 2 | 2 | DX-11 permissions, deny by default, route coverage test, audited denials | `AdminRouteCoverageTest`, `ExtensionAdminTest` |
| DX-T10 | T | A staff price, or one made stale by a cost or rate rise, sells a domain below cost | 3 | 3 | DX-14 floor on save and at every quote, markup price as the fallback, paused flag | `ExtensionPricesTest`, `ExtensionsAdminTest` |
| DX-T9 | R | Nobody can show who changed a price, a pin or a redemption fee, or who approved a held job | 2 | 2 | DX-12 audit with before/after | `ExtensionAdminTest`, `LossGuardTest` |
| DX-T10 | I | Costs, margins or registrar names leak to customers | 2 | 2 | DX-13; customer payload allow-list (CD-5) | `PublicCatalogueTest` |
| DX-T11 | T | A customer switches account currency to exploit a stale rate or a credit balance | 2 | 2 | DX-8 only with no unpaid invoice and no credit; DX-6 lock | `AccountCurrencyTest` |
| DX-T12 | D | Namecheap refuses calls because the staging server's IP changed (server replaced), and prices go stale | 3 | 1 | NC-8 `unauthorised` alert naming the IP whitelist; DR-8 keeps the last good snapshot; DX-2 marks the offer unhealthy after 72 hours so the other registrar is chosen | `NamecheapClientTest`, `RegistrarChoiceTest` |
| DX-T13 | S | DNS tampering or a redirect sends the Namecheap credential to another host | 1 | 3 | `ProviderHttpClient`: fixed hosts, own DNS + public-IP pinning, verified TLS, no redirects | existing `ProviderHttpClientTest` |

## 7. Residual risks

| ID | Risk | Owner / action |
|---|---|---|
| R-1 | Namecheap facts come from the official SDK and its recorded response, not from the documentation pages (blocked for automated reads). If the live response differs, items are skipped or the snapshot is quarantined: no wrong price is published. | Claude: compare the first live sync with the Namecheap price list in the CEO's account. |
| R-2 | Namecheap's `ClientIp` ties the credential to the server's IPv4 address. Replacing the staging server (infra apply) changes the address and stops the sync until the CEO updates the whitelist. | CEO: update the whitelist after each server replacement; consider a reserved IP for production. |
| R-3 | Namecheap and DomainNameAPI keys are not read-only; staging holds live keys. | DR-4 guard; CEO keeps small balances until launch. |
| R-4 | Registrar grace and redemption lengths differ from our defaults until staff set them per extension. | DX-7 guard catches the cost difference; staff review the lifecycle tab for featured extensions before launch. |
| R-5 | Choosing the cheapest registrar optimises cost, not reliability or support quality. | Staff can pin (DX-3); a reliability score can be added later. |

## 8. Dynadot and Spaceship (added 24 Oct 2026, before their code)

CEO decisions, 24 Oct 2026:

- **Dynadot** is added as a registrar. The CEO has a reseller account.
- **Spaceship plan agreed.** Spaceship ranks last. A read-only probe comes first, to find out whether its API returns prices for ordinary names.
- **Admin menu.** "API providers" is the menu heading, with **Domain registrars** and **Server providers** pages under it (paxofi-cloud PR #112).
- **Ranking**, used only to break ties in DX-3:
  1. DomainNameAPI
  2. Namecheap
  3. Dynadot
  4. Spaceship. It is last because any Spaceship cost that cannot be fetched is entered by hand.

### 8.1 What we know about the Dynadot API

**Sources.**
- Dynadot's RESTful API document (https://www.dynadot.com/domain/api-document, marked *Beta*).
- Its legacy API3 command list (https://www.dynadot.com/domain/api-commands).
- Two open-source clients that call the price endpoint: `Tahmied/Dynadot-wisecp-v5` and `7c/dynadot`.
- The RESTful price endpoint is **not** in Dynadot's own document, so the first real run confirms it (residual R-6).

| Fact | Value |
|---|---|
| RESTful base | `https://api.dynadot.com/restful/v2/`. The sandbox is `https://api-sandbox.dynadot.com`, with its own sandbox key and secret. |
| Authentication | `Authorization: Bearer <API key>` on every call. The **API secret** signs requests: `X-Signature` = Base64(HMAC-SHA256(secret, apiKey + "\n" + fullPathAndQuery + "\n" + X-Request-ID + "\n" + body)), with `X-Request-ID` a UUID. The document makes the signature mandatory for "transactional requests, including those that retrieve sensitive information". |
| Keys | Created in the Dynadot account under **Tools → API**. Since v2.0.0 (9 Oct 2025), "Reseller account no longer required to enter at least 1 IP to use API (RESTful API only)". |
| Price list | `GET /restful/v2/domains/get_tld_price?currency=usd&show_multi_year=true&page_index=N&count_per_page=M`. `data.tld_price_list[]` holds `tld`, `all_years_register_price[]`, `all_years_renew_price[]`, `transfer_price`, `min_duration`, `max_duration` and `on_sale`; `data.currency` is the currency. |
| Price meaning | The `all_years_*` arrays are **cumulative totals**: index 0 is the 1-year total, index 1 the 2-year total, and so on. `on_sale` is not a reliable promotion flag. The RESTful response has **no restore price and no grace or redemption days**. |
| Rate limit | 1 request at a time and 60 per minute on a regular account; HTTP 429 when exceeded. |
| Legacy API3 | `api3.xml?key=<API key>&command=tld_price`. It carries restore and grace data, but the **API key goes in the query string**, and Dynadot has scheduled API3 for retirement. |

### 8.2 Dynadot design decisions

| ID | Decision |
|---|---|
| DY-1 | **RESTful only.** API3 is never used: it puts the key in the URL, where proxies and logs can capture it. |
| DY-2 | **Credential.** The API key and API secret are stored as JSON `{api_key, api_secret}` through API providers → Domain registrars (PC-1 to PC-8) and opened only in the provider worker. Both are printable, have no spaces and are at most 256 characters. They are sensitive parameters and are never logged or put in errors. |
| DY-3 | **Every call is signed.** We always send `X-Request-ID` (a random UUID) and `X-Signature` as in §8.1. This works whether or not Dynadot enforces the signature on price reads, and it protects the request if the bearer token alone were ever replayed elsewhere. The signature uses PHP's `hash_hmac('sha256', …, true)` and `base64_encode`. These are native primitives; we do not write our own crypto (CLAUDE.md §2). |
| DY-4 | **Own adapter, existing client.** All calls go through `ProviderHttpClient`: fixed host `api.dynadot.com`, our own DNS resolution with public-IP pinning, verified TLS 1.2+, no redirects, no proxies, `Accept: application/json`. The JSON is parsed with `ExactJson`, so numbers stay text. Each page is capped at 2 MiB, with at most 200 pages and 5,000 extensions. Pages are fetched one at a time, at least 1.1 seconds apart. A 429 waits and retries up to 3 times per page; after that the run fails with `rate_limited`. |
| DY-5 | **Cost rules.** The 1-year register cost is `all_years_register_price[0]`. The 1-year renew cost is `all_years_renew_price[0]`. Transfer is `transfer_price`. Restore is **empty** (not sold online through Dynadot until staff set it: DX-9, DR-15). Costs are rounded up to the cent (DR-5), and `data.currency` must be `USD` or the whole run fails. When the 1-year register cost is below the 1-year renew cost, the extension's status is `promo`, so DX-5 and DX-7 judge the renewal on the renew cost and never on the promotional first year. Multi-year totals are recorded in the field inventory (DR-16) but not yet sold. |
| DY-6 | **Strict parsing.** A row with a bad name, a missing or non-positive 1-year register or renew price, `min_duration` > 1, or an unexpected type is skipped with a fixed reason (DR-14). Duplicate extensions are skipped. A response without `data.tld_price_list` fails the run with `provider_error`. |
| DY-7 | **Errors.** HTTP 401 or 403, or a body `code` of 401 or 403, maps to `unauthorised` (one alert per streak, DR-9). 429 maps to `rate_limited`. Anything else is `provider_error`. Messages from Dynadot are never stored or shown; only the code is kept. |
| DY-8 | **Environment.** Staging uses the **live** key, read only, like the other registrars (CEO decision for Namecheap applied here). Writes are refused by the DR-4 write guard before the credential is opened. The sandbox host is allowed only outside production. |
| DY-9 | **Reuse.** Dynadot results use the same snapshot store, quarantine, skipped-item and field records, 02:30 WAT schedule, "Sync now" queue and registrar tabs as the other registrars (NC-9). The sync runs only once a Dynadot connection exists. |

### 8.3 What we know about the Spaceship API

These facts come from https://docs.spaceship.dev, read on 24 Oct 2026.

| Fact | Value |
|---|---|
| Base | `https://spaceship.dev/api/v1` |
| Authentication | Headers `X-API-Key` and `X-API-Secret`. Each key has scopes (`domains:read`, `domains:write`, `domains:transfer`, `domains:billing`, `contacts:*`, `dnsrecords:*`, `asyncoperations:read` and others). |
| Prices | There is **no price-list endpoint**. `POST /v1/domains/available` takes up to 20 names and returns availability, with `premiumPricing` (operation, price, currency) for premium names. |
| Rate limit | 30 requests per 30 seconds per user (availability). |
| Writes | Registration, renewal and transfer are asynchronous: HTTP 202 plus a `spaceship-async-operationid` header. |

### 8.4 Spaceship design decisions

| ID | Decision |
|---|---|
| SP-1 | **Least privilege.** The key entered for PaxofiCloud has only the `domains:read` scope until registration through Spaceship goes live. Write and billing scopes are added then, in a new key. The CEO's existing full-access key must not be entered. |
| SP-2 | **Credential.** Stored as JSON `{api_key, api_secret}` through API providers → Domain registrars and opened only in the provider worker. Both values are sent only in headers, never in a URL or a log. |
| SP-3 | **Probe first.** A price check sends **one** availability request (`POST /v1/domains/available`) for 20 ordinary test names (`paxofi-price-check-<16 random hex>.<tld>`) across 20 fixed common extensions. It registers nothing: the client has a single read operation (`RegistrarAction::Availability`), every call asks the write guard first, and the key has `domains:read` only. *Amended 25 Oct 2026 (before its code), so the CEO can run it without a shell:* the check is started from the **Spaceship** tab of Domain costs by staff with `catalogue.manage` (audited, at most once per 10 minutes, the same queue as "Sync now", DR-12) and run by the provider worker, the only process that can open the key. The operator CLI `prices:probe-spaceship` runs the same check directly. |
| SP-4 | **If the probe shows prices for ordinary names:** a daily Spaceship sync probes one name per extension on sale, in batches of 20 at no more than 1 request per 1.2 seconds. Each price becomes a 1-year register cost with source `probe`. Renew, transfer and restore stay empty unless the response carries them. The sync otherwise follows DY-4 to DY-9. |
| SP-5 | **If it does not:** Spaceship is used only as a fallback registrar for a short list of extensions chosen by staff. For each of them, staff with `catalogue.manage` enter the register and renew cost. The entry is audited and **expires after 30 days**. Three days before expiry, every Catalogue member is emailed. An expired or missing entry means Spaceship has no healthy offer for that extension (DX-2), so it is never chosen. The DX-7 loss guard still checks every spend. |
| SP-6 | **No scraping.** Spaceship's public price web page is not read by any job: that would be fragile, may breach their terms, and is an uncontrolled external call (CLAUDE.md §2.4). |
| SP-7 | **What a check keeps.** One row per check (time, trigger, outcome or failure reason, how many names were answered and priced) and one row per extension: Spaceship's `result` word (checked against a short-code pattern, else `other`), whether a `register` price came back for the ordinary test name, that price rounded up to the cent if it is USD, and the list of other operations that carried a price. Prices are internal costs, shown only to `catalogue.view` (like DR-13). Test names, Spaceship's message text and the key are never stored. Rows are append-only for the runtime database user. A check never feeds the cost list: it only answers SP-4 versus SP-5. |

### 8.5 Threats (Dynadot and Spaceship)

| ID | STRIDE | Threat | L | I | Mitigation | Test evidence |
|---|---|---|---|---|---|---|
| DY-T1 | I | The Dynadot key or secret leaks through a URL, a log or an error | 2 | 3 | DY-1 RESTful only, key in the header; DY-2 sensitive parameters; DY-7 code-only errors | `DynadotClientTest` (key and secret absent from URL, logs, exceptions) |
| DY-T2 | S | A wrong signature makes the call fail, or the signature leaks the secret | 2 | 2 | DY-3: the HMAC sends a digest, never the secret; the test vector is computed independently | `DynadotSignatureTest` |
| DY-T3 | T | A promotional first-year price or cumulative-array misuse becomes our renewal cost | 3 | 3 | DY-5 uses index 0 only, `promo` status, renew judged on renew cost; DX-5 floor and DX-7 guard | `DynadotPriceParserTest` |
| DY-T4 | D | Dynadot's 1-request-at-a-time limit makes the sync hammer the API or never finish | 2 | 1 | DY-4 serial paging, spacing, bounded retries, page cap | `DynadotClientTest` |
| DY-T5 | E | A staging bug spends money at Dynadot with the live key | 2 | 3 | DR-4 guard covers every Dynadot write | `RegistrarWriteCoverageTest` extended |
| SP-T1 | E | A full-access Spaceship key in our store allows registrations, transfers or DNS changes if the store is compromised | 2 | 3 | SP-1 `domains:read` only; the runbook checks the scope | Runbook step: confirm the key shows only `domains:read` before entering it |
| SP-T2 | T | A stale hand-entered Spaceship cost makes us sell below cost | 3 | 3 | SP-5 30-day expiry and reminder; DX-2 healthy only when unexpired; DX-7 guard | `ManualCostTest` |
| SP-T3 | R | Nobody can show who entered a Spaceship cost | 2 | 2 | SP-5 audited with before and after | `ManualCostTest` |
| SP-T4 | E | The price check registers or reserves a test name, or is used to send many availability calls | 1 | 2 | SP-3: one read operation only, write guard first, `domains:read` key; one request of 20 names per check; check requests limited to one per 10 minutes and audited | `SpaceshipClientTest`, `SpaceshipPriceCheckTest`, `RegistrarWriteCoverageTest` |
| SP-T5 | I | The Spaceship key or secret leaks through a URL, log, error or debug dump | 2 | 3 | SP-2: the secret is the endpoint's credential header, the key an allow-listed request header; both are sensitive parameters; failures keep a fixed reason only | `SpaceshipClientTest` (both absent from URL, exceptions and debug output) |

### 8.6 Residual risks (Dynadot and Spaceship)

| ID | Risk | Owner / action |
|---|---|---|
| R-6 | The Dynadot price endpoint and response come from two open-source clients, not Dynadot's own document, which is *Beta*. If the live response differs, items are skipped or the run fails; no wrong price is published. | Claude: compare the first live sync with the Dynadot price list in the CEO's account. CEO: ask Dynadot support to confirm `domains/get_tld_price` if it fails. |
| R-7 | Dynadot's RESTful price response has no restore price or grace days. | Staff set them per extension (DX-9). Restore through Dynadot is not sold online until then. |
| R-8 | Spaceship may never expose ordinary prices through its API. | SP-5 fallback list. CEO: ask Spaceship support for a reseller price list or feed. |

## 9. Review log

| Date | Change |
|---|---|
| 24 Oct 2026 | First version, before code. CEO decisions: USD base; cheapest healthy registrar with pinning; Namecheap live read-only on staging; Namecheap sync first. |
| 24 Oct 2026 | Built with the Namecheap price sync: the Domain costs page and "Sync now" take the registrar from a fixed server-side list (unknown names get 404, never a query to another table); `prices:sync --provider=` checks the same list; provider connections carry a fixed category (Domain providers / Server providers, CEO decision). Product names are lower-cased so a sheet that writes "NET" in one action and "net" in another still matches (found by test). Spaceship (CEO, 24 Oct): its API has no price list, so it is not part of this sync; the plan and its threat-model section come before its code. |
| 24 Oct 2026 | Section 8 added before code: Dynadot facts and DY-1 to DY-9, Spaceship facts and SP-1 to SP-6, threats DY-T1 to DY-T5 and SP-T1 to SP-T3, residual risks R-6 to R-8. CEO decisions: add Dynadot; Spaceship plan agreed (probe first, manual fallback last); ranking DomainNameAPI, Namecheap, Dynadot, Spaceship. |
| 25 Oct 2026 | Domain extensions page built (DX-2, DX-3, DX-5, DX-9, DX-10): registrar choice from the three live feeds (healthy = accepted snapshot under 72 h, USD), pin with "pin unavailable" fallback, USD price via the CD-14 deriver and NGN from the USD price, unsold action when a cost is missing or below the floor (fails closed), pricing settings stored in the database only, launch extensions seeded as featured. DX-11 and DX-12 amended (see rows). The provider worker now refreshes exchange rates once the accepted set is 20 hours old (tried at most hourly). Not yet: per-term prices, staff price overrides, grace/redemption from feeds, public catalogue exposure, loss guard (DX-6, DX-7) — next PRs. |
| 25 Oct 2026 | SP-3 amended and SP-7, SP-T4, SP-T5 added before the Spaceship code: the price check is started from the admin console and run by the provider worker (the CEO has no shell on staging); what a check stores. API facts re-read from docs.spaceship.dev: request `{"domains": [1..20 names]}`, response `domains[].{domain, result, premiumPricing[].{operation, price, currency}}`; whether ordinary names carry a price is not stated, which is what the check finds out. |
| 24 Oct 2026 | Built with the Dynadot price sync: paging follows the server's own `page`/`page_size` and stops on a short page, no paging data, or a page that names no new extension (guards against a wrong page index); thousands separators are accepted; the signature test vector is computed with OpenSSL outside PHP. |
