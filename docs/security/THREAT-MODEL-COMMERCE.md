# Threat Model — Commerce: catalogue, pricing, cart, checkout and orders

| | |
|---|---|
| Epics | BE-03 Catalogue & pricing, BE-04 Cart & checkout, FE commerce screens, admin catalogue (Sprint 2) |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | CAT-001…004, CAT-006, PRC-001…008, PRC-010, CRT-001, CRT-002, CRT-005, ORD-001, ORD-002, ORD-004, ACC-004, CMP-010, NFR-012 |
| Standard | OWASP ASVS 5.0 — Level 2; **Level 3** for anything that sets or changes a price, a VAT rate or an order total |
| Status | Baseline, written **before** any commerce code (Sprint 2, 20 Oct 2026). Revision 2 (20 Oct 2026), written before its code: provider cost sync, markup, automatic repricing and exchange rates (§6.5, CD-13…CD-19), per the CEO's pricing decisions. Payments, invoices as documents, the ledger and refunds have their own model in Sprint 3. |

Every Sprint 2 commerce PR cites the threat IDs (`C-xx`) it mitigates and adds the matching tests from §7. A threat counts as mitigated only when its test exists and passes in CI.

---

## 1. Scope

In scope:

- **Catalogue data.** Products, plans, billing cycles and typed options, their lifecycle states (CAT-002), and the provider mapping kept apart from customer-facing data (CAT-003).
- **Prices.** Effective-dated NGN and USD sell prices per cycle, with first-term and renewal prices; provider cost; VAT rate.
- **Public catalogue API.** Read-only and cached; used by the website and the portal (CAT-006).
- **Server-side cart.** Anonymous cart under a signed cookie, merged into the account cart at sign-in (CRT-001, CRT-002).
- **Billing details and billing currency** (ACC-004, PRC-003).
- **Checkout.** Creates an Order and its first Invoice record atomically, once per idempotency key (CRT-005).
- **Orders.** State machine (SRS §5.1), immutable line snapshots, and the customer's order list (ORD-001, ORD-002, ORD-004).
- **Staff catalogue management** in the admin console (CAT-001, PRC-005).
- **Provider cost sync** (revision 2). A background job pulls provider products and cost prices from provider APIs:
  - DomainNameAPI: extensions and register, renew and transfer costs;
  - Hetzner Cloud and Vultr: server types, locations and add-ons such as extra IPs and backups.
  - Staff can also start a sync with "Sync now" in the admin console.
- **Markup and automatic repricing** (revision 2). Selling prices are derived from cost, an exchange rate and a markup rule. Small changes go live automatically from the next day; large ones wait for staff approval.
- **Exchange rates** (revision 2), from a free public source behind a replaceable adapter.

Out of scope here:

- **Sprint 3:** payment gateways and webhooks; invoice numbering, PDF and credit notes; the ledger; refunds.
- **Sprint 4:** provisioning.
- **Identity, sessions, CSRF and staff sign-in:** covered by THREAT-MODEL-IDENTITY.md. This model relies on them.

## 2. Assets

| Asset | Why it matters | Classification |
|---|---|---|
| Sell prices, renewal prices and VAT rate | Wrong values lose money on every sale or overcharge customers (consumer-protection exposure) | Integrity-critical (L3) |
| Provider cost and margin | Commercially sensitive; leaking it weakens negotiation and invites undercutting | Confidential |
| Provider mapping (adapter + product reference) | Leaks which upstream we use; integrity decides what gets provisioned | Confidential, integrity-critical |
| Cart contents | A customer's intended purchases; tampering changes what they pay for | Internal, integrity |
| Orders and their line snapshots | Legal record of what was sold, at what price and tax | Integrity-critical, retained (NDPA/tax) |
| Billing details (name, company, address, phone, TIN) | Personal data | Confidential (NDPA 2023) |
| Idempotency keys | Prevent double orders and double charges | Integrity |
| Provider cost snapshots | Input to every derived selling price; a wrong or forged feed reprices the whole catalogue | Confidential, integrity-critical (L3) |
| Exchange rates | Convert EUR/USD costs into NGN/USD prices; a wrong rate misprices every converted item | Integrity-critical (L3) |
| Markup rules, thresholds and margin floor | Decide our margin and when a human must approve | Confidential, integrity-critical (L3) |

