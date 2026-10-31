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
| DS-11 | **Public price list.** `GET /api/v1/domains/prices?q=&page=` returns the featured extensions and every extension we sell (25 per page) with the one-year register, transfer and renew selling price in USD and NGN, the featured flag and the label. Same quote engine and allow-list as DS-9: no costs, registrars, pins or margins. Nothing is listed until pricing settings exist. Search text is limited to `a-z 0-9 . -`, 63 characters; 120 requests per minute per IP; `Cache-Control: public, max-age=300` (prices change at most daily). Staff see the chosen registrar's cost on the admin Domain extensions page only (`catalogue.view`). Caching: `Cache-Control: no-cache` with a strong ETag (SHA-256 of the body); an unchanged list answers 304 with no body, so staff changes (featured, labels, prices) show on the next load. |
| DS-12 | **Categories.** Each listed extension carries one category from a fixed list of 14 (business, technology, geographic, … other). Staff may set it per extension; otherwise a short built-in list decides, then two-letter country codes are geographic, else other. The price list accepts `category=` (only names from the list) and returns the number of extensions on sale per category for the tabs. |
| DS-13 | **Registrar check (authoritative + premium).** After the RDAP pre-check the page asks `POST /api/v1/domains/check` for the names RDAP did not rule out (at most 6, each a label in an extension we sell). The web tier never holds a registrar key (PC-6): it stores the names in Redis under a random 128-bit job ID for at most 2 minutes and queues the ID. The provider worker takes jobs every loop (idle wait 1 s), asks DomainNameAPI `domains/bulk-search` (a read, allowed on staging by the write guard), deletes the job, and writes the answer for 2 minutes: available, taken, unavailable (reserved) or unknown, the premium flag, and for a premium name with a USD price our selling price from the same markup, rounding and floor. A failure, no connection, or an unrecognised status is `unknown` and the RDAP result stays. The page polls `GET /api/v1/domains/check?id=` for up to 15 s. Limits: 10 per minute and 120 per hour per IP, queue capped at 500; responses `no-store`; the answer is rebuilt field by field on the way out. Names are never logged or written to the database. Reply shape (from the official client): items under `infos`; status `available`, `1` or `true` is available. A reply naming none of the asked domains is `malformed`. The worker keeps only the latest outcome (fixed code, counts, time; never names) in Redis for 7 days, shown to `catalogue.view` staff on Domain costs as "Last availability check". |
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
| DS-T9 | D | The price list is scraped hard enough to load the database | 3 | 1 | DS-11 per-IP limit, ETag revalidation (304 without a body), quotes computed once per request | `DomainPriceListTest` |
| DS-T10 | E | The public check is used to make us hammer the registrar or exhaust its rate limit | 3 | 2 | DS-13 per-IP limits, 6 names per job, queue cap, 10 jobs per loop | `RegistrarCheckTest` |
| DS-T11 | T | A forged or replayed job ID reads someone else's answer or injects a job | 2 | 1 | 128-bit random IDs, strict hex format, 2-minute TTL; answers carry only public availability for names the requester typed | `RegistrarCheckTest` |
| DS-T12 | T | An odd registrar answer shows a taken or reserved name as available, or sells a premium name at the normal price | 2 | 3 | Unrecognised status is unknown; premium without a usable price is "contact us", not sellable; premium priced through the same floor; checkout re-checks | `DomainNameApiAvailabilityParserTest`, `RegistrarCheckTest` |
| DS-T7 | S | Bootstrap fetched from a spoofed host | 1 | 3 | Fixed host, verified TLS, public-IP pinning; validation of every entry | `RdapTest` |

## 7. Multi-source availability lookup (DS-14 to DS-21, proposed 31 Oct 2026)

CEO request: a domain search that does not depend on one source, like WHMCS's choice of lookup
providers, and an extension list that includes every registrar's extensions.

**Today:** the pre-check uses RDAP only (DS-1). Extensions without an RDAP server in IANA's file, which
includes most `.ng` second-level names, show "couldn't check" (R-2). The registrar check (DS-13) asks
DomainNameAPI only, even for an extension another registrar sells.

**Extension list:** the list is already the union of every registrar with a usable cost snapshot
(`ExtensionQuotes::offers()`), and the cheapest healthy registrar sells each extension (DX-2). Staging
lists only DomainNameAPI's extensions because only DomainNameAPI has synced costs. Once Spaceship,
Dynadot, Namecheap or go54 costs are synced, their extensions appear with no further change. DS-21 adds a
test that keeps it that way.

