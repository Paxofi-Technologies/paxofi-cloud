# Threat Model — Transactional Email

| | |
|---|---|
| Epics | BE (Identity, Sprint 1), LIF-01 notifications (Sprint 8) |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | IAM-001, IAM-002, IAM-011, IAM-012, IAM-015, IAM-016, NFR-014 |
| Standard | OWASP ASVS 5.0 Level 2; **Level 3** for the provider API tokens |
| Status | Baseline, written **before** the email code. Closes Identity threat model open item O-4. |
| Decision | CEO, 2026-10-14: **Postmark is primary, Resend is secondary**, switchable at any time. CEO, 2026-10-17: add **SMTP through our own mail server** as a third option, and use it on staging until Postmark and Resend are set up (E-13). |

Email carries the one-time links for verification, password reset and email
change. It is the customer's recovery root (Identity threat model §4), so a
leak, a forged message or a link pointing at the wrong host is an account
takeover.

## 1. Data flow and trust boundaries

```mermaid
flowchart LR
  W["Web tier (PHP-FPM)"] -- "E1: enqueue (payload sealed to worker public key)" --> DB[("MySQL email_outbox")]
  K["Mail worker"] -- "E2: claim + decrypt" --> DB
  K -- "E3: HTTPS, allow-listed host" --> P["Postmark API"]
  K -. "failover" .-> R["Resend API"]
  P --> M["Customer mailbox"]
  R --> M
```

- **E1:** the web tier never talks to an email provider and never holds provider tokens.
- **E2:** only the worker holds the provider tokens and the secret key that opens outbox payloads.
- **E3:** outbound HTTPS goes through `ProviderHttpClient`, with allow-listed hosts, TLS verification and timeouts.

## 2. Design decisions