## 3. Data-flow diagram and trust boundaries

```mermaid
flowchart LR
  subgraph Internet["Untrusted: Internet"]
    V["Visitor / customer browser"]
    W["Public website (Sprint 10)"]
    S["Staff browser"]
  end
  subgraph Edge["Boundary 1: Cloudflare edge + nginx"]
    E["WAF, rate limits, cache for GET /api/v1/catalogue"]
  end
  subgraph App["Boundary 2: PHP web tier (runtime DB user)"]
    CAT["Catalogue API (public, read-only)"]
    CART["Cart API (anonymous cookie or session)"]
    CHK["Checkout + Orders API (session + CSRF)"]
    ADM["Admin catalogue API (staff session + permission)"]
    PR["Pricing engine (domain: Money, VAT, rounding)"]
  end
  subgraph Data["Boundary 3: data network"]
    DB[("MySQL: catalogue, prices, orders, lines")]
    R[("Redis: carts, rate limits")]
  end
  V --> E --> CAT & CART & CHK
  W --> E
  S --> E --> ADM
  CART & CHK --> PR
  CAT & CART & CHK & ADM --> DB
  CART --> R
```

Boundaries:

1. **Everything the browser sends is untrusted:** prices, totals, currencies, plan states and option values are recomputed or re-validated on the server.
2. **The web tier connects as the runtime database user.** Its per-table grants (THREAT-MODEL-IDENTITY §6.22) make order lines append-only.
3. **Staff catalogue changes** need a staff session and a catalogue permission, are audited, and take effect only from a future date.

## 4. Assumptions

- Identity, sessions, CSRF, the Origin/JSON write guard and staff RBAC behave as the identity threat model describes.
- Sell prices are set by staff in the admin console. The CEO sets launch prices before beta (BR-05). Revision 2: for domains and servers, the selling price is *derived* from the synced provider cost by markup rules that staff set (CD-14). Hosting (cPanel) has no price feed, so its prices are entered by hand.
- Provider price feeds and the free exchange-rate source are **untrusted input**: an outage, a format change or a compromise upstream must not reprice the catalogue without a human seeing it.
- The VAT rate and treatment are confirmed by the accountant (BR-06/BR-07). Until then, 7.5% is the configurable default for Nigerian customers and 0% for others.
- PHP runs on 64-bit integers. All amounts stay far below 2^53, so the TypeScript `number` type is exact for display.

## 5. Design decisions this model fixes

