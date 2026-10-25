# Threat model — customer domain search (DOM-001, DOM-003, DOM-017)

Status: first version, 25 Oct 2026, written before the code. Owner: Claude (CTO role); approver: CEO.
Related: THREAT-MODEL-DOMAIN-PRICING.md (DX-5 prices, DX-13 visibility), THREAT-MODEL-PROVIDER-CONNECTIONS.md (PC-6: only the provider worker holds registrar keys).

## 1. Scope

A public search box (customer portal now, the public website later): the customer types a name, and sees
whether it is available in the requested extension and in the featured extensions, each with our
one-year selling price in USD and NGN. A name that is taken shows "taken". This PR covers the search
only: no cart, no WHOIS details, no registrar call.

## 2. Key facts and constraints

- The registrar keys are opened only in the provider worker (PC-6). The web tier must never hold them,
  so the web tier cannot call DomainNameAPI's availability check (`POST /domains/bulk-search`, official
  client domainreseller/php-dna 4.0.3) during a search.
- **RDAP** (RFC 9082/9083) is public and needs no credential. IANA publishes which RDAP server answers for
  each extension at `https://data.iana.org/rdap/dns.json` (RFC 9224). `GET {server}/domain/{name}`
  answers 200 when the name is registered and 404 when it is not.
- RDAP is not authoritative for availability: reserved, premium or blocked names may answer 404. The
  registrar check at checkout (later PR, in the worker) is the authority and decides the price.

## 3. Design decisions

