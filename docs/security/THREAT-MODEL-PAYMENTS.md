# Threat Model — Payments, webhooks and the ledger (Sprint 3)

| | |
|---|---|
| Epics | BE-03 Billing & payments, INT-03 Payment gateways, BE-04 Ledger |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | PAY-001…009, PAY-011, PAY-012, LED-001…004, INV-003…005, CRT-006, CRT-007, ORD-006, SEC-004, SEC-005, SEC-012, CMP-006, CMP-008; SRS §5.1 order, §5.2 payment attempt, §5.3 invoice, §6.4 payment gateway port |
| Standard | OWASP ASVS 5.0 — **Level 3** (payments, webhooks and money; CLAUDE.md §5, SRS §9.1). PCI DSS **SAQ-A**: card data never touches PaxofiCloud. |
| Status | Written **before** any payment or ledger code (26 Oct 2026). Builds on THREAT-MODEL-COMMERCE.md rev 3 (CD-21…CD-28: billing details, tax, checkout, orders, invoices) and THREAT-MODEL-PROVIDER-CONNECTIONS.md (PC-1…PC-8: how credentials get in). |

## 1. Scope

Sprint 3 turns a placed order into a paid one. This model covers:

- **Paying an invoice** through Paystack or Flutterwave hosted checkout, in NGN or USD, with the customer choosing the gateway (PAY-002).
- **Payment attempts** and their state machine (SRS §5.2), matched to invoices only by our own reference (PAY-004).
- **Webhooks** from both gateways: signature check, de-duplication, and the rule that a webhook is only a *hint* — money moves only after our own call to the gateway's verify API (PAY-005…007).
- **The ledger**: provider-independent double-entry journal, append-only, for invoice issue, payment, gateway fee, overpayment, cancellation and refund (LED-001…004).
- **Invoice and order transitions** on payment, expiry after 72 hours and customer cancellation (CRT-006, ORD-006).
- **Refunds and credit notes** with four-eyes approval above ₦500,000 / US$500 (PAY-009, INV-004, BR-17).

Out of scope: saved cards and renewal auto-charge (PAY-010, Sprint 8), provisioning after payment (Sprint 4), nightly full reconciliation against gateway transaction lists (REC-002, Sprint 9), invoice PDF layout (INV-005 gets its own section when built), additional gateways (Phase 2).

## 2. What we know about the gateways

**Flutterwave (v3)** — checked against the official docs on 26 Oct 2026 (developer.flutterwave.com: webhooks, Flutterwave Standard, transaction verification, refund reference):

| Fact | Value |
|---|---|
| Base URL and auth | `https://api.flutterwave.com/v3`, header `Authorization: Bearer <secret key>` |
| Hosted checkout | `POST /v3/payments` with `tx_ref`, `amount`, `currency`, `redirect_url`, `customer.email` (required), `customizations`, `meta` → `data.link` (`https://checkout.flutterwave.com/…`) |
| Return | Redirect to `redirect_url` with `status`, `tx_ref`, `transaction_id` query parameters (`status=failed` on cancel) |
| Verify | `GET /v3/transactions/{id}/verify` or `GET /v3/transactions/verify_by_reference?tx_ref=` → `data.status` (`successful`), `data.amount`, `data.currency`, `data.tx_ref`, `data.id`, `data.app_fee`, `data.charged_amount`. The envelope `status: "success"` only means the lookup worked. |
| Webhook | Header `verif-hash` equals the **secret hash** set in the dashboard. **No HMAC of the body is documented.** Events include `charge.completed` with `data.id`, `data.tx_ref`, `data.status`. Expects HTTP 200 within 60 s; up to 3 retries, 30 minutes apart, if enabled. **Source IPs are dynamic** (Flutterwave advises against IP allow-listing). The docs say to re-verify before giving value. |
| Refund | `POST /v3/transactions/{id}/refund` with optional `amount` (partial), returns `data.amount_refunded`, `data.status`; completes in 3–15 working days |
| Amounts | Docs show `amount` as a number in the currency's major unit (e.g. `100`, `app_fee` `1.4`); we parse it as exact decimal text, never a float (R-3) |
| Currencies | NGN default; USD hosted checkout is **not explicitly documented** (R-2) |

**Paystack** — the docs site refuses automated reads from our build environment (HTTP 403). The CEO captured the API reference pages (Introduction, Authentication, Rate limits, Pagination, Errors, Transactions, Customers, Dedicated Virtual Accounts, Settlements, Refunds, Webhook Events, Verification) on 26 Oct 2026; the Webhooks guide and the refund-status article followed the same day. Every row below is now confirmed from Paystack's own documentation:

| Fact | Value | Source |
|---|---|---|
| Base URL and auth | `https://api.paystack.co` for both modes; header `Authorization: Bearer <secret key>`; HTTPS only, TLS must be verified; 401 for a missing or wrong key | API docs |
| Test vs live | Keys prefixed `sk_test_` / `pk_test_` and `sk_live_` / `pk_live_`; the key alone decides the mode, and every transaction says `domain: test` or `live` | API docs |
| Responses | JSON `{status, message, data, meta}`; errors add `type` (`api_error`, `validation_error`, `processor_error`) and `code`. Verify and charge return **HTTP 200 even when the payment failed** — only `data.status` decides | API docs |
| Amounts | Integer **subunit** (kobo, cents); minimum NGN 50.00, USD 2.00 | API docs |
| Hosted checkout | `POST /transaction/initialize` (email, amount, currency, reference, callback_url, channels, metadata) → `data.authorization_url`, `access_code`, `reference`. Reference allows only letters, digits, `-`, `.`, `=` (our `PXC-<ULID>` fits) | API docs |
| Verify | `GET /transaction/verify/:reference` → `data.id` (unsigned 64-bit, stored as text), `domain`, `status` (`success`, `failed`, `abandoned`, …), `reference`, `amount`, `currency`, `paid_at`, `gateway_response` | API docs |
| Reconciliation | `GET /transaction?from&to` (offset or cursor pages, ≤ 1000 per page, default 50); `GET /settlement` and settlement transactions for payouts to our bank | API docs |
| Missed webhooks | `GET /integration/webhooks/events` (status Delivered/Pending/Failed, by transaction id) lets the reconciliation job find failed deliveries; resend is **not idempotent** (same event delivered twice) | API docs |
| Rate limits | Live 600 requests/60 s per endpoint (verify 3,000, initialize 1,200); test 100/60 s; 429 with `x-ratelimit-reset` seconds | API docs |
| Refund | `POST /refund` {transaction, amount (subunit, ≤ original, default full), currency, customer_note, merchant_note} → **queued** (async); `GET /refund/:id`, `GET /refund?transaction=`; a refund in `needs-attention` needs customer bank details (`/refund/retry_with_customer_details/:id`) | API docs |
| Webhook signature | Header `x-paystack-signature` = HMAC-SHA512 of the **raw event payload** keyed with the **secret key** (hex); verify before processing | Webhooks guide |
| Webhook source IPs | `52.31.139.75`, `52.49.173.169`, `52.214.14.220` — same for test and live; anything else is counterfeit | Webhooks guide |
| Webhook retries | Without a 200 OK: live retries every 3 minutes for the first 4 tries, then hourly for 72 hours; test retries hourly for 10 hours; 30 s timeout per attempt. Return 200 fast, do work after | Webhooks guide |
| Webhook events we use | `charge.success`; `refund.pending`, `refund.processing`, `refund.processed`, `refund.failed` (failed = amount credited back to our balance). Everything else is acknowledged and ignored | Webhooks guide |
| Refund states | Pending (amount deducted from our next payout; transaction → Reversal pending) → Processing → Processed (transaction → Reversed) or Failed (transaction back to Success, amount returned to payout) | Paystack support article |
| Currencies | **NGN only on our merchant account today**; USD needs Paystack to enable it (PA-5). USD goes through Flutterwave meanwhile | CEO, 26 Oct 2026 |

## 3. Assets

| Asset | Why it matters |
|---|---|
| Gateway secret keys (Paystack secret key, Flutterwave secret key) | Initialise, verify and **refund** — a stolen key can refund money out or read every transaction |
| Flutterwave webhook secret hash | The only thing that authenticates a Flutterwave webhook |
| Paystack secret key as webhook HMAC key | Same key that signs webhooks; anyone holding it can forge a Paystack webhook |
| Invoice and order state | "Paid" releases provisioning in Sprint 4: a false "paid" gives away domains and servers |
| Ledger | The accountant's record of every naira and dollar; tax filings depend on it (CMP-008) |
| Customer payment data at the gateway | Card numbers never reach us (SAQ-A); we hold email, name, amounts and gateway transaction IDs |
| Refund and credit-note authority | Money leaving the company |

## 4. Data flow and trust boundaries