| ID | Decision |
|---|---|
| CD-1 | **Money is an integer of minor units plus an ISO 4217 code** (`NGN`, `USD` only at launch). Arithmetic exists only on `Money`. Adding or comparing different currencies throws. Amounts are bounded to ±10^13 minor units (₦100 billion / $100 billion), so no operation can overflow. There is no `float` anywhere in a money path (PRC-001); a CI check enforces it. |
| CD-2 | **Percentages are integer basis points** (7.5% = 750 bp). `amount × bp / 10000` is computed in integers and rounded half-up (away from zero for negatives) at line level; totals are sums of rounded lines (PRC-008, PRC-010). |
| CD-3 | **The server is the only price authority.** Cart and checkout requests carry plan, cycle, options and quantity only. Any price, total, tax or currency field in a request is ignored. The response always shows the server-computed figures (CRT-001). |
| CD-4 | **Prices are explicit rows** keyed by (plan, cycle, currency, effective_from), with first-term and renewal amounts. A plan can become ACTIVE only when every cycle has both NGN and USD prices (PRC-002). A new price row's effective date must be in the future, so history is never rewritten (PRC-005). Orders snapshot the price row they used. |
| CD-5 | **Provider mapping and cost live in separate tables** that customer-facing queries never join. Public and customer DTOs are built from an allow-list of fields, never by serialising entities. A test asserts that no `provider`, `cost`, `margin` or `reference` key appears in any customer payload (CAT-003, PRC-004). |
| CD-6 | **Cart storage.** Carts live server-side in Redis.<br>• A signed-in cart is keyed by account ID.<br>• An anonymous cart is keyed by SHA-256 of a 256-bit random ID, sent in a `__Host-pc_cart` cookie (HttpOnly, Secure, SameSite=Lax).<br>• A cart holds at most 20 lines and expires after 30 days.<br>• At sign-in the anonymous cart's lines are merged into the account cart (still within the limits) and the anonymous cart is deleted (CRT-001, CRT-002). |
| CD-7 | **Checkout is one database transaction.** It reloads current prices and plan states, checks verification, terms and billing details, then writes the order, its lines and the invoice record. An `Idempotency-Key` header (unique per account) makes a repeat return the first result (CRT-005). |
| CD-8 | **Order lines are immutable in the database.** The runtime user gets `SELECT, INSERT` only on `order_lines`, the same treatment as `audit_events`. Order status changes only through the domain state machine (§5.1), and every transition is audited (ORD-001, ORD-002). |
| CD-9 | **Customer order reads are scoped by the session's account in the query.** There is no account parameter; another account's order ID returns 404 (ORD-004). |
| CD-10 | **Billing currency is chosen when billing details are first saved:** NGN for Nigeria, USD otherwise. It locks with the first invoice, and the cart prices in that currency only (PRC-003). |
| CD-11 | **Catalogue text** (names, descriptions, option labels) is plain text with length limits. It is rendered as text, never as HTML, in every frontend. |
| CD-12 | **Tax rates are a per-country table, not code** (CEO decision, 20 Oct 2026). Each row holds country (ISO 3166-1 alpha-2), tax name, rate in basis points and effective-from date. One fallback row covers every unlisted country, set to 0%. The home country is Nigeria at 7.5%. Each product kind can be marked taxable or not.<br>• Only Finance or Security Admin staff can change rates. Changes are future-dated and audited.<br>• **The tax country is the billing-address country.** The Cloudflare visitor country only pre-fills the form.<br>• Both countries are stored on the order. A mismatch where the billing country has a lower rate than the visitor country flags the order for review; the order is still taxed by billing country.<br>• The rate, tax name and amount are snapshotted on each order line and invoice, so later rate changes never alter past documents.<br>• A country is added only once Paxofi is tax-registered there (accountant to confirm).<br>This replaces the two-rate `VatPolicy` in the billing-details/checkout work.<br>**Revision 2 (CEO, 20 Oct 2026):**<br>• Paxofi is tax-registered in Nigeria (7.5% VAT) and in the USA (Montana). Montana has no general sales tax, so the US rate is 0%. The fallback for other countries is 0%.<br>• The IP country is the **second piece of evidence**, not the decider. When it disagrees with the billing country and the billing country has the lower rate, checkout asks the customer to confirm the billing country, and the order is flagged for review. |
| CD-13 | **Provider cost sync** is a worker job, never a web request.<br>• A `ProviderPriceFeed` port has one adapter per provider: DomainNameAPI, Hetzner Cloud, Vultr, and later ConnectReseller. Each fetches through `ProviderHttpClient` (allow-listed hosts, verified TLS, timeouts, response caps) with credentials from the encrypted store.<br>• Runs daily on a schedule, plus "Sync now" in the admin console (`catalogue.manage`). "Sync now" queues the same job; at most one sync runs per provider at a time, at most one manual request per provider per 10 minutes, with bounded retries and backoff.<br>• Each run writes an **append-only cost snapshot**, an internal table never read by customer code (CD-5). Each item records the provider reference, kind, currency (NGN, USD or EUR), cost amounts in minor units, and the fetch time.<br>• **Strict parsing:** unknown currencies, zero or negative amounts, out-of-range amounts and malformed items are refused item by item, and counted.<br>• **Quarantine:** a whole snapshot is held for staff review and changes nothing when it looks implausible: more than 20% of known items disappear, or more than 20% of items change cost by more than 50%.<br>• New provider items (a new extension or server type) appear in the admin console as **"Available from provider"**. By default they are not on sale until staff enable them and give them a markup.<br>• **Exception for domains** (CEO decision, 20 Oct 2026: "we are selling all the domain extensions that our domain providers will have"): a setting, *sell new extensions automatically*, is **on** for domains and off for servers. When it is on, a new extension goes on sale automatically only when all of these hold:<br>&nbsp;&nbsp;– a markup rule covers it (default or group);<br>&nbsp;&nbsp;– register and renew prices derive in both NGN and USD above the margin floor;<br>&nbsp;&nbsp;– the snapshot it came from was not quarantined.<br>&nbsp;&nbsp;Staff are told about each auto-enabled extension, and the change is audited. Only Finance or Security Admin staff can switch the setting. |
| CD-14 | **Markup engine.** selling price = round_up(convert(cost) × (1 + markup)).<br>• **Markup rules** are in basis points: a per-item override beats a per-group rule (e.g. per extension group or product kind), which beats the default.<br>• **Rounding** is a per-currency step, e.g. up to the next ₦100 or the next $0.50.<br>• **Margin floor:** a derived price is never below converted cost × (1 + minimum margin), default 10%. An item that would fall below its floor is never published.<br>• Results are integer minor units. Currency conversion multiplies decimal strings with PHP's bundled `bcmath` extension and rounds half-up to minor units, so there is no `float` and no integer overflow. `NoFloatMoneyTest` covers the pricing engine.<br>• First-term and renewal prices are derived separately from their own cost (e.g. register vs renew for domains). Transfer pricing uses the same rule. |
| CD-15 | **Automatic repricing** (CEO decision, 20 Oct 2026). After each sync or rate update, the engine compares each enabled item's new derived price with its current price.<br>• **Small change** (at or below the approval threshold, default 10%): a new price row is written, effective from **00:00 WAT the next day** (future-dated, PRC-005). It is audited with actor `system` plus the snapshot and rate IDs it came from.<br>• **Large change** (above the threshold, up or down): a **pending price change** is created. It goes live only when staff with `catalogue.manage` approve it; approval is audited.<br>• **Cost rises above the current selling price:** the item stops selling immediately (no new orders) and staff are alerted. It resumes only with an approved price.<br>• **Who can change the settings:** the threshold, the minimum margin and the default markup are changed only by Finance or Security Admin staff, and every change is audited.<br>• **Existing customers:** renewal-price changes for existing services follow the renewal-notice rules (Sprint 8). An order always keeps the snapshot it was placed with. |
| CD-16 | **Exchange rates** come from a replaceable `FxRateSource` adapter.<br>• **Source at launch (CEO decision, 20 Oct 2026):** the free ExchangeRate-API open-access endpoint. It needs no key, updates daily, covers NGN, USD and EUR, and requires attribution. Paid sources can be added later behind the same port.<br>• **Fetch:** by the worker through `ProviderHttpClient`.<br>• **Storage:** rates are stored append-only as decimal strings with their source and time. They are visible in the admin console, and Finance staff can override a rate manually (audited).<br>• **Validation:** the required currencies must be present and positive, within plausible bounds. A move of more than 5% against the last accepted rate is **held** for Finance confirmation instead of being used.<br>• **Repricing hysteresis:** a rate update reprices converted items only when the rate has moved more than 3% since the rate their current price was derived from. This avoids daily naira churn; the CD-15 rules then apply.<br>• **Staleness:** if no rate is accepted for 72 hours, automatic repricing pauses and staff are alerted. Stored prices keep selling.<br>• **No conversion at checkout:** rates are used only to derive catalogue prices. A customer always pays the stored price in their billing currency (CD-10), and the invoice snapshots it. |
| CD-17 | **Billing cycles** (CEO decision, 20 Oct 2026, change record CR-002):<br>• hosting: monthly, **3 months**, **6 months**, 1 year, 2 years and 3 years;<br>• VPS: monthly and 1 year;<br>• domains: yearly (1–10 years).<br>Hosting prices are entered by hand per cycle. |
| CD-18 | **Content managed in the admin console (CMS).**<br>• Plan names, descriptions, feature lists and option labels are edited by catalogue staff, follow the CD-11 plain-text rules, and are audited.<br>• Rich pages (legal pages, help articles) arrive with the public website (Sprint 10) under their own threat-model section, which covers sanitised rich text. |
| CD-19 | **Who sees costs.** Provider costs, markups, margins and snapshots are visible only to staff with `catalogue.manage` or Finance permissions. They never appear in customer payloads (CD-5), logs or emails. |
| CD-20 | **Domain cart lines** (first cart PR, 25 Oct 2026). A line is `{id, kind: domain_register, domain, years: 1}`; it carries no price (CD-3).<br>• **Signed-in only for now**: the portal search is behind sign-in, so the cart is keyed by SHA-256 of the account ID (`pc:cart:`). The anonymous `__Host-pc_cart` cart and the merge at sign-in (CD-6) come with the public website.<br>• **Adding** needs the session and the CSRF token; the name is validated with the same rules as search and its extension must be on sale for registration. A name already in the cart is not added twice. 20 lines at most, 60 adds per account per hour, 30-day expiry refreshed on change.<br>• **Reading** re-quotes every line from the current price engine (USD and NGN). A line whose extension is no longer on sale shows as unavailable and counts in no total.<br>• The cart's price is a preview only: checkout re-checks availability and premium status with the registrar and locks the price (DX-6). A premium name shows the standard price in the cart and is re-priced or refused at checkout.<br>• Cart changes are not audited (no money moves); names in a cart belong to that account and expire with it. |