| ID | Decision |
|---|---|
| E-1 | **One port, two adapters.** `TransactionalEmailSender` is implemented by `PostmarkEmailSender` and `ResendEmailSender`. `EMAIL_PROVIDER=postmark\|resend` picks the primary. The other provider is the failover, used only on a timeout, a 5xx response or a 429 after the primary's bounded retries. Never on a 4xx, which is a request we must fix, not retry elsewhere. |
| E-2 | **Transactional outbox.** The web request writes one `email_outbox` row in the same database transaction as the action that needs the email (e.g. creating the account). The worker sends it. A crash between "account created" and "email sent" therefore never loses or duplicates the intent. |
| E-3 | **Sealed payload, purged after send.** Outbox payloads contain raw one-time tokens. The web tier encrypts each payload with libsodium `crypto_box_seal` to the **worker's public key** (X25519 + XSalsa20-Poly1305). The web tier holds only the public key, so a compromised web container can enqueue mail but can never read the queue. The worker holds the secret key, decrypts the payload, and checks that the decrypted outbox id and template match the row, which binds the ciphertext to its row. The ciphertext is deleted once the message is accepted or dead-lettered. This keeps Identity D-2 true: at rest, the database holds token hashes only. The deploy agent generates the keypair on the host on first release (like the database passwords); it never passes through CI. |
| E-4 | **Bounded retries.** At most 5 attempts with exponential backoff and jitter (1 min to 30 min), then dead-letter and an operator alert. There are no unbounded retries (PCF invariant). |
| E-5 | **Idempotency.** Each send carries the outbox row id as the provider's idempotency key (Resend `Idempotency-Key`; Postmark `Metadata` plus our claim lock). The worker claims rows with `SELECT … FOR UPDATE SKIP LOCKED`. |
| E-6 | **Credentials.** `POSTMARK_SERVER_TOKEN` (one Postmark *Server* per environment) and `RESEND_API_KEY` (sending-only, restricted to our domain) are GitHub **environment secrets**. The deploy agent receives them over the Access-gated tunnel on stdin, writes them to `/etc/paxoficloud/worker.env` (mode 0600), and passes them to the worker container only. They are never put in images, the web tier, logs or the repository. Rotation is a secret update followed by a deploy. |
| E-7 | **Egress.** `ProviderHttpClient` allow-lists exactly `api.postmarkapp.com` and `api.resend.com`, with its existing DNS pinning, non-public-IP deny, TLS 1.2+ and size caps. |
| E-8 | **Links and content.** Links are built only from the configured `APP_URL`. The request `Host` header is never trusted (Identity T-26). Templates live in the repository as plain text plus minimal HTML. Every variable is HTML-escaped, and no customer-supplied HTML is ever rendered. |
| E-9 | **Staging cannot email strangers.** Outside production, the worker delivers only to addresses in `EMAIL_RECIPIENT_ALLOWLIST`. It drops (and logs) every other address, so test data can never reach real people. |
| E-10 | **Logging.** Logs record only the outbox id, template name, provider, provider message id, outcome and a SHA-256 of the recipient. They never contain tokens, links, bodies or plain addresses. |
| E-11 | **Domain authentication.** Mail is sent from `no-reply@paxofi.com`. Postmark and Resend each publish DKIM for the domain, and use a custom Return-Path or bounce subdomain so SPF alignment does not touch the root SPF record that the existing mailboxes depend on. DMARC starts at `p=none` with reports and moves to `quarantine` once both providers align. |
| E-13 | **SMTP relay option.** `SmtpEmailSender` implements the same port, so `EMAIL_PROVIDER=smtp` switches to it and the failover rules of E-1 still apply (SMTP 4xx = transient; 530/534/535/538 = configuration; other 5xx = rejected). Our own minimal SMTP client (no new dependency) gives the worker the same egress controls as `ProviderHttpClient`: the host is resolved by us, every address must be public, and the connection is pinned to it. Only ports **465** (implicit TLS) and **587** (STARTTLS) are allowed. TLS 1.2+ with certificate and host-name verification is mandatory: if the server does not offer STARTTLS the client stops **before** sending credentials, so a downgrade cannot expose the password. `AUTH PLAIN`/`LOGIN` happens only inside TLS. `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME` and `SMTP_PASSWORD` are environment secrets delivered to the worker only, exactly like E-6, and the host stays out of the public repository. Error messages carry only SMTP reply codes, never the server's reply text, which can echo addresses (E-10). **Certificate name (2026-10-17):** on shared cPanel hosting the mail certificate often covers only the apex domain (`example.com`), while the apex itself sits behind the Cloudflare proxy and cannot carry SMTP. Optional `SMTP_TLS_NAME` lets the worker connect to the DNS-only host (`mail.example.com`) and verify the certificate against the apex instead. It must equal `SMTP_HOST` or be a parent domain of it, so it can only relax the name to our own zone, never to an unrelated name; verification itself (chain, expiry, name) stays mandatory. |
| E-12 | **Bounces and complaints.** Until the bounce webhooks are built (Sprint 8, with HMAC and replay window per the webhook baseline), both providers' own suppression lists stop delivery to hard-bounced or complaining addresses. |

## 3. Threats (STRIDE)