| ID | Decision |
|---|---|
| DS-1 | **Availability from RDAP, prices from our tables.** The web tier asks RDAP only; it never calls a registrar and never opens a key. Results are labelled "available to register" only as a pre-check; the registrar confirms at checkout (DS-9). |
| DS-2 | **Bootstrap.** The IANA `dns.json` file is fetched by the web tier through `ProviderHttpClient` (fixed host `data.iana.org`, verified TLS, own DNS + public-IP pinning, no redirects, 1 MiB cap) at most once per 24 hours, validated, and cached in Redis; the last good copy is kept if a fetch fails. Only entries whose servers are `https` URLs with a DNS host name, no port, no credentials, no query, and a plain path are kept. |
| DS-3 | **RDAP calls.** Each call goes through `ProviderHttpClient` with an allow-list of exactly the one server for that extension (from DS-2), `Accept: application/rdap+json`, 2 s connect / 4 s total, 64 KiB cap. **Only the HTTP status is used** (200 taken, 404 available); the body is never parsed or shown. The lookup asks with `HEAD` first (no body is read; Verisign's .com "not found" has no length and could hold a body read until the timeout) and repeats once with `GET` only when the server refuses `HEAD` (400, 403, 405, 501). Anything else (429, 5xx, timeout, no server) is `unknown`, never "available". |
| DS-4 | **Input.** One label: 1–63 characters, `a-z 0-9 -`, no leading or trailing hyphen, lower-cased; an optional extension that must be one we sell (has a USD register price on the Domain extensions page). Internationalised names are refused for now. The domain is built by us from the label and a known extension, never taken from the request as a URL or host. |
| DS-5 | **How many lookups.** One search checks at most 6 names: the requested extension (default `com`) plus the featured extensions, each once. Lookups run one after another; a search returns within about 6 × 4 s in the worst case, usually under 1 s. |
| DS-6 | **Abuse limits.** Per client IP: 20 searches per minute and 300 per hour (Redis); over the limit → 429 with Retry-After. Per RDAP server: at most 30 lookups per 10 seconds across all users; over that → `unknown` for that name, no call. Turnstile is not used on search (friction); limits and caching carry the load. |
| DS-7 | **Caching.** Each name's result is cached in Redis for 10 minutes (available or taken) and 1 minute (`unknown`), under a key that is the SHA-256 of the name, so Redis never holds searched names in clear. |
| DS-8 | **Privacy (NDPA).** Searched names are not stored in the database, not logged and not audited; logs carry only counts and fixed codes. The registrar check (DS-13) holds names in Redis for at most 2 minutes. |
| DS-9 | **Prices and visibility (DX-13).** Each result shows only the selling price (register, renew) in USD and NGN from the Domain extensions engine, the featured flag and label. Costs, registrar names, pins and margins never leave the admin API. An extension without a price is not offered. |
| DS-11 | **Public price list.** `GET /api/v1/domains/prices?q=&page=` returns the featured extensions and every extension we sell (25 per page) with the one-year register, transfer and renew selling price in USD and NGN, the featured flag and the label. Same quote engine and allow-list as DS-9: no costs, registrars, pins or margins. Nothing is listed until pricing settings exist. Search text is limited to `a-z 0-9 . -`, 63 characters; 120 requests per minute per IP; `Cache-Control: public, max-age=300` (prices change at most daily). Staff see the chosen registrar's cost on the admin Domain extensions page only (`catalogue.view`). |
| DS-13 | **Registrar check (authoritative + premium).** After the RDAP pre-check the page asks `POST /api/v1/domains/check` for the names RDAP did not rule out (at most 6, each a label in an extension we sell). The web tier never holds a registrar key (PC-6): it stores the names in Redis under a random 128-bit job ID for at most 2 minutes and queues the ID. The provider worker takes jobs every loop (idle wait 1 s), asks DomainNameAPI `domains/bulk-search` (a read, allowed on staging by the write guard), deletes the job, and writes the answer for 2 minutes: available, taken, unavailable (reserved) or unknown, the premium flag, and for a premium name with a USD price our selling price from the same markup, rounding and floor. A failure, no connection, or an unrecognised status is `unknown` and the RDAP result stays. The page polls `GET /api/v1/domains/check?id=` for up to 15 s. Limits: 10 per minute and 120 per hour per IP, queue capped at 500; responses `no-store`; the answer is rebuilt field by field on the way out. Names are never logged or written to the database. |
| DS-10 | **Public endpoint.** `GET /api/v1/domains/search?name=&tld=` needs no sign-in (it will serve the public website), is read-only (no CSRF), returns `Cache-Control: no-store`, and is covered by the existing security headers and the API write guard (GET only). |

## 4. Threats

| ID | STRIDE | Threat | L | I | Mitigation | Test evidence |
|---|---|---|---|---|---|---|
| DS-T1 | T/I | SSRF: a crafted name or a poisoned bootstrap entry makes the server call an internal address | 2 | 3 | DS-2 URL validation; DS-3 one-host allow-list per call; `ProviderHttpClient` own DNS, public-IP pinning, no redirects; DS-4 strict label, extension from our own list | `RdapTest`, `DomainSearchTest`, existing `ProviderHttpClientTest` |
| DS-T2 | D | The search is used to flood RDAP servers or our server | 3 | 2 | DS-5 at most 6 lookups; DS-6 per-IP and per-server limits; DS-7 cache | `DomainSearchTest` (limits, cache) |
| DS-T3 | T | A false "available" sells a name we cannot register | 3 | 2 | DS-1 pre-check only; DS-3 `unknown` never shown as available; registrar check at checkout | `RdapTest` |
| DS-T4 | I | Searched names (possible business plans) leak through logs, DB or Redis | 2 | 2 | DS-7 hashed keys; DS-8 no storage or logging | `DomainSearchTest` (Redis keys hashed) |
| DS-T5 | I | Costs, margins or registrar choice leak through the public search | 2 | 3 | DS-9 allow-listed response fields | `DomainSearchTest` (response keys) |
| DS-T6 | T | A hostile RDAP response (huge, slow, malformed) exhausts the web tier | 2 | 2 | DS-3 status only, 64 KiB cap, 4 s timeout | `RdapTest` |
| DS-T8 | I | The public price list leaks costs or margins | 2 | 3 | DS-11 allow-listed fields; costs stay behind `catalogue.view` | `DomainPriceListTest` |
| DS-T9 | D | The price list is scraped hard enough to load the database | 3 | 1 | DS-11 per-IP limit, cacheable response, quotes computed once per request | `DomainPriceListTest` |
| DS-T10 | E | The public check is used to make us hammer the registrar or exhaust its rate limit | 3 | 2 | DS-13 per-IP limits, 6 names per job, queue cap, 10 jobs per loop | `RegistrarCheckTest` |
| DS-T11 | T | A forged or replayed job ID reads someone else's answer or injects a job | 2 | 1 | 128-bit random IDs, strict hex format, 2-minute TTL; answers carry only public availability for names the requester typed | `RegistrarCheckTest` |
| DS-T12 | T | An odd registrar answer shows a taken or reserved name as available, or sells a premium name at the normal price | 2 | 3 | Unrecognised status is unknown; premium without a usable price is "contact us", not sellable; premium priced through the same floor; checkout re-checks | `DomainNameApiAvailabilityParserTest`, `RegistrarCheckTest` |
| DS-T7 | S | Bootstrap fetched from a spoofed host | 1 | 3 | Fixed host, verified TLS, public-IP pinning; validation of every entry | `RdapTest` |

## 5. Residual risks

| ID | Risk | Owner / action |
|---|---|---|
| R-1 | RDAP says "available" for some reserved or premium names. | Registrar check at checkout (cart PR); the result says "available to register" and the price is confirmed at checkout. |
| R-2 | Some extensions have no RDAP server (none in IANA's file). | They show `unknown` ("couldn't check right now"); port-43 WHOIS fallback is a later PR (DOM-017). |
| R-3 | A registry rate-limits our server IP. | DS-6 per-server cap, DS-7 cache; `unknown` instead of errors. |

## 6. Review log

| Date | Change |
|---|---|
| 25 Oct 2026 | DS-13, DS-T10 to DS-T12: registrar availability and premium check through the provider worker (CEO: "build all"). |
| 25 Oct 2026 | DS-3: HEAD first, GET only when HEAD is refused (every unregistered .com showed "couldn't check"). |
| 25 Oct 2026 | DS-11, DS-T8, DS-T9: public price list and featured cards (CEO request); admin shows registrar cost before pricing settings exist. |
| 25 Oct 2026 | First version, before code. CEO chose customer domain search next (option A). |