## 6. Threats (STRIDE)

Ratings: **L** likelihood and **I** impact on a 1–3 scale.

### 6.1 Prices and totals (PRC, CRT-001)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| C-01 | T | Client sends a lower price, total, discount or tax in a cart or checkout request | 3 | 3 | CD-3: request schema has no price fields; unknown fields rejected; totals recomputed | CRT-001 | `CartIgnoresClientPricesTest` |
| C-02 | T | Float rounding or mixed-currency arithmetic produces wrong totals | 2 | 3 | CD-1, CD-2; `Money` rejects mixed currencies; CI forbids `float` in money code | PRC-001, PRC-010 | `MoneyTest`, `PercentageRoundingTest`, `NoFloatMoneyTest` |
| C-03 | T | Integer overflow from huge quantities or amounts wraps a total negative | 1 | 3 | CD-1 bounds; quantity and term limits (CRT-002); checked multiplication | PRC-001, CRT-002 | `MoneyBoundsTest` |
| C-04 | T | VAT computed on the wrong base, wrongly rounded or applied to the wrong customers | 2 | 3 | CD-2 per-line VAT; rate from configuration (basis points), country rule from billing details; shown separately | PRC-008 | `VatTest` (₦10,000 → ₦750 acceptance case) |
| C-05 | T | Price changes between "add to cart" and "checkout", or are back-dated to alter past orders | 2 | 2 | CD-4 effective dating with future-only start; checkout re-prices and reports the change; orders snapshot the price row | PRC-005 | `PriceEffectiveDateTest`, `CheckoutRepricesTest` |
| C-24 | T | Customer picks a zero-rate billing country to avoid VAT | 2 | 2 | CD-12: billing country and Cloudflare country both stored; lower-rate mismatch flags the order for review; rate snapshotted | PRC-008 | `TaxCountryMismatchTest` |
| C-06 | E | Customer switches billing currency to buy at a cheaper USD/NGN price | 2 | 2 | CD-10 lock after first invoice; cart currency comes from the account, not the request | PRC-003 | `BillingCurrencyLockTest` |