| ID | Decision |
|---|---|
| DS-14 | **Lookup order for each extension.** Each extension has a lookup mode, set on the admin Domain extensions page: `auto` (the default) means DNS, then RDAP, then port-43 WHOIS, then the registrar. **DNS** (worker only, system resolver): an NS record for the name proves it is registered, so it answers `taken` at once and saves the slower lookups. No NS record proves nothing (a name can be registered without DNS), so the chain goes on; `rdap`, `whois` or `registrar` uses that source only. In `auto`, a source is tried only when the one before it gave `unknown` or has no server for the extension. A source never turns `taken` into `available`. Only the registrar check (DS-13/DS-17) can mark a name premium or reserved, and checkout always re-checks with the registrar. |
| DS-15 | **WHOIS server discovery.** The WHOIS server for an extension is learned from IANA: one port-43 query of the extension to `whois.iana.org`, reading only the `refer:` line. It is cached in Redis for 7 days, and the last good value is kept if a query fails. A Super Admin may set a fixed server for an extension instead. Every server, learned or set, must be a DNS host name (no IP literal, port, user or path) and is always contacted on port 43. Staff cannot choose a port or an IP. |
| DS-16 | **The port-43 client** (new `WhoisClient`). It runs only in the provider worker, never in the web tier: names are queued exactly as in DS-13 (Redis, random job ID, 2-minute TTL), so the web tier gains no new egress. The worker resolves the host with its own resolver and connects only to a public IP (the same pinning rules as `ProviderHttpClient`): 3 s connect, 5 s total, 32 KiB read cap. It sends exactly `<domain>\r\n`, where the domain is built by us from a validated label and an extension we sell (DS-4). The response is never stored, logged or shown. |
| DS-17 | **Reading a WHOIS reply.** `available` only when the reply contains a known "no match" phrase. A built-in list holds the common ones (e.g. "No match for", "NOT FOUND", "No Data Found", "No entries found", "Status: free", "Domain not found"). Staff may add one plain-text phrase per extension (no regular expressions, so no ReDoS; 3–100 printable characters; case-insensitive substring). A known rate-limit or error phrase ("limit exceeded", "try again", "quota"), an empty reply or a timeout gives `unknown`. Any other non-empty reply gives `taken`. A wrong guess therefore errs towards "taken", never towards "available". |
| DS-18 | **Registrar check by extension.** The DS-13 job asks the registrar that would sell each name, the cheapest healthy one or the pinned one (DX-2/DX-3), with one bulk call per registrar per job, through each adapter's `AvailabilityChecker`. A registrar without an availability call (or one that is paused, e.g. go54 until its docs arrive) falls back to DomainNameAPI when that registrar sells the extension; otherwise the result is `unknown`. |
| DS-19 | **Limits.** All DS-6/DS-13 per-IP limits are unchanged. For each WHOIS server: at most 4 queries per second and 30 per minute across all users (Redis); over that, `unknown` with no call. For `whois.iana.org`: at most 10 discovery queries per hour. At most 6 names per job (DS-5) and at most 10 jobs per worker loop. Results are cached exactly as in DS-7 (hashed keys, 10 min / 1 min). |
| DS-20 | **Privacy (DS-8 extended).** WHOIS replies are not kept. Neither the names nor the replies are logged; logs carry only fixed codes (`whois.timeout`, `whois.no_server`, `whois.rate_limited`) and the server host. Staff see per-server counts of the last 24 h on Domain costs ("Lookup sources"). |
| DS-21 | **Admin and audit.** The lookup mode and WHOIS phrase need `catalogue.manage`; a fixed WHOIS server needs Super Admin. Every change is audited (`extension.lookup_changed`, old and new value). The admin page shows, per extension, the source that answered last ("RDAP", "WHOIS whois.nic.net.ng", "Registrar") and when. |

**Search extensions, name ideas and progressive results (CEO screenshots of WHMCS "Lookup Provider" and
"Namespinning", 31 Oct 2026).** We keep the useful parts of the WHMCS idea: staff choose which extensions are
checked with every search, and customers see up to about 20 suggestions. We do it our own way: there is no
third-party suggestion service and the customer's IP is never shared. Results also appear as they arrive
instead of after the slowest lookup.