```txt
Customer browser ──(1) POST /api/v1/invoices/{id}/payments {gateway} ──► web tier (no gateway keys)
        │                                                                     │ creates attempt INITIATED,
        │                                                                     │ queues "initialise" (Redis)
        │                                                                     ▼
        │                                                         payments worker (gateway keys)
        │                                                                     │ (2) POST initialize/payments ──► Gateway API
        │◄── (3) poll attempt → checkout URL (allow-listed host) ◄────────────┘
        │
        ├──(4) pays on gateway-hosted page (card/transfer/USSD; never on PaxofiCloud)
        │
        ├──(5) redirected to /billing/payments/{attempt} → page polls server state only
        │
Gateway ──(6) POST /webhooks/{paystack|flutterwave} ──► Cloudflare ──► payments-web (webhook endpoint only)
                                                                         │ signature check → 401 or
                                                                         │ record event (unique) → 200
                                                                         │ queue "verify"
                                                                         ▼
                                                         payments worker ──(7) GET verify ──► Gateway API
                                                                         │ one DB transaction:
                                                                         │ attempt SUCCEEDED, invoice PAID,
                                                                         │ order PAID, journal entry, audit
                                                                         ▼
                                                                       MySQL
```

Trust boundaries: the browser (untrusted), the gateway's webhook calls (untrusted until signed, and even then only a hint), the gateway APIs (trusted for payment status, reached only from the payments worker), the web tier (holds no gateway secret), the payments containers (hold gateway secrets, no registrar or server-provider keys).

## 5. Design decisions