### 6.2 Catalogue integrity and disclosure (CAT)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| C-07 | E | Customer buys a DRAFT, DEPRECATED or RETIRED plan through the API | 2 | 2 | Plan state checked at add-to-cart **and** again at checkout | CAT-002 | `PlanStateGateTest` |
| C-08 | T | Invalid or injected option values (unknown location, out-of-range integer) reach provisioning | 2 | 3 | Typed options with enum or range validation against the plan definition; unknown options rejected, with the option named in the error | CAT-004 | `OptionValidationTest` |
| C-09 | I | Public or customer API leaks provider name, provider reference, cost or margin | 2 | 2 | CD-5 allow-listed DTOs; separate tables | CAT-003, PRC-004 | `CustomerPayloadHasNoProviderDataTest` |
| C-10 | T | Staff without the catalogue permission changes prices or plans; or a permitted staff member sets an absurd price | 2 | 3 | AdminGate + catalogue permission; audited before/after values; activation blocked without both currencies; future-dated prices only | CAT-001, PRC-002, PRC-005 | `AdminCatalogueAuthorizationTest`, `PlanActivationNeedsBothCurrenciesTest` |
| C-11 | T | Stored XSS through product or option text | 1 | 3 | CD-11 plain text and length limits; React text rendering; strict CSP | — | `CatalogueTextValidationTest` |
| C-12 | D | Scraping or flooding the public catalogue API | 2 | 1 | Cacheable 5 minutes (`Cache-Control: public, max-age=300`); Cloudflare cache; per-IP rate limit | CAT-006 | `CatalogueCacheHeadersTest` |