| ID | Threat | Element | Mitigation | Test |
|---|---|---|---|---|
| M-01 | **I:** raw verification or reset tokens readable in a database dump, or by a compromised web tier | Outbox | E-3 sealed to the worker key, then purged | `EmailOutboxEncryptionTest` (stored bytes contain no token; a payload moved to another row is rejected; the web tier cannot open it) |
| M-02 | **I:** a provider token leaks from the web tier or the logs | Web, logs | E-6, E-10 | `WebTierHasNoEmailCredentialsTest` (container env); log redaction test |
| M-03 | **S:** a forged email from our domain is used for phishing | DNS | E-11 DKIM, SPF alignment, DMARC | Manual DNS check at go-live; DMARC reports reviewed monthly |
| M-04 | **T:** a reset link points at an attacker host (Host-header injection) | Templates | E-8 | `ResetLinkBaseUrlTest` |
| M-05 | **T:** HTML or script injected through a customer's name into the email | Templates | E-8 escaping | `EmailTemplateEscapingTest` |
| M-06 | **D:** a bot sign-up storm burns the provider quota or reputation | Outbox, worker | Identity T-02 rate limits, plus a global per-hour send cap in the worker | `EmailGlobalCapTest` |
| M-07 | **D:** primary provider outage blocks sign-ups and resets | E3 | E-1 failover, E-4 retries | `EmailFailoverTest` (5xx→secondary; 4xx→no failover) |
| M-08 | **R:** cannot prove an email was sent, or why it was not | Worker | E-10 records outcome and provider id per row; dead letters retained 30 days | `EmailOutcomeRecordedTest` |
| M-09 | **I:** staging emails reach real people | Worker | E-9 allow-list | `NonProductionRecipientAllowlistTest` |
| M-10 | **E:** SSRF through the email HTTP client | E3 | E-7 allow-list and the existing `ProviderHttpClient` controls | Existing `ProviderHttpClient` tests plus host allow-list test |
| M-11 | **I:** duplicate sends confuse users or reveal retries | Worker | E-5 claim lock and idempotency key | `EmailIdempotencyTest` |
| M-12 | **I:** SMTP password sent in clear after a STARTTLS downgrade or to a server with a bad certificate | E3 (SMTP) | E-13 mandatory STARTTLS before AUTH; verified TLS 1.2+ | `SmtpEmailSenderTest` (no STARTTLS → no AUTH line sent; AUTH only after TLS) |
| M-13 | **T:** header or SMTP command injection through recipient, subject or body (e.g. a lone `.` ending DATA early) | E3 (SMTP) | Validated `EmailAddress` and one-line subject; CRLF-normalised quoted-printable bodies; dot-stuffing | `SmtpMimeMessageTest`, `SmtpEmailSenderTest` |
| M-14 | **E:** SSRF through `SMTP_HOST` pointing at an internal service | E3 (SMTP) | E-13 own DNS resolution, non-public deny, port allow-list 465/587 | `SmtpServerTest`, `StreamSmtpConnectorTest` |

## 4. Residual risks

- **Duplicate on failover.** If Postmark accepts a message but the response is lost, the Resend failover may deliver it again. The customer can receive two identical emails. That is acceptable, because the tokens stay single-use.
- **Own SMTP deliverability.** Mail sent through our hosting mail server shares that server's IP reputation and has no provider suppression list, so bounces must be watched by hand until Postmark or Resend is enabled. It is meant for staging and low volume; production uses Postmark with SMTP as a fallback at most.
- **Provider sees content.** Both providers process the email body, which includes one-time links. This is mitigated by short token lifetimes (24 h verification, 30 min reset) and single use. It is recorded as a processor in the NDPA data map.

## 5. Open items (CEO)

| ID | Item |
|---|---|
| EM-1 | **Postmark:** create a Server "PaxofiCloud Staging", add the `paxofi.com` sender domain (DKIM plus Return-Path DNS records in Cloudflare), and request account approval. New accounts can only send to their own domain until approved. Store the Server API token as staging secret `POSTMARK_SERVER_TOKEN`. |
| EM-2 | **Resend:** add domain `paxofi.com` (DNS records via Resend's Cloudflare auto-setup), create an API key with *Sending access* restricted to that domain, and store it as staging secret `RESEND_API_KEY`. |
| EM-4 | **SMTP (staging, now):** create a mailbox such as `no-reply@paxofi.com` on the paxofi.com mail server, then add staging secrets `SMTP_HOST` (the server's TLS host name, e.g. the one in the cPanel "Connect devices" page), `SMTP_PORT` (465 or 587), `SMTP_USERNAME` and `SMTP_PASSWORD` (no spaces). Check that the domain's SPF record includes that server and that DKIM is enabled for it. |
| EM-3 | Confirm the sender address `no-reply@paxofi.com` and the display name "PaxofiCloud". |

## 6. Review log

| Date | Change |
|---|---|
| 2026-10-14 | Baseline written before any email code. Postmark primary and Resend secondary (CEO decision). |
| 2026-10-17 | E-13 `SMTP_TLS_NAME` (verify against a parent domain of `SMTP_HOST`), after staging showed the cPanel certificate covers only the proxied apex. |
| 2026-10-17 | E-13 SMTP relay option, M-12..M-14, EM-4, written before the SMTP code (CEO decision: SMTP on staging for now). |