| ID | Decision |
|---|---|
| DS-22 | **Search extensions.** A new admin setting ("Domain search" on the Domain extensions page, `catalogue.manage`) lists every extension on sale from **all** registrars with synced costs. It has a search box, "select all", "select a category" and "select all sold by registrar X". Staff tick the extensions checked alongside the one the customer typed. The featured extensions stay as they are (the cards). The default is the featured extensions plus `com.ng` and `ng`. An extension that stops being on sale (no healthy registrar cost) is skipped silently. Per search: the requested extension plus at most **12** search extensions (setting 0–20). |
| DS-23 | **Name ideas, built in.** No external AI or suggestion service, and the customer's IP is never sent anywhere. Ideas come from: (a) the same label in other extensions from the search set, cheapest first; (b) the label with a prefix or suffix from a staff-edited word list (default `get`, `my`, `try`, `the`, `hq`, `app`, `online`, `hub`, `ng`, `africa`; each word 2–12 characters, `a-z 0-9`), each in `.com` and the requested extension; (c) the label split into words where a hyphen reads better (`paxoficloud` → `paxofi-cloud` only when both parts are dictionary words from a fixed built-in list). Every idea passes the DS-4 label rule and is in an extension we sell. "Maximum ideas" is a setting: 0–30, default 20, 0 turning ideas off. **Only ideas confirmed available are shown**; taken or unknown ideas are dropped. A registrar suggestion API (e.g. Dynadot or Namecheap) or an AI idea source may be added later as one more source behind the same rules. Each would be a separate decision with its own privacy review, off by default and sending only the label. |
| DS-24 | **Progressive results.** `GET /api/v1/domains/search` answers at once with the exact name (its RDAP result when cached or fast) and the list of names being checked (search extensions and ideas, at most 1 + 20 + 30 = 51 names), each marked `checking`, plus one job ID. The provider worker checks them by the DS-14 order and writes each answer as it gets it. The page polls `GET /api/v1/domains/search/results?id=` every second for up to 20 s, then marks anything still pending as "couldn't check". Every name, idea or not, is priced by the same quote engine (DS-9). The order shown is the exact name, then search extensions in staff order, then available ideas. |
| DS-25 | **Limits for the bigger search.** The per-IP limits now count **lookups**, not searches: 300 per minute and 3,000 per hour per IP. A search costs its number of names that are not cached. Over the limit, the exact name is still checked and the rest is skipped. Global: the job queue is capped at 500 and the per-server limits of DS-19 still apply, with ideas checked last, so customers' own names never wait behind ideas. The cache (DS-7) makes repeated popular names cheap. |
| DS-26 | **Staff tools.** (a) **Test a lookup** (`catalogue.manage`): staff type a full name and see the answer from each source (RDAP, WHOIS and server used, registrar), the time taken and, for WHOIS, the raw reply. The reply is limited to 32 KiB, shown as escaped text and not stored. It is audited as `domain.lookup_tested` with the extension and sources only, never the name. (b) **Lookup health**: for each source and WHOIS server, the answers, unknowns and time-outs over 24 h, and the last error code. (c) The staff WHOIS record view (the WHMCS `DomainWhois` equivalent, used for a customer's domain) reuses (a) and needs `domains.view`. |

| ID | STRIDE | Threat | L | I | Mitigation | Test |
|---|---|---|---|---|---|---|
| DS-T13 | T/I | SSRF through a WHOIS server: a poisoned IANA `refer:` line or a staff-entered host points at an internal address | 2 | 3 | DS-15 host-name only, fixed port 43; DS-16 own resolver, public-IP pinning, worker only | `WhoisClientTest`, `WhoisServerTest` |
| DS-T14 | T | A WHOIS reply makes a taken name look available | 2 | 2 | DS-17 available only on a known phrase; everything unrecognised is taken or unknown; registrar re-check at checkout (DS-1, R-1) | `WhoisReplyTest` |
| DS-T15 | D | A slow or endless WHOIS server ties up the worker | 3 | 2 | DS-16 3 s / 5 s / 32 KiB; DS-19 per-server and per-loop caps | `WhoisClientTest` |
| DS-T16 | D | The search makes us flood a registry's WHOIS and get our IP blocked | 3 | 2 | DS-19 per-server limits, DS-7 cache, `unknown` instead of retrying | `RegistrarCheckWorkerTest` |
| DS-T17 | I | Searched names leak through WHOIS logs or our own logs | 2 | 2 | DS-20: no names or replies logged or stored. Residual: the registry sees the query, as it already does through RDAP and the registrar (R-4). | `WhoisClientTest` (log context) |
| DS-T18 | E | Command injection into the WHOIS query (CRLF, extra arguments) | 1 | 2 | DS-16: the query is built from a validated label and extension, and any CR, LF or space in the name is refused | `WhoisClientTest` |
| DS-T19 | T | A staff phrase such as "a" marks every name available | 2 | 2 | DS-17 minimum length, audit (DS-21); checkout re-check | `ExtensionLookupSettingsTest` |
| DS-T20 | D | One search fans out to 50 lookups and is used to amplify load on registries | 3 | 2 | DS-25 per-IP lookup budget, ideas last, per-server caps, cache; ideas stop at the setting | `DomainSearchTest`, `NameIdeasTest` |
| DS-T21 | I | Name ideas leak the customer's search to a third party | 1 | 2 | DS-23: generated in-house; no external call except the lookups themselves (R-4) | `NameIdeasTest` (no transport) |
| DS-T22 | T | A staff word list injects odd labels (homograph, offensive or overlong) | 2 | 1 | DS-23 word rule `a-z 0-9` 2–12 characters; every idea passes DS-4; changes audited | `NameIdeasTest` |
| DS-T23 | I | The staff WHOIS view shows registrant personal data to the wrong staff | 2 | 2 | DS-26 permission checks, nothing stored, audit without the name | `DomainLookupAdminTest` |

**Why this order (research, 31 Oct 2026).**
- **gTLDs:** since 28 January 2025, ICANN no longer requires gTLD registries to run port-43 WHOIS (.com, .name and .post excepted), and RDAP is mandatory for every gTLD. RDAP is therefore the primary source for gTLDs, and WHOIS is often switched off for them.
- **ccTLDs:** country-code registries are outside ICANN contracts, and most still run port-43 WHOIS. Fewer have RDAP, and fewer still are listed in IANA's RDAP bootstrap file. WHOIS is therefore the main fallback for ccTLDs.
- **`.ng`:** IANA's root record lists both `whois.nic.net.ng` and `https://rdap.nic.net.ng`. NiRA has redacted registrant details since June 2025, which does not affect availability.
- **DNS first:** open-source availability checkers use DNS, then RDAP, then WHOIS, returning on the first conclusive answer. That is the order of DS-14.
- **Registrar last:** the registrar check works for every extension the registrar sells, so it is the last fallback.

| ID | Decision |
|---|---|
| DS-27 | **Nobody left out: coverage report.** Admin "Lookup coverage" lists every extension on sale from all registrars. For each one it shows the working sources: DNS (always), RDAP server (from IANA bootstrap, else from the IANA root record learned with the WHOIS referral), WHOIS server, and registrar check (the selling registrar supports it). It also shows the source that answered last and when. An extension whose only source is DNS is flagged red: it can show "taken" but never "available", so it is sold only after the registrar check at checkout. A daily worker job checks one known-registered name per extension (the registry's own `nic.<tld>` where it exists) through each source and records which ones work, so the report stays true without staff action. |

**Infrastructure.** The provider worker needs outbound TCP 43. On staging the Vultr firewall filters
inbound traffic only, and the worker's `egress` network has no port filter, because "HTTPS to allow-listed
hosts" is enforced in code (`ProviderHttpClient`). Port 43 therefore needs no infra change. It is
enforced the same way: `WhoisClient` connects only to a vetted public address of a validated WHOIS host,
on port 43 only. The web tier and the payments containers do not get the WHOIS code.

**Build order:**
1. `WhoisClient`, the reply parser and IANA discovery, with tests (library only, not wired).
2. Worker job: the lookup chain (RDAP, WHOIS, registrar) with per-registrar routing (DS-18), progressive
   results (DS-24) and the lookup budget (DS-25).
3. Name ideas (DS-23) and the search-extensions setting (DS-22).
4. Admin: lookup mode, WHOIS phrase and fixed server, search extensions, idea settings, "Test a lookup"
   and lookup health (DS-21, DS-26); migration for the settings.
5. Customer search page: progressive list and ideas. Then a staging test on `.ng`, `.com.ng` and `.com`.

## 5. Residual risks

| ID | Risk | Owner / action |
|---|---|---|
| R-1 | RDAP says "available" for some reserved or premium names. | Registrar check at checkout (cart PR); the result says "available to register" and the price is confirmed at checkout. |
| R-2 | Some extensions have no RDAP server (none in IANA's file). | Port-43 WHOIS and the registrar check (§7, DS-14 to DS-18). |
| R-3 | A registry rate-limits our server IP. | DS-6 per-server cap, DS-7 cache; `unknown` instead of errors. |
| R-4 | A registry sees which names are queried (RDAP, WHOIS or registrar). Some registrars and registries have been accused of front-running searched names. | Queries come from our server IP, never with the customer's identity; we query only names the customer typed, and only extensions we sell. |

## 6. Review log

| Date | Change |
|---|---|
| 31 Oct 2026 | Step 3 (search extensions and name ideas, DS-22 to DS-24). **Search extensions:** until the admin setting (step 4), the featured extensions plus `ng` and `com.ng`, at most 12. The first 6 names get the RDAP pre-check during the search, and the rest show their cached answer or "Checking…". **Ideas:** up to 30 priced candidates go out with the search, unchecked; the page shows at most 20, only those the worker confirms available. Idea (c), hyphen splits, is dropped: it needs a dictionary and adds little. **Worker job:** {domains ≤ 13, ideas ≤ 30}. Order: DNS for all, then the registrar for searched names only (batches of 6), then RDAP, then WHOIS for whatever is still unknown, searched names first. **Ideas never go to the registrar,** so a premium idea can show the normal price until checkout re-checks it with the registrar (CD-23) and prices it. **Budgets:** 14 s per job, a run stops taking jobs after 15 s, the page waits 20 s, and the queue cap is lowered to 200 jobs. **DS-25:** the per-IP limit still counts jobs (10/min, 120/h), so at most about 430 names a minute per IP. Load on each registry stays bounded by the per-server RDAP (30/10 s) and WHOIS (4/s, 30/min) caps and the cache. |
| 31 Oct 2026 | Step 2: the worker job answers each name by DNS (NS = taken), then the registrar (one bulk call, authoritative, premium), then WHOIS for names still unknown. The registrar comes before WHOIS inside the job because it is one call and the only premium source, which also spares the per-server WHOIS budget. WHOIS gets 12 s per worker run. Per-server limit raised to 4/s (30/min) because `.ng` and `.com.ng` share one server and a search often checks both. No firewall change needed (egress filtered in code, not by network). |
| 31 Oct 2026 | §7: DNS first in the lookup order (taken only), DS-27 coverage report and daily self-test; research notes on the 2025 gTLD WHOIS sunset and ccTLD WHOIS. Build step 1 (WHOIS client, reply reader, IANA discovery) added, not wired. |
| 31 Oct 2026 | §7 DS-22 to DS-26, DS-T20 to DS-T23: search extensions chosen from all registrars, built-in name ideas (up to 20, available only), progressive results, lookup budget, staff test and health tools (CEO screenshots of WHMCS lookup provider and namespinning; "not a must we follow WHMCS"). |
| 31 Oct 2026 | §7 DS-14 to DS-21, DS-T13 to DS-T19, R-4: multi-source lookup (RDAP, port-43 WHOIS, registrar per extension), proposed before code at the CEO's request. Notes that the extension list is already the union of all synced registrars. |
| 25 Oct 2026 | DS-11: the price list was `public, max-age=300`, so a browser kept an old featured list for up to 5 minutes after staff changed it (CEO: .xyz replaced .org at position 4 but customers still saw .org). Now `no-cache` + ETag. |
| 25 Oct 2026 | DS-13: `.ng` names stayed "Couldn't check" on staging. The official client reads the answer list from `infos`, which the parser did not look in. Added `infos`, status `1`/`true`, `malformed` for a reply naming none of the asked domains, and the "Last availability check" line for staff. |
| 25 Oct 2026 | DS-13, DS-T10 to DS-T12: registrar availability and premium check through the provider worker (CEO: "build all"). |
| 25 Oct 2026 | DS-3: HEAD first, GET only when HEAD is refused (every unregistered .com showed "couldn't check"). |
| 25 Oct 2026 | DS-11, DS-T8, DS-T9: public price list and featured cards (CEO request); admin shows registrar cost before pricing settings exist. |
| 25 Oct 2026 | First version, before code. CEO chose customer domain search next (option A). |