### 6.3 Cart (CRT-001, CRT-002)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| C-13 | I/T | Reading or changing another visitor's cart | 2 | 2 | CD-6: anonymous carts keyed by the hash of a 256-bit random cookie; account carts keyed by the session's account; no cart ID in URLs | CRT-001 | `CartIsolationTest` |
| C-14 | D | Cart flooding (many anonymous carts or lines) exhausts Redis | 2 | 2 | 20 lines per cart, 30-day TTL, per-IP rate limit on cart writes, bounded line size | CRT-002 | `CartLimitsTest` |
| C-15 | T | Planted anonymous cart: an attacker sets a victim's cart cookie so attacker-chosen items merge into the victim's account cart | 1 | 1 | `__Host-` cookie (cannot be set from a subdomain); merge never checks out; checkout review lists every line; lines can be removed | CRT-001 | `CartMergeTest` |
| C-16 | T | Cross-site requests modify a signed-in cart | 2 | 1 | Existing Origin/JSON write guard; session writes need the CSRF token | API | covered by `ApiWriteGuardMiddlewareTest` + cart route tests |

### 6.4 Checkout and orders (CRT-005, ORD)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| C-17 | T/R | Double-submit or replay creates two orders (and later two charges) | 3 | 3 | CD-7 idempotency key, unique per account in the database; the same key with a different body returns 409 | CRT-005 | `CheckoutIdempotencyTest` |
| C-18 | S | Unverified account, missing terms acceptance or missing billing details places an order | 2 | 2 | Checkout preconditions in the domain service; terms version recorded on the order | CRT-005 | `CheckoutPreconditionsTest` |
| C-19 | T | Order lines or totals changed after placement (bug, SQL injection, compromised web tier) | 1 | 3 | CD-8 grants: `order_lines` SELECT/INSERT only for the runtime user; amounts and snapshots in columns with CHECKs | ORD-002 | `RuntimeGrantsIntegrationTest`, `OrderSnapshotTest` |
| C-20 | T | Order jumps to PAID or FULFILLED without payment | 1 | 3 | Domain state machine; invalid transitions throw; transitions audited; only Sprint 3 payment code may call `markPaid` | ORD-001 | `OrderStateMachineTest` |
| C-21 | I | Customer reads another account's orders (IDOR) | 2 | 3 | CD-9 query scoped by session account; 404 otherwise | ORD-004 | `OrderIsolationTest` |
| C-22 | I | Order and billing personal data leak through logs or errors | 1 | 2 | Fixed log codes, no request bodies in logs; billing data only in its tables | ACC-004 | review |
| C-23 | R | Customer disputes the price, renewal price or VAT shown at purchase | 2 | 2 | Order lines snapshot the first-term price, renewal price, VAT rate and terms version; checkout shows renewal price and date (CMP-010) | CMP-010 | `CheckoutDisclosureTest` |