| ID | Decision |
|---|---|
| PY-1 | **One port, our own adapters** (PAY-001, SRS §6.4). `PaymentGateway` in the Application layer: `initialise`, `verify`, `refund`, `verifyWebhook`. Paystack and Flutterwave adapters in Infrastructure use `ProviderHttpClient` (allow-listed hosts `api.paystack.co`, `api.flutterwave.com`, TLS verified, no redirects, timeouts, response caps). No gateway SDK is installed. Billing code never sees a gateway type. |
| PY-2 | **Gateway credentials enter like every provider credential** (PC-1…PC-8): Admin → API providers → **Payment gateways**, `provider_credentials.manage` + fresh authenticator code, sealed to the intake key, stored encrypted. New connection types: `paystack` {secret_key} and `flutterwave` {secret_key, webhook_secret_hash}. Never in chat, the repo, Actions variables or logs. |
| PY-3 | **The web tier never holds a gateway secret.** Gateway credentials are encrypted under a **separate payments key ring** (`PAYMENTS_KEK`), held only by the new `payments-worker` (initialise, verify, refund) and `payments-web` (webhook signature check) containers, both built from the same image. Neither holds the main KEK, so they cannot read registrar or server-provider keys; the main web tier, mail worker and provider worker cannot read gateway keys. The deploy agent generates `PAYMENTS_KEK` on the host. |
| PY-4 | **Environment guard on keys.** Staging accepts only gateway **test** keys (`sk_test_…`, `FLWSECK_TEST-…`); production accepts only **live** keys. The check runs at intake and again before every call; a mismatch refuses the call and alerts managers. This is the opposite of the registrar rule (DR, staging uses the live registrar key read-only): test mode is how staging exercises real payment flows safely. |
| PY-5 | **Payment attempt** (PAY-004, SRS §5.2): `payment_attempts` row created by the web tier in state `INITIATED` with reference `PXC-<ULID>` (SRS §6.4; a different shape from invoice numbers `PXC-YYYY-NNNNNN`), invoice ID, account ID, gateway, **amount and currency copied from the invoice balance at that moment**, and the customer's `Idempotency-Key` (unique per account). Two clicks with one key create one attempt and one gateway call. States: INITIATED → PENDING → SUCCEEDED / FAILED / ABANDONED / UNKNOWN / AMOUNT_MISMATCH; SUCCEEDED → REFUNDED / PARTIALLY_REFUNDED. Transitions not listed throw (Domain layer) and every transition is audited. |
| PY-6 | **Initialise through the payments worker.** The web tier queues `initialise(attempt)`; the worker calls the gateway with a **server-fixed** callback/redirect URL (`APP_URL/billing/payments/{attemptId}`), the attempt's amount and currency, the reference, and the account owner's email; it stores the returned checkout URL only if its host is `checkout.paystack.com` or `checkout.flutterwave.com` over https (otherwise the attempt fails). The browser polls `GET /api/v1/payments/{attemptId}` (account-scoped) until the URL is ready (target < 2 s), then navigates. Init errors or timeouts → FAILED, invoice stays UNPAID, the other gateway is offered (PAY-012). |
| PY-7 | **Webhook endpoint** on `payments-web` only: `POST /webhooks/paystack` and `POST /webhooks/flutterwave` (exact paths; everything else 404); body ≤ 64 KiB, JSON; **signature first**: Paystack `hash_equals(hex HMAC-SHA512(raw body, secret key), lower(x-paystack-signature))`; Flutterwave `hash_equals(secret hash, verif-hash)`. Failure → **401**, nothing stored, a rate-limited security event (`payment.webhook_rejected`, gateway + reason only) (PAY-005). Success → parse only the fields we need with ExactJson (event name, our reference, gateway transaction ID, status) and insert into `gateway_events` with a **unique** key `(gateway, event_key)` where `event_key` = SHA-256 of event name + gateway transaction ID + status (Paystack events carry no separate event ID). The row keeps the payload's SHA-256, not the payload (it contains customer email and card metadata). Duplicate → 200, nothing else. Then queue `verify(reference)` and return **200 only after the row is committed** (SRS §6.4). |
| PY-8 | **A webhook never moves money by itself** (PAY-006). The payments worker calls the verify API by **our** reference (Paystack) or by gateway transaction ID then checks `tx_ref` (Flutterwave). Paid only if **all** hold: gateway status is success (`success` / `successful`); reference equals the attempt's; currency equals the attempt's; amount equals the attempt's to the minor unit (Paystack integer subunits; Flutterwave decimal text converted exactly, more than 2 decimal places refused); the attempt belongs to an UNPAID or PARTIALLY_PAID invoice. Status success with any mismatch → `AMOUNT_MISMATCH`, money posted to **unallocated receipts** in the ledger, invoice untouched, alert to Billing. This is why Flutterwave's weaker `verif-hash` (no body integrity, PT-18) is acceptable. |
| PY-9 | **Exactly once** (PAY-007). Marking paid is **one MySQL transaction**: conditional `UPDATE payment_attempts SET state='SUCCEEDED' WHERE id=? AND state IN ('INITIATED','PENDING','UNKNOWN')` (zero rows = already done → stop), invoice → PAID, order → PAID, journal entry with a unique `source_key` (`payment:<attemptId>`), `gateway_events.processed_at`, audit `payment.succeeded`. Webhook duplicates, return-page checks and the reconciler all funnel into this one path. |
| PY-10 | **Return page shows server state only** (PAY-008). `/billing/payments/{attemptId}` polls `GET /api/v1/payments/{attemptId}`; query parameters from the gateway (`status`, `tx_ref`, `transaction_id`, `reference`) are ignored except to queue one extra `verify` (max 1 per attempt per 30 s) so a missed webhook does not leave the customer waiting. |
| PY-11 | **Pending attempts are re-verified on a schedule**: 2 min, 10 min, 1 h, 6 h, 24 h after init. No success after 24 h → ABANDONED. A verify timeout or 5xx → UNKNOWN, retried on the same schedule (bounded, no unbounded retries — PCF invariant). Full nightly transaction-list reconciliation is REC-002 (Sprint 9). |
| PY-12 | **Double payment** (two gateways, or a late payment after expiry or cancellation). The second successful payment is recorded (money is real), posted to the account's **credit** (LED-003, BR-18), Billing is alerted and the customer is emailed that they can ask for a refund. No automatic gateway refund at MVP (refunds go through PY-15 so a person decides). |
| PY-13 | **Ledger** (LED-001…004). Tables `ledger_accounts`, `journal_entries`, `journal_lines`; every entry has a unique `source_key`, a currency, a posted time, and lines with debit **or** credit in integer minor units. Balanced per entry **and per currency** (checked in code before insert, inside the same transaction, and by `ledger:verify` across all entries). Runtime DB user: `SELECT, INSERT` only — no UPDATE or DELETE (LED-002, enforced like the audit log). Corrections are reversing entries. Customer credit is a per-account sub-ledger whose balance is the **sum of its lines**, never a stored counter (LED-003). Postings: invoice issued (DR receivable / CR revenue by product kind / CR VAT payable); payment (DR gateway clearing / CR receivable); gateway fee from verify data (DR fees expense / CR gateway clearing, LED-004); overpayment (DR clearing / CR customer credit); mismatch (DR clearing / CR unallocated receipts); invoice cancelled or expired (reversal of the issue entry); refund (PY-15). Account codes are fixed in code; no chart of accounts editing at MVP. |
| PY-14 | **Invoices already issued before the ledger existed** (Sprint 2 placed orders) get their issue entry **inside migration 0025** itself (run once by the schema owner during the deploy that creates the ledger; same postings and the same `invoice_issued:<invoice id>` source key the application uses, so a later application posting for that invoice is a no-op). No separate command or deploy-agent change is needed. From then on every invoice posts its issue entry in the placing transaction, and `ledger:verify` reports any invoice without one. |
| PY-15 | **Refunds and credit notes** (PAY-009, INV-004). Staff with `billing.refund.issue` start a full or partial refund of a SUCCEEDED attempt, to the **original gateway transaction only** or to account credit; a numbered credit note `CN-YYYY-NNNNNN` (gap-free, like invoices) references the invoice. Above ₦500,000 / US$500 the refund waits in `PENDING_APPROVAL` until a **different** staff member with `billing.refund.approve` approves with a fresh authenticator code. The gateway refund call is made by the payments worker with an idempotency key; ledger entries and the attempt state move only when the gateway confirms. All audited; managers emailed on every refund. |
| PY-16 | **Expiry and cancellation** (CRT-006, ORD-006). A worker job finds UNPAID new-order invoices older than 72 h. Before expiring it verifies every non-terminal attempt of that invoice; only if none succeeded: invoice → CANCELLED, order → EXPIRED, reversing ledger entry, audit. A customer may cancel an unpaid order from the portal (session + CSRF, owner) with the same verify-first rule. A payment that still arrives later follows PY-12. |
| PY-17 | **Gateway choice and currency** (PAY-002). The pay screen offers only gateways that are connected, enabled by staff, and enabled for the invoice currency (setting per gateway: NGN on/off, USD on/off; Paystack USD off until the merchant account has it). The amount sent is the invoice balance in the invoice currency; no FX at payment time. |
| PY-18 | **Edge rules.** Cloudflare: `/webhooks/paystack` accepts only Paystack's published source IPs (WAF rule, CEO action); `/webhooks/flutterwave` cannot be IP-filtered (dynamic IPs) and relies on PY-7/PY-8. On staging the Cloudflare Access gate gets a **bypass** for exactly these two paths (IaC), otherwise test webhooks never arrive. Both paths are rate-limited at the edge and in the app. |
| PY-19 | **Data minimisation and logs.** Stored per attempt: reference, gateway, gateway transaction ID, amounts, currency, fee, channel name, timestamps, state. Not stored: card numbers, card BIN/last4, bank account numbers, raw webhook bodies, gateway responses. Logs carry reference, gateway, state and fixed reason codes only; secrets are never logged (SEC-005). |