### 6.5 Provider cost sync, markup, repricing and exchange rates (revision 2)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| C-25 | T | A faulty or compromised provider feed reports near-zero or wildly wrong costs, and the catalogue reprices to a loss | 2 | 3 | CD-13 strict parsing and snapshot quarantine; CD-15 approval for changes above 10%; margin floor (CD-14) | PRC-005 | `PriceFeedQuarantineTest`, `RepricingThresholdTest` |
| C-26 | S | Spoofed feed or rate source (DNS hijack, TLS interception) | 1 | 3 | `ProviderHttpClient`: allow-listed hosts, verified TLS 1.2+, own DNS resolution with non-public IPs refused, no redirects | — | existing `ProviderHttpClientTest` + adapter host allow-list test |
| C-27 | T | The free exchange-rate source is wrong, stale or manipulated | 2 | 3 | CD-16 bounds, 5% hold for Finance confirmation, 72-hour staleness pause, manual override, 3% hysteresis; rates never used at checkout | PRC-003 | `FxRateValidationTest`, `FxHysteresisTest` |
| C-28 | E | Staff without the right permission start syncs, approve price changes or change thresholds, markups or rates | 2 | 3 | AdminGate + `catalogue.manage` (sync, approve, markups); Finance or Security Admin (threshold, margin floor, rate override); all audited with before and after values | CAT-001 | `AdminPricingAuthorizationTest` |
| C-29 | D | "Sync now" spam or provider rate limits exhaust the worker, or get our API key throttled or banned | 2 | 2 | One running sync per provider; 1 manual request per provider per 10 minutes; bounded retries with backoff; response caps | — | `SyncThrottleTest` |
| C-30 | I | Provider credentials or cost data leak through sync logs, errors or the admin API | 1 | 3 | Credentials only in the worker from the encrypted store; fixed error codes; CD-19 cost visibility; customer allow-lists (CD-5) | PRC-004 | `CustomerPayloadHasNoProviderDataTest`, log review |
| C-31 | T | Rounding, conversion or overflow errors in derived prices | 2 | 3 | CD-14 `bcmath` decimal conversion, half-up rounding, then integer minor units within `Money` bounds; `NoFloatMoneyTest` | PRC-001, PRC-010 | `MarkupEngineTest` (worked examples), `NoFloatMoneyTest` |
| C-32 | R | Dispute over why a price changed, or who approved it | 2 | 2 | Every price row links to its source (snapshot ID, rate ID, rule ID, or the staff approval); audit events for each automatic and approved change | PRC-005 | `RepricingAuditTest` |
| C-33 | T | A cost rise leaves an item selling below cost while approval is pending | 2 | 2 | CD-15: an item whose cost exceeds its price stops selling immediately and staff are alerted | PRC-005 | `BelowCostStopsSellingTest` |
| C-34 | E | A new provider item (e.g. a new server type, or a mispriced extension) goes on sale without a human decision | 1 | 2 | CD-13: new servers are "Available from provider" only; new domain extensions go on sale automatically only under the setting the CEO chose, with full NGN/USD pricing above the floor and a non-quarantined snapshot; staff are notified and the change is audited | CAT-002, DOM-003 | `NewProviderItemNotForSaleTest`, `AutoSellNewExtensionTest` |
| C-35 | I/T | A customer reads or changes another account's cart | 1 | 2 | CD-20: the key comes only from the session's account; there is no account or cart parameter; line removal matches IDs inside that account's cart only | CRT-001 | `DomainCartTest` |
| C-36 | T | The cart preview is taken as a binding price, or a premium name is bought at the standard price | 2 | 2 | CD-20: no price stored in the cart; every read re-quotes; checkout re-checks with the registrar and locks the price (DX-6, DS-T12) | CRT-003 | `DomainCartTest` |
| C-37 | D | Cart flooding by a signed-in account | 2 | 1 | CD-20: 20 lines, 60 adds per hour, 30-day expiry; all writes need a session and CSRF token | CRT-001 | `DomainCartTest` |

## 7. Sprint 2 security test plan

| Test | Proves | Threats |
|---|---|---|
| `MoneyTest`, `PercentageRoundingTest`, `VatTest`, `MoneyBoundsTest` | Integer arithmetic, half-up rounding, the SRS acceptance figures, bounds | C-02, C-03, C-04 |
| `NoFloatMoneyTest` (CI) | No `float` in money code | C-02 |
| `PlanStateGateTest`, `OptionValidationTest`, `PlanActivationNeedsBothCurrenciesTest` | Catalogue rules | C-07, C-08, C-10 |
| `CustomerPayloadHasNoProviderDataTest`, `CatalogueCacheHeadersTest` | Disclosure and caching | C-09, C-12 |
| `CartIgnoresClientPricesTest`, `CartIsolationTest`, `CartLimitsTest`, `CartMergeTest` | Cart integrity | C-01, C-13…C-15 |
| `CheckoutIdempotencyTest`, `CheckoutPreconditionsTest`, `CheckoutRepricesTest`, `CheckoutDisclosureTest` | Checkout | C-05, C-17, C-18, C-23 |
| `OrderStateMachineTest`, `OrderSnapshotTest`, `OrderIsolationTest`, runtime grants on `order_lines` | Orders | C-19…C-21 |
| `PriceFeedQuarantineTest`, `NewProviderItemNotForSaleTest`, `SyncThrottleTest` | Provider cost sync | C-25, C-29, C-34 |
| `MarkupEngineTest`, `RepricingThresholdTest`, `BelowCostStopsSellingTest`, `RepricingAuditTest` | Markup and repricing | C-25, C-31…C-33 |
| `FxRateValidationTest`, `FxHysteresisTest` | Exchange rates | C-27 |
| `AdminPricingAuthorizationTest` | Who may sync, approve and change settings | C-28 |