## 6. Threats (STRIDE)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| PT-01 | S/T | Forged webhook marks an invoice paid | 3 | 3 | PY-7 signature with `hash_equals`; PY-8 verify API decides; webhook alone never moves money | PAY-005, PAY-006 | `WebhookSignatureTest`, `PaymentVerificationTest` |
| PT-02 | S/T | A genuine old webhook (another invoice, another account) is replayed | 2 | 3 | Reference binds to one attempt; verify by our reference; dedupe key; amount/currency/invoice-state checks | PAY-004, PAY-007 | `WebhookReplayTest` |
| PT-03 | T | Customer pays less than the invoice (tampered inline/hosted amount) | 2 | 3 | Server sets the amount at init; PY-8 exact amount check; mismatch → AMOUNT_MISMATCH, invoice untouched | PAY-006 | `AmountMismatchTest` |
| PT-04 | T | Currency swap (₦10,000 paid against a US$10,000 invoice) | 2 | 3 | PY-8 currency check | PAY-006 | `AmountMismatchTest` |
| PT-05 | T/S | Customer edits the return URL (`?status=successful`) to see "paid" | 3 | 2 | PY-10: return page reads server state only | PAY-008 | `PaymentReturnTest` |
| PT-06 | T | Race: webhook, return-page check and reconciler verify at once → paid twice, two ledger entries | 2 | 3 | PY-9 conditional update + unique `source_key` in one transaction | PAY-007, LED-001 | `PaymentConcurrencyIntegrationTest` |
| PT-07 | T | Two gateways both succeed for one invoice | 2 | 2 | PY-12 second payment → credit + alert; never a second "paid" | PAY-012 | `DoublePaymentTest` |
| PT-08 | I/E | A web-tier bug or RCE steals gateway secret keys and issues refunds | 2 | 3 | PY-3 separate key ring; web tier and other workers cannot decrypt; payments containers expose only two webhook paths | SEC-004, SEC-005 | `PaymentsKeyRingIsolationTest`, compose inspection |
| PT-09 | T | Live keys used on staging (real charges in tests) or test keys in production (fake payments accepted) | 2 | 3 | PY-4 key-prefix guard at intake and before every call | SEC-004 | `GatewayEnvironmentGuardTest` |
| PT-10 | D | Webhook flood fills the database or the queue | 2 | 2 | Body cap, edge + app rate limits, signature before any DB write, dedupe; 401s logged with rate limiting | PAY-005 | `WebhookLimitsTest` |
| PT-11 | T | Checkout URL from a compromised/MITM response sends the customer to a phishing page | 1 | 3 | TLS-verified pinned hosts (ProviderHttpClient); PY-6 checkout host allow-list | PAY-003 | `CheckoutUrlAllowListTest` |
| PT-12 | T/R | Ledger rows edited or deleted to hide money | 1 | 3 | PY-13 append-only grants, balanced entries, `ledger:verify`, reversal-only corrections, audit chain | LED-001, LED-002 | `RuntimeGrantsIntegrationTest`, `LedgerBalanceTest` |
| PT-13 | T | Payment for an expired or cancelled invoice revives the old price | 2 | 2 | PY-16 verify-before-expire; late payment → credit (PY-12), order not revived | CRT-006 | `ExpiryRaceTest` |
| PT-14 | I | Account A reads account B's payment attempt or checkout URL | 2 | 2 | Attempt IDs 128-bit random; every query scoped by session account; 404 for others | ACC-002 | `PaymentIsolationTest` |
| PT-15 | I | Customer PII or card metadata leaks through logs or stored webhook bodies | 2 | 2 | PY-19: payload hash only, minimal fields, redacted logs | CMP-006, NDPA | `GatewayEventStorageTest`, log inspection |
| PT-16 | E/T | A single staff member refunds large sums to an accomplice | 2 | 3 | PY-15: refund only to the original transaction or account credit; four-eyes above threshold with fresh code; audit + manager email | PAY-009 | `RefundApprovalTest` |
| PT-17 | R | Disputes over who paid, refunded or cancelled what | 2 | 2 | Every attempt/invoice/order transition and refund audited (hash chain); ledger source keys | AUD-001 | `PaymentAuditTest` |
| PT-18 | S | Flutterwave `verif-hash` is a static shared secret with no body integrity; a leaked hash allows forged webhooks | 2 | 2 | Long random hash (≥ 32 chars, generated by us, entered by the CEO); PY-8 makes a forged webhook harmless (it only triggers a verify); rotate on suspicion | PAY-005 | `WebhookSignatureTest` |
| PT-19 | S | Webhook replay outside a time window (neither gateway sends a signed timestamp) | 2 | 1 | Dedupe (PY-7) + verify-is-truth (PY-8) + exactly-once (PY-9); see R-4 | PAY-007 | `WebhookReplayTest` |
| PT-20 | T | Attacker changes the callback/redirect URL at init (open redirect, token leak) | 1 | 2 | PY-6: URL fixed server-side from configuration; no client-supplied URLs | PAY-008 | `PaymentInitTest` |
| PT-21 | D | Gateway outage leaves invoices stuck or corrupts state | 2 | 2 | PY-6/PY-11: FAILED/UNKNOWN attempts never touch invoice state; other gateway offered; bounded re-verification | PAY-012 | `GatewayOutageTest` |
| PT-22 | T | Ledger imbalance from a code bug (debits ≠ credits) | 2 | 2 | PY-13: balance check per entry per currency before commit (transaction fails); `ledger:verify` nightly and in CI integration tests | LED-001 | `LedgerBalanceTest` |

## 7. Sprint 3 build order and tests

1. This threat model (no code).
2. **Ledger core**: accounts, entries, lines, balance check, append-only grants, `ledger:verify`, invoice-issue postings in the placing transaction + backfill in migration 0025 (PY-13, PY-14).
3. **Gateway port and adapters**: Paystack and Flutterwave clients against recorded fixtures, environment guard, payment gateway connections and settings (PY-1…PY-4, PY-17).
4. **Payments runtime**: `payments-worker` and `payments-web` containers, `PAYMENTS_KEK`, attempts, pay API, webhook endpoint, verify and mark-paid transaction, re-verification schedule, Access bypass for webhook paths (PY-5…PY-11, PY-18).
5. **Portal**: pay button and gateway choice on invoices/checkout, return page polling.
6. **Expiry, cancellation, double payment** (PY-12, PY-16).
7. **Refunds and credit notes** (PY-15).

Every PR carries happy-path, failure and security-negative tests from the table above, PHPStan max, Semgrep, gitleaks, and at least one mutation check on its central rule. Before step 3 merges, both adapters run once against the gateways' **test** mode on staging (R-1, R-2).

## 8. Residual risks

| ID | Risk | Plan |
|---|---|---|
| R-1 | Paystack facts now come from Paystack's own docs (captured by the CEO), not a live read by us | Still run one test-mode initialise → pay → webhook → verify on staging before the adapter merges: a real test webhook must pass the HMAC check and a one-byte change must fail it |
| R-2 | Flutterwave USD hosted checkout is not explicitly documented | Test a USD payment in Flutterwave test mode; until it works, USD is offered only through gateways where it is proven (PY-17 setting stays off) |
| R-3 | Flutterwave `amount` unit and `phone_number`/`phonenumber` naming differ between doc pages | Amounts parsed as exact decimal text and cross-checked in the test-mode run; phone not sent (not required) |
| R-4 | Neither gateway signs a timestamp, so no replay window (CLAUDE.md §5 asks for one) | Accepted: dedupe + verify-is-truth + exactly-once make replays harmless; revisit if a gateway adds signed timestamps |
| R-5 | Paystack source-IP filter (3 published IPs, §2) depends on a Cloudflare WAF rule the CEO adds | Listed in the rollout steps; the app is safe without it (PY-7/PY-8), the rule only cuts noise |
| R-6 | Domain VAT treatment still awaits the accountant (CR-7) | Tax is data (CD-28); ledger posts whatever the invoice carries |
| R-7 | Paystack refunds are asynchronous (pending → processing → processed/failed) and can stop in `needs-attention` | Refund state follows the gateway and the `refund.*` webhooks (each one still confirmed by fetching the refund); a credit note is issued when the refund is approved, the ledger posts the payout only when the gateway reports it processed; `needs-attention` alerts Billing |