## 8. Residual risks

| ID | Risk | Why accepted | Owner | Revisit |
|---|---|---|---|---|
| CR-1 | A staff member with the catalogue permission can set a wrong (e.g. zero) future price | Business rule, not a technical control; changes are audited and shown in a review screen. A second approver for large price drops is Phase 2 | CEO | Sprint 9 |
| CR-2 | VAT treatment (rate, domains from foreign registrars, non-Nigerian customers) is not yet confirmed by the accountant | Rate is configuration, not code; default 7.5% / 0% follows SRS BR-06/BR-07 | CEO / accountant | Before beta |
| CR-3 | Anonymous cart contents can be read by anyone holding the cookie (e.g. a shared computer) | A cart holds no personal or payment data | Product | — |
| CR-4 | The free exchange-rate source has no SLA and its rates may differ from what our bank actually gives us for NGN | Rates only derive catalogue prices, with bounds, holds, hysteresis and manual override; a paid source can be added behind the same port | CEO / Finance | Before beta |
| CR-5 | Changes below the 10% threshold go live without a human, so a slow drift of small automatic changes could compound | Every change is audited and reported; the margin floor still applies; the threshold is configurable | CEO | Sprint 9 (pricing report) |

## 9. Open items

| ID | Item | Decision needed by |
|---|---|---|
| CO-1 | Launch price points and provider costs for each plan (BR-05) | CEO, before beta |
| CO-2 | VAT rates and treatment confirmation (BR-06, BR-07): Nigeria rate, taxable product kinds (e.g. domains from foreign registrars), and the countries where Paxofi is tax-registered. Design decided by the CEO (CD-12) | Accountant, before beta |
| CO-3 | Coupons (PRC-007, Should). **Decided 20 Oct 2026: deferred until after the core purchase flow** (catalogue → cart → checkout → payment) | Closed |
| CO-4 | Repricing defaults: approval threshold 10% (CEO-confirmed), minimum margin 10%, FX hold 5%, FX hysteresis 3%, rounding steps. All are configurable settings | CEO, before beta |
| CO-5 | Accountant: (a) does the US registration create sales-tax duties in other states once sales there pass their economic-nexus limits; (b) are services sold to customers outside Nigeria zero-rated for Nigerian VAT | Accountant, before beta |
| CO-6 | Confirm each feed against the provider's real API with live credentials: the DomainNameAPI price list call and currency, Hetzner `/pricing` (EUR, net and gross), Vultr `/plans` (USD monthly). Adapters are built against the published docs and fixtures until then | Claude, when credentials are entered |

## 10. Review log

| Date | Change | By |
|---|---|---|
| 2026-10-20 | Baseline, written before any commerce code | Claude (security engineering) |
| 2026-10-20 | CD-12 per-country tax table + C-24 (CEO VAT decision); CO-3 closed (coupons deferred) | Claude (security engineering) |
| 2026-10-20 | CD-13/C-34: domains sell every extension the registrar offers (setting *sell new extensions automatically*, CEO decision) | Claude (security engineering) |
| 2026-10-20 | Revision 2, written before its code: provider cost sync, markup, automatic repricing, exchange rates, 3- and 6-month hosting cycles, CMS scope, tax evidence (CD-12 amended, CD-13…CD-19, C-25…C-34, CR-4/5, CO-4…6) | Claude (security engineering) |
| 2026-10-25 | CD-20, C-35 to C-37: domain cart lines (signed-in cart first; anonymous cart with the public website), written before the cart code | Claude (security engineering) |