## 9. CEO actions

| ID | Action | When |
|---|---|---|
| PA-1 | Paystack: test mode secret key; set the test webhook URL to `https://cloud-staging.paxofi.com/webhooks/paystack` | Before step 4 is deployed |
| PA-2 | Flutterwave: test mode secret key; generate a long random secret hash (I will give a one-line command), set it and the webhook URL `…/webhooks/flutterwave` in the dashboard; enable webhook retries | Before step 4 is deployed |
| PA-3 | Enter both under Admin → API providers → Payment gateways (never in chat) | After step 3 is deployed |
| PA-4 | Cloudflare WAF rule: `/webhooks/paystack` only from `52.31.139.75`, `52.49.173.169`, `52.214.14.220` (staging and production) | Before production |
| PA-5 | Ask Paystack to enable USD collection (account is NGN-only today); Paystack USD stays off in PY-17 until confirmed | Before Paystack USD is switched on |

## 10. Review log

| Date | Change | By |
|---|---|---|
| 2026-10-26 | Baseline, written before any payment or ledger code. Flutterwave facts checked against official docs; Paystack facts from SRS §6.4 (R-1). | Claude (security engineering) |
| 2026-10-26 | Paystack facts confirmed from CEO captures of the API reference and Webhooks guide (signature, source IPs, retries, refund events); Paystack NGN-only (PA-5); refunds async (R-7); dedicated virtual accounts recorded as future scope (§11). | Claude (security engineering) |
| 2026-10-26 | PY-14: backfill moved into migration 0025 (no deploy-agent change); fixed chart adds per-gateway clearing accounts; reversals keyed `reversal:<entry id>` so an entry is reversed at most once. | Claude (security engineering) |
| 2026-10-26 | Step 3a (adapters): the port has `initialise`, `verify`, `webhookHint`; `refund` joins it in step 7 with the refund flow. Paystack verify also checks `data.domain` (test/live) against the environment, a second PY-4 check after the key prefix. Checkout URLs must be https on the gateway's own checkout host (Paystack `checkout.paystack.com`; Flutterwave `checkout.flutterwave.com`, test host confirmed on staging under R-2; unknown hosts fail closed). Provider request paths may now contain upper-case letters (Paystack verify path carries `PXC-<ULID>`); traversal, query, encoding and whitespace stay refused. | Claude (security engineering) |
| 2026-10-31 | Step 3b (gateway connections): gateway keys enter through the existing intake table but are sealed to a separate **payments intake key**; the provider worker reads only non-gateway rows and the payments worker only gateway rows, and each refuses a payload for the other lane. They are stored in their own table `payment_gateway_credentials` under the **payments key ring** (runtime user: SELECT/INSERT/UPDATE, no DELETE), so rotating or listing provider credentials never touches them. PY-4 is also checked at intake (wrong-mode key refused, nothing stored). One `payments-worker` container for now; the webhook endpoint (`payments-web`) comes with step 4. | Claude (security engineering) |
| 2026-10-31 | Step 4a (attempts and hosted checkout): migration 0027 `payment_attempts`; runtime user may UPDATE only `state`, `checkout_url`, `failure_reason`, `claimed_at`, `state_changed_at`. Customer API `GET /api/v1/payments/options?order_id=`, `POST /api/v1/payments` {order_id, gateway} + Idempotency-Key (owner only, 10 per hour per account, at most 5 open attempts per invoice), `GET /api/v1/payments/detail?id=`; all account-scoped in SQL. The payer email is the initiating owner's, read by the worker at claim time and not stored on the attempt. The worker claims with a conditional UPDATE; an attempt not started within 10 minutes fails as `stale`, and a claim left unfinished for 5 minutes fails as `interrupted` without a second initialise (a reference is never sent twice). PY-17 for now: a gateway is offered when it has a credential for this environment and the invoice is NGN; USD stays off (PA-5, R-2) and the staff on/off switch comes with the settings screen. Balance = invoice total until PY-9 records payments. | Claude (security engineering) |
| 2026-10-31 | Step 4b-i (verify and mark paid, before webhooks): migration 0028 adds settlement columns (`gateway_transaction_id` unique per gateway, `paid_minor`/`paid_currency`, `fee_minor`, `paid_at`) and the check schedule (`checks`, `next_check_at`); runtime UPDATE extended to exactly those columns. The payments worker verifies each pending/unknown attempt at 2 min, 10 min, 1 h, 6 h and 24 h after creation (PY-11). Any non-success answer, including `failed`, waits for the next check (a customer may retry on the same checkout page), so a later success is never shut out by an early `failed`; after the fifth check the attempt is closed as failed or abandoned. PY-9 is one transaction whose single winner is the conditional attempt UPDATE (no `FOR UPDATE`, which column-level grants may not allow): invoice and order → paid only if the invoice is still unpaid; otherwise the money is posted to customer credit (PY-12, `payment.credited` + log for Billing); the fee is posted when reported in the same currency. A mismatch posts to unallocated receipts and leaves the invoice unpaid. A recording error rolls back and is retried on the schedule, not every loop. A success after an attempt was closed (late payment) is left to the PY-12/PY-16 step. Webhooks (PY-7) follow in 4b-ii and only bring a check forward. | Claude (security engineering) |
| 2026-10-31 | Step 4b-ii (webhooks): `payments-web` is the php image with `APP_ROLE=payments-web`; its router has exactly the two webhook routes (every other path, including `/health` and `/api`, is 404), it holds only the payments key ring (no intake keys, no provider KEK, no web or mail secrets) and has no egress. nginx sends exactly `POST /webhooks/(paystack|flutterwave)` (64 KiB) to it; the web tier and admin host never answer them. Access bypass = two path-scoped Access apps with a `bypass` policy (IaC). Migration 0029 `gateway_events` (runtime SELECT/INSERT only): `event_key` = SHA-256 of event name, our reference and the payload SHA-256 (neither gateway gives an event ID; a retry resends the same body, so it dedupes); the payload itself is never stored (PT-15). Recording the event and setting the attempt's `next_check_at` to now are one transaction committed before 200; the verifier then decides (PY-8). A webhook-triggered check counts towards the five scheduled checks, so many genuine non-success webhooks can close an attempt before 24 h; accepted (both gateways send success events only for completed charges, and a late success is handled with PY-12). Responses: 200 recorded/duplicate/ignored, 401 signature missing or invalid, 400 unreadable, 503 when the gateway key cannot be loaded (gateway retries). Rejections audited at most once a minute per gateway and reason. A repeated header (e.g. two `verif-hash` values) is not trusted. | Claude (security engineering) |

## 11. Future scope: dedicated virtual accounts (not MVP)

A bank account number per customer, shown in the dashboard, that the customer can pay into directly at any time. Paystack offers it (`/dedicated_account`, `/dedicated_account/assign`; NGN only; banks such as Wema or Titan). CBN rules mean every provider (Paystack, Flutterwave, Monnify and others) needs the customer's **BVN or NIN** and validated names and phone first; Paystack's customer validation is asynchronous and reports its result by webhook. BVN and NIN are sensitive personal data under the NDPA 2023, so this feature needs its own threat model before any code: whether we store BVN at all (preferred: pass it straight to the provider and keep only the provider's customer code and validation result), consent and retention, who may see it, and how money arriving in a virtual account maps to invoices and credit (the PY-8 verify rule and the ledger apply unchanged).
