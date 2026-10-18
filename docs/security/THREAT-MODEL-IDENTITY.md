# Threat Model — Identity, Sessions, Tenant Isolation and Audit (Sprint 1)

| | |
|---|---|
| Epic | Identity & access (SEC-01 / SEC-02 / BE-06) |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | IAM-001…016, IAM-017 (sign-in notifications; new, pending SRS change record), ACC-001…003, ACC-005, AUD-001…004, API-002…008, SEC-011 |
| Standard | OWASP ASVS 5.0 — Level 2; Level 3 for staff authentication and the admin console |
| Status | Baseline for Sprint 1. Must be re-reviewed at Sprint 1 planning and updated at sprint end. |

This document is written **before** any identity code exists. Every Sprint 1
pull request touching identity must cite the threat IDs (`T-xx`) it mitigates
and add the matching tests listed in §7. A threat is only "mitigated" when its
test exists and passes in CI.

---

## 1. Scope

In scope:

- Customer registration, email verification, sign-in, sign-out.
- Passwords (policy, storage, reset), TOTP MFA and recovery codes.
- Server-side sessions, CSRF, step-up authentication, email change.
- Staff authentication with WebAuthn and staff role-based access control.
- Tenant isolation (identity → account → resources).
- Security audit log and log redaction.
- Edge controls that protect these flows: rate limiting, bot protection,
  security headers, CORS.

Out of scope (own threat models later): payments and webhooks (Sprint 3),
provider credentials (Sprint 3), provisioning (Sprint 4), the admin console's
business functions (Sprint 9).

## 2. Assets

| Asset | Why it matters | Classification |
|---|---|---|
| Customer credentials (password hash, TOTP secret, recovery codes) | Account takeover → domains, servers, money | Restricted |
| Session identifiers and CSRF tokens | Bearer access to an account | Restricted |
| One-time tokens (verify, reset, email-change, staff invite) | Bearer access to a specific action | Restricted |
| Staff WebAuthn credentials and staff sessions | Access to every customer | Restricted (L3) |
| Identity PII (name, email, phone, IP history) | NDPA 2023 personal data | Confidential |
| Account ownership (identity ↔ account ↔ resources) | Basis of tenant isolation | Integrity-critical |
| Audit log | Evidence for disputes, fraud and incident response | Integrity-critical |
| Encryption keys (TOTP secret key, audit/HMAC keys) | Protect the assets above | Restricted; environment secrets only |

## 3. Data-flow diagram and trust boundaries

```mermaid
flowchart LR
  subgraph Internet["Untrusted: Internet"]
    C["Customer browser<br/>portal SPA"]
    S["Staff browser<br/>admin SPA"]
    A[Attacker]
  end
  subgraph Edge["Boundary 1: Cloudflare edge"]
    WAF["WAF · TLS · Turnstile<br/>Access: admin only"]
  end
  subgraph App["Boundary 2: application network"]
    N[Nginx]
    API["PCF API<br/>PHP-FPM"]
    R[(Redis<br/>sessions · rate limits)]
    DB[(MySQL<br/>identities · accounts · audit)]
    W["Queue worker<br/>email jobs"]
  end
  E[Email provider]
  H["Breached-password<br/>range API"]
  C -->|HTTPS| WAF
  S -->|HTTPS + WebAuthn| WAF
  A -.-> WAF
  WAF --> N --> API
  API <--> R
  API <--> DB
  API -->|enqueue| W -->|"Boundary 3: outbound"| E
  API -->|"k-anonymity prefix only"| H
```

Trust boundaries:

1. **Internet → edge.** Everything from a browser is untrusted, including the
   frontend SPAs, which are public code.
2. **Edge → application.** Origin accepts traffic only from Cloudflare (origin
   firewall and authenticated origin pulls). The API never trusts client IP
   headers other than `CF-Connecting-IP`, and only on that path.
3. **Application → third parties.** Outbound calls go only to allow-listed
   hosts with timeouts and size caps, and never carry secrets beyond what the
   call needs.

## 4. Assumptions

- PHP 8.4+, libsodium and `password_hash` are trustworthy; we never implement
  cryptographic primitives ourselves (company rule).
- Cloudflare terminates TLS in Full (strict) mode; the origin certificate is
  valid (SEC-007).
- Redis and MySQL are reachable only from the application network, require
  authentication, and are not exposed publicly (SEC-013).
- The email channel is the customer's recovery root. Its compromise is a
  residual risk (R-1) reduced by MFA, not eliminated.
- At MVP one identity owns exactly one account (ACC-001); team membership is
  Phase 2 but the authorizer already checks membership, so the model will not
  change.

## 5. Design decisions this model fixes

These are binding for Sprint 1 implementation. Changing one requires updating
this document in the same PR.

| ID | Decision |
|---|---|
| D-1 | **Random values:** every token, session ID and recovery code comes from `random_bytes()` with ≥ 256 bits of entropy (recovery codes: ≥ 80 bits, human-readable). |
| D-2 | **Token storage:** one-time tokens and session IDs are stored only as `SHA-256(token)`; lookup is by hash; the raw value exists only in the email or cookie. A leaked database or Redis dump therefore yields no usable tokens. |
| D-3 | **Passwords:** `password_hash($p, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1])`, `password_needs_rehash` on every successful sign-in. The algorithm is set explicitly; framework defaults are never relied on. |
| D-4 | **TOTP secrets:** 160-bit secrets, encrypted at rest with `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`, key from environment, ciphertext carries a key ID for rotation, the identity ID is bound as associated data. TOTP verification implements RFC 6238 (HMAC-SHA1, 30 s step, ±1 step) with `hash_equals`, and stores the last accepted time-step to block replay. Unit tests use the RFC 6238 test vectors. |
| D-5 | **Recovery codes:** 10 codes, stored as keyed hashes (`sodium_crypto_generichash` with a server key), single-use, regenerating them invalidates the old set. |
| D-6 | **Sessions:** server-side in Redis under key `sess:{SHA-256(sid)}`; cookie `__Host-pc_sid` (Secure, HttpOnly, SameSite=Lax, Path=/). Staff use a separate cookie `__Host-pc_staff`, a separate Redis prefix and a separate origin; a customer session is never accepted on admin routes and vice versa. Session ID rotates on sign-in, MFA completion, step-up, password change and role change. |
| D-7 | **Uniform responses:** registration, sign-in and password reset return the same response and comparable timing whether or not the email exists. Sign-in for an unknown email runs `password_verify` against a fixed dummy Argon2id hash. |
| D-8 | **Password reset does not bypass MFA.** After a reset the customer must still pass TOTP (or use a recovery code) to sign in. Reset revokes all sessions. |
| D-9 | **No SMS for authentication.** Phone numbers are for contact only, which removes SIM-swap as an attack path. |
| D-10 | **WebAuthn verification uses a vetted, minimal open-source library**, not our own CBOR/COSE/attestation parsing (company rule against rolling our own crypto; a library is not a framework). Required settings: `userVerification=required`, RP ID pinned to the admin origin, sign-count checks, ES256 and EdDSA only. **Library choice is an ADR in Sprint 0** (open item O-1). |
| D-11 | **Staff onboarding:** staff accounts are created only by invitation from a Super Admin. The invite is a single-use 24-hour link that leads directly to WebAuthn enrolment. Every staff member registers at least two authenticators. No self-signup, no password fallback. |
| D-12 | **No impersonation at MVP.** Support staff never "log in as" a customer; they act through audited admin endpoints. |
| D-13 | **Authorization order:** authenticate → load session → resolve the account from the session (never from the request) → `AuthoritativeTenantAuthorizer::assertCanAccess` → load the resource **scoped by account ID in the query** → act. A resource owned by another account returns **404**, not 403, so existence is not revealed. |
| D-14 | **Audit log:** append-only table; the application DB user has `INSERT, SELECT` only; each row stores `hash = SHA-256(prev_hash ‖ canonical_json(event))`. The current chain head is exported daily to storage outside the database (open item O-2) so a database administrator cannot silently rewrite the chain. |

## 6. Threats (STRIDE)

Ratings: **L** likelihood and **I** impact on a 1–3 scale; risk = L × I.
"Test" names the CI test that proves the mitigation (§7).

### 6.1 Registration and email verification (IAM-001, IAM-002, IAM-016)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-01 | I | Account enumeration: "email already registered" reveals customers | 3 | 2 | D-7: always "check your email"; an existing address receives a "someone tried to register" email instead | IAM-001 | `RegistrationEnumerationTest` |
| T-02 | D | Bot mass-registration exhausting email quota or polluting data | 3 | 2 | Turnstile verified server-side (fail closed), per-IP limit 5/h, email sending via queue with a global cap | IAM-016, API-004 | `RegistrationRateLimitTest` |
| T-03 | S | Verification token guessed or reused | 1 | 2 | D-1, D-2; single-use, 24 h expiry; a new token invalidates older ones | IAM-002 | `EmailVerificationTokenTest` |
| T-04 | T | Mass assignment, e.g. `{"role":"admin","account_id":…}` in the body | 2 | 3 | Strict input schema that rejects unknown fields; role and account are never accepted from the client | API-002 | `RegistrationSchemaTest` |
| T-05 | I | Unverified identity places orders or receives services | 2 | 2 | `verified_at` is checked on checkout; unverified identities have read-only scope | IAM-002 | `UnverifiedCannotOrderTest` |
| T-46 | S | **Account pre-hijacking:** an attacker registers a victim's email first, so the account later verified by the victim carries the attacker's password | 1 | 3 | A pending (unverified) identity is overwritten by the latest registration (name, phone, password) and every older verification token is deleted, so only the newest submission can ever be activated; per-email limit 3/h makes racing the victim impractical; unverified identities cannot sign in (sign-in PR); the "verified" step does not reveal the account's password or details | IAM-001, IAM-002 | `RegistrationPreHijackTest` |
| T-47 | D | Email bombing: repeated registrations for one address flood a victim's inbox | 2 | 1 | Per-email limit 3/h (keyed by the address hash) in addition to the per-IP limit; the worker's global hourly cap | IAM-016 | `RegistrationRateLimitTest` |
| T-48 | T | Cross-site POST to the registration or verification API from another origin | 2 | 2 | State-changing `/api` requests must carry an `Origin` equal to `APP_URL`'s origin and a JSON content type (which forces a CORS preflight that we never approve); sessions add the T-21 synchroniser token when they land | API-003 | `OriginGuardTest` |
| T-49 | I/D | "Resend verification email" used to learn whether an address is registered, or to flood an inbox | 2 | 2 | `POST /api/v1/auth/resend-verification` always answers the same 202 for a well-formed address. Pending: a new single-use token replaces every older one (T-46) and the link is queued. Active or other status: the owner gets the account-exists email, as at registration. Unknown address: nothing is queued. Limits run before any lookup: 10/h per IP on this endpoint, and the per-address bucket is **shared with registration** (3/h in total), so the two endpoints together cannot send more than 3 verification-type emails an hour to one address. The UI enforces a 60-second cooldown | IAM-002, IAM-016 | `ResendVerificationTest` |

### 6.2 Sign-in and passwords (IAM-003…006)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-06 | S | Credential stuffing with breached passwords | 3 | 3 | Breached-password check at registration and password change (k-anonymity range API: only a 5-character SHA-1 prefix leaves our network; on API outage the check fails open, is logged and is retried at next sign-in); per-account and per-IP throttling; Turnstile after 3 failures; MFA | IAM-003, IAM-006, IAM-016 | `BreachedPasswordTest`, `SignInThrottleTest` |
| T-07 | S | Online brute force against one account, including from many IPs | 2 | 3 | Two counters in Redis: (a) per (account, client-IP bucket): 5 failures → exponential delay up to 15 min; (b) per account across all IPs: above 10 failures/hour every further attempt requires Turnstile and the owner is notified. 30/min per IP overall. MFA makes a guessed password insufficient. Events audited | IAM-006 | `SignInThrottleTest`, `DistributedBruteForceTest` |
| T-08 | D | Attacker locks a victim out by failing on purpose | 2 | 2 | No permanent lockout. Delays from counter (a) apply only to the attacker's IP bucket, so the owner's network is unaffected; counter (b) adds a Turnstile challenge, which the owner can pass, instead of blocking. Successful sign-in resets (a) for that bucket | IAM-006 | `TargetedLockoutTest` |
| T-09 | I | Timing difference reveals whether an email exists | 2 | 2 | D-7 dummy hash; identical code path and response | IAM-005 | `SignInTimingParityTest` (statistical, tolerance-based) |
| T-10 | I | Database leak exposes passwords | 1 | 3 | D-3 Argon2id with explicit parameters; rehash on sign-in | IAM-004 | `PasswordHashingTest` |
| T-11 | D | CPU/memory exhaustion: many concurrent Argon2id verifications | 2 | 2 | Rate limit before hashing; FPM worker cap sized for 64 MiB per hash; sign-in endpoint has its own worker pool limit | IAM-004, API-004 | load test in Sprint 11 |
| T-12 | T | Open redirect via `?next=` after sign-in | 2 | 2 | Only relative paths matching the portal route allowlist; anything else redirects to the dashboard | API-002 | `PostLoginRedirectTest` |

### 6.3 MFA and recovery (IAM-007, IAM-013)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-13 | S | TOTP code replayed within its window | 2 | 2 | D-4 last-accepted step stored; reuse rejected | IAM-007 | `TotpReplayTest` |
| T-14 | S | TOTP brute force (10⁶ space) | 2 | 3 | 5 wrong codes → MFA step locked for 15 min and owner notified; the half-authenticated state lives max 5 min | IAM-007 | `TotpThrottleTest` |
| T-15 | I | TOTP secrets readable from a DB backup | 1 | 3 | D-4 encryption with a key outside the database | IAM-007 | `TotpSecretEncryptionTest` |
| T-16 | S | Social-engineering support to remove MFA | 2 | 3 | No recovery code → support-verified identity check **and** 24 h delay with email notice to the account, cancellable by the owner; staff action audited | IAM-013 | `MfaRecoveryDelayTest` |
| T-17 | E | Enrolling MFA without proving the current password | 2 | 2 | MFA enrol/disable/regenerate are step-up actions (T-22) | IAM-014 | `StepUpRequiredTest` |

### 6.4 Sessions and CSRF (IAM-009…011, API-003)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-18 | S | Session fixation | 2 | 3 | D-6 rotation on every privilege change; a pre-auth session ID is never promoted | IAM-009 | `SessionFixationTest` |
| T-19 | I | Session theft via XSS | 2 | 3 | HttpOnly cookie; strict CSP without `unsafe-inline`; React output encoding, `dangerouslySetInnerHTML` forbidden by lint | API-005, SEC-011 | `SecurityHeadersTest`, frontend lint rule |
| T-20 | I | Session theft from a Redis snapshot | 1 | 3 | D-2/D-6 keys are hashes of the session ID; Redis on private network with ACL user and password | IAM-009, SEC-013 | `SessionStorageHashTest` |
| T-21 | T | Cross-site request forgery | 2 | 3 | Synchroniser token bound to session for every state-changing request **and** `Origin` must match an allowed origin; SameSite=Lax as defence in depth | API-003 | `CsrfTest` |
| T-22 | E | Stolen-but-idle session used for high-impact actions | 2 | 3 | Step-up within 5 min for: change email/password/MFA, domain transfer-out/EPP, VPS rebuild/delete, remove payment method | IAM-014 | `StepUpRequiredTest` |
| T-23 | R | Customer cannot tell a session was hijacked | 2 | 2 | Session list with device/IP/last seen; revoke one or all; new-device sign-in email | IAM-011 | `SessionRevocationTest` |
| T-24 | S | Sessions live forever | 2 | 2 | Idle 30 min / absolute 12 h (customer); 15 min / 8 h (staff); enforced server-side | IAM-010 | `SessionTimeoutTest` |

### 6.5 Password reset and email change (IAM-012, IAM-015)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-25 | S | Reset token theft (referer leak, logs, email scanners) | 2 | 3 | D-2; 30 min, single-use; reset page sets `Referrer-Policy: no-referrer`; the token is consumed by POST, not by GET (link scanners only GET) | IAM-012 | `PasswordResetTokenTest` |
| T-26 | S | Host-header poisoning makes the reset link point to an attacker domain | 2 | 3 | Links are built from a configured base URL, never from the request `Host` | IAM-012 | `ResetLinkBaseUrlTest` |
| T-27 | E | Reset used to bypass MFA | 2 | 3 | D-8 | IAM-012 | `ResetDoesNotBypassMfaTest` |
| T-28 | S | Account takeover by changing email from a hijacked session | 2 | 3 | Step-up; confirm new address; notify old address with a 48 h revert link that also revokes sessions | IAM-015 | `EmailChangeTest` |

### 6.6 Staff authentication and RBAC (IAM-008, ACC-005)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-29 | S | Phishing of staff credentials | 2 | 3 | WebAuthn only (origin-bound, phishing-resistant); admin origin also behind Cloudflare Access | IAM-008 | `StaffWebAuthnOnlyTest` |
| T-30 | S | Cloned authenticator | 1 | 3 | Sign-count regression rejects and alerts; attestation policy recorded per credential | IAM-008 | `WebAuthnSignCountTest` |
| T-31 | E | Customer session reaches admin API | 2 | 3 | D-6 separate cookie, origin and Redis prefix; admin routes require a staff session | ACC-005 | `CustomerSessionRejectedOnAdminTest` |
| T-32 | E | Staff exceeds role (e.g. support issues refunds) | 2 | 3 | Deny-by-default permission map; every admin route declares its permission; CI fails if a route has none | ACC-005 | `AdminRoutePermissionCoverageTest` |
| T-33 | E | Break-glass Super Admin abused | 1 | 3 | Super Admin credentials kept offline; every use alerts the CEO and is audited; no routine work under it | ACC-005 | runbook check (Sprint 11) |
| T-34 | R | Staff denies an action | 2 | 2 | Every staff action audited with staff ID, permission used and correlation ID (D-14) | AUD-001 | `StaffActionAuditedTest` |

### 6.7 Tenant isolation (ACC-001…003)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-35 | E | IDOR: customer changes an ID in the URL/body to reach another account's invoice, domain or server | 3 | 3 | D-13: account from session only; `AuthoritativeTenantAuthorizer`; repository queries always include `account_id`; 404 on mismatch | ACC-002 | **Generated per route** (`TenantIsolationRouteTest`) |
| T-36 | E | A new route ships without an isolation test | 2 | 3 | CI enumerates the route table and fails if any tenant-scoped route lacks a negative test | ACC-003 | `TenantIsolationCoverageTest` |
| T-37 | I | Response reveals that another account's resource exists (403 vs 404) | 2 | 1 | Uniform 404 | ACC-002 | `TenantIsolationRouteTest` |
| T-38 | E | Background job runs in the wrong tenant | 2 | 3 | Jobs carry account ID and re-authorize on execution; no ambient tenant state (PCF: no hidden global state) | ACC-002 | `JobTenantContextTest` (Sprint 4) |

### 6.8 Audit and logging (AUD-001…004, API-008)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-39 | T | Attacker or insider edits or deletes audit rows | 1 | 3 | D-14 grants + hash chain + daily external anchor; chain verification job alerts on break | AUD-002 | `AuditChainVerificationTest`, `AuditGrantsTest` |
| T-40 | I | Secrets in logs (passwords, tokens, session IDs, TOTP secrets) | 2 | 3 | Central redaction by field name and value pattern before any log/audit write; request bodies of auth endpoints are never logged | AUD-004 | `LogRedactionTest` |
| T-41 | T | Log injection via crafted user-agent or name | 2 | 1 | Structured JSON logging only; no string concatenation | AUD-004 | `LogRedactionTest` |
| T-42 | R | Client forges request IDs to confuse investigations | 2 | 1 | Server-generated ID; client value accepted only if it matches `^[A-Za-z0-9-]{8,64}$` and is stored separately | API-008 | `RequestIdTest` |
| T-50 | S | Client forges location or device details (fake `cf-ipcity`, `User-Agent`) to mislead an investigation | 2 | 1 | The IP comes only from `CF-Connecting-IP` via nginx `real_ip` from the tunnel hop; location and device are recorded as *reported* (approximate), validated (length, characters, country `^[A-Z0-9]{2}$`) and never used for any security decision | AUD-001 | `ClientContextTest` |
| T-51 | I | The audit trail becomes a store of personal data kept forever (NDPA 2023 minimisation) | 2 | 2 | Personal request context (full IP, location, user agent) lives in a separate, purgeable table, committed to the chain only by a salted hash; deleted after 180 days by `audit:purge-context`; events themselves hold IDs and fixed reason codes only, never emails, names, passwords, codes or tokens | AUD-004 | `AuditContextPurgeTest`, `AuditEventDetailsTest` |
| T-52 | I | A customer sees another customer's activity | 1 | 3 | `GET /api/v1/account/activity` reads only events whose subject is the session's identity; no ID parameter exists | ACC-002 | `AccountActivityTest` |
| T-53 | S | A sign-in alert email is used for phishing, or carries attacker-controlled text (user agent, city) | 2 | 2 | Alerts contain no action links except the fixed `APP_URL/security` page (the customer signs in normally); device, location and IP are rendered only from validated parameters (label, location and IP patterns) and HTML-escaped; no reply-to | IAM-017 | `EmailTemplatesTest` |
| T-54 | D | Repeated sign-ins flood the owner's inbox with alerts | 2 | 1 | At most 10 sign-in alerts per identity per hour (counted in Redis); further sign-ins are still audited, the email is skipped and logged | IAM-017 | `SignInAlertsTest` |
| T-55 | S | A copied or forged device cookie makes a new device look known | 2 | 1 | The device cookie only chooses between the "new device" and the normal sign-in email: it never skips the password, MFA or any check; it is a 256-bit random value stored as SHA-256 per identity, `__Host-`, HttpOnly, Secure, SameSite=Lax | IAM-017 | `SignInAlertsTest` |

### 6.9 Edge and transport (API-004…006, SEC-007)

| ID | STRIDE | Threat | L | I | Mitigation | Req | Test |
|---|---|---|---|---|---|---|---|
| T-43 | I | Downgrade/strip TLS | 1 | 3 | HSTS preload, TLS 1.2+ only, Full (strict) | API-005, SEC-007 | `SecurityHeadersTest` + staging ZAP baseline |
| T-44 | T | Clickjacking of sensitive pages | 2 | 2 | `frame-ancestors 'none'` | API-005 | `SecurityHeadersTest` |
| T-45 | I | Credentialed CORS from attacker origin | 2 | 3 | Exact-origin allowlist; never reflect `Origin`; no wildcard | API-006 | `CorsTest` |
| T-46 | S | Origin bypass: attacker hits the origin IP directly, spoofing `CF-Connecting-IP` | 2 | 2 | Origin firewall allows Cloudflare ranges only; authenticated origin pulls | SEC-007, SEC-013 | staging check (Sprint 0) |
| T-47 | D | Request floods on auth endpoints | 3 | 2 | Cloudflare rate rules + application limits with 429 and `Retry-After` | API-004 | `RateLimitTest` |
| T-48 | I | Authenticated responses cached by a proxy | 1 | 2 | `Cache-Control: no-store` on authenticated responses | API-005 | `SecurityHeadersTest` |

## 7. Sprint 1 security test plan

Every test below must exist and pass before the Identity epic can close. They
are negative tests unless noted. Each Sprint 1 PR lists the tests it adds.

| Area | Tests |
|---|---|
| Registration | `RegistrationEnumerationTest`, `RegistrationRateLimitTest`, `RegistrationSchemaTest`, `EmailVerificationTokenTest`, `UnverifiedCannotOrderTest` |
| Sign-in | `BreachedPasswordTest`, `SignInThrottleTest`, `DistributedBruteForceTest`, `TargetedLockoutTest`, `SignInTimingParityTest`, `PasswordHashingTest`, `PostLoginRedirectTest` |
| MFA | `TotpRfc6238VectorsTest` (positive), `TotpReplayTest`, `TotpThrottleTest`, `TotpSecretEncryptionTest`, `MfaRecoveryDelayTest` |
| Sessions | `SessionFixationTest`, `SessionStorageHashTest`, `CsrfTest`, `StepUpRequiredTest`, `SessionRevocationTest`, `SessionTimeoutTest` |
| Reset / email | `PasswordResetTokenTest`, `ResetLinkBaseUrlTest`, `ResetDoesNotBypassMfaTest`, `EmailChangeTest` |
| Staff | `StaffWebAuthnOnlyTest`, `WebAuthnSignCountTest`, `CustomerSessionRejectedOnAdminTest`, `AdminRoutePermissionCoverageTest`, `StaffActionAuditedTest` |
| Tenant isolation | `TenantIsolationRouteTest` (generated), `TenantIsolationCoverageTest` |
| Audit / logs | `AuditChainVerificationTest`, `AuditGrantsTest`, `LogRedactionTest`, `RequestIdTest` |
| Edge | `SecurityHeadersTest`, `CorsTest`, `RateLimitTest` |

### 6.10 Registration implementation notes (Sprint 1, PR "registration + email verification")

- PCF's `NativePasswordHasher` uses `PASSWORD_DEFAULT` (bcrypt), which breaks D-3 and silently truncates passwords longer than 72 bytes. PaxofiCloud therefore binds its own `Argon2idPasswordHasher` with the D-3 parameters; reported to the PCF maintainers as a framework finding.
- The client IP used for rate limits is nginx's `$remote_addr`, which `real_ip_header CF-Connecting-IP` sets only for requests arriving through the tunnel (trusted loopback and Docker ranges).
- Turnstile (T-02) and the breached-password check (T-06, IAM-003) are delivered in the follow-up PR, because both add an outbound integration; staging stays behind Cloudflare Access until then.
- **T-06 at registration (implemented 2026-10-17).** Before the password is hashed, the web tier asks the Pwned Passwords range API (`api.pwnedpasswords.com`, allow-listed in `ProviderHttpClient`; same TLS, DNS-pinning and size caps as other providers) for the 5-character prefix of the password's SHA-1, with `Add-Padding: true` so response size does not leak the bucket. Only the prefix leaves our network; the suffix comparison happens locally. SHA-1 is required by that protocol and is used for nothing else (one reviewed `nosemgrep` line, covering our rule and the community weak-crypto rule). A hit returns the field error `password: breached`. Timeouts are short (2 s connect, 4 s total); on outage or a non-200 the check **fails open** and logs `password_breach_check.unavailable` (no password data). The retry at next sign-in lands with sign-in.

### 6.11 Sign-in and session implementation notes (Sprint 1, PR "sign-in API + sessions")

Written before the code; it fixes how T-06…T-12 and T-18…T-24 are met in this PR and what is deferred.

- **Endpoints.** `POST /api/v1/auth/sign-in` `{email, password}` (strict schema), `GET /api/v1/auth/session`, `POST /api/v1/auth/sign-out`. All writes keep the T-48 Origin + JSON guard.
- **Uniform failure (T-09, D-7).** Unknown email, wrong password → the same `401 invalid_credentials`. An unknown email still runs `password_verify` against a fixed dummy Argon2id hash with the D-3 parameters, so timing is comparable. Only after the correct password: `403 email_not_verified` (pending) or `403 account_unavailable` (suspended); revealing state to someone who knows the password is acceptable.
- **Throttling (T-07, T-08, T-11).** Before any hash: 30 attempts/min per client IP. Per (email, IP bucket = IPv4 /24 or IPv6 /64): from the 5th failure within 15 min the pair is locked for 30 s × 2^(n−5), capped at 15 min; a success clears the pair. The owner's own network is never locked by someone else's failures (T-08). Counter (b), failures per account across all IPs per hour, is counted and logged above 10 now; the Turnstile challenge and owner notification it triggers land with the Turnstile and audit PRs (**residual until then:** a distributed attack is limited by the per-IP and per-pair limits only).
- **Rehash (D-3).** `password_needs_rehash` after every successful sign-in; the new hash is stored.
- **Sessions (D-6, T-18, T-20, T-24).** 256-bit random session ID; Redis key `pc:sess:{SHA-256(sid)}` (raw ID never stored). Cookie `__Host-pc_sid` (Secure, HttpOnly, SameSite=Lax, Path=/, no Max-Age). Idle timeout 30 min (sliding Redis TTL and a stored `last_seen_at`), absolute 12 h (`created_at`). Every sign-in issues a new ID and destroys the session named by any cookie sent with it, so a planted ID is never promoted.
- **CSRF (T-21).** Each session has its own 256-bit synchroniser token, kept in the server-side session record (whose key is the hash of the session ID, so a Redis read yields no usable cookie) and returned in the JSON body of sign-in and `GET /session` (CORS is never granted, so other origins cannot read it). State-changing requests made with a session (sign-out now; every later authenticated write) must send it as `X-CSRF-Token`, compared with `hash_equals`, in addition to the Origin check.
- **Deferred to named follow-ups:** session list and revoke (T-23) and new-device email; breached-password retry at sign-in (T-06); audit events for sign-in success and failure (§6.8, audit PR); MFA step (T-13…T-17); staff sessions (§6.6).

### 6.12 Turnstile challenge implementation notes (Sprint 1, PR "Turnstile")

Written before the code; it fixes how T-02, T-06 and T-07 use Cloudflare Turnstile and what happens when it is unavailable.

- **Where.** Registration and "resend verification email" always require a token (T-02, T-47, T-49). Sign-in requires one only once the attempt looks risky: 3 or more failures for the (email, network) pair in 15 min (T-06), or more than 10 failures for the email across all networks in the hour (T-07 counter b). Without a valid token such a sign-in gets `403 challenge_required` **before** any password hash, and the client shows the widget.
- **No enumeration through the challenge.** Counter (b) is now counted for every email submitted, known or not, so whether a challenge is required never depends on the address having an account (T-09).
- **Server-side verification only.** The widget's token is posted with the form (`turnstile_token`) and checked with Cloudflare `siteverify` from the web tier through `ProviderHttpClient` (allow-listed `challenges.cloudflare.com`, TLS 1.2+, pinned vetted addresses, no redirects, 2 s connect / 4 s total). We accept the token only if `success` is true, `hostname` equals `APP_URL`'s host and `action` equals the form's action (`register`, `resend`, `sign_in`). The client IP and an idempotency key are sent. Tokens are single-use and expire after 300 s at Cloudflare. Tokens and the secret are never logged; only fixed reasons (`missing`, `rejected`, `transport`, `http_NNN`) are logged.
- **Fail closed (T-02).** If Cloudflare cannot be reached, a challenged request is refused with `503 challenge_unavailable` and the customer is asked to retry. Sign-ins that are not challenged are unaffected.
- **Keys.** `TURNSTILE_SITE_KEY` (public) is served by `GET /api/v1/auth/challenge`; `TURNSTILE_SECRET_KEY` goes only to the PHP web container, through the deploy agent's `web-secrets` verb into `/etc/paxoficloud/web.env` (0600), never into an image, compose file or log. Without keys, Turnstile is off; that is refused when `APP_ENV=production`.
- **Browser.** The Turnstile script is loaded only on the three forms, from `https://challenges.cloudflare.com` (CSP `script-src` and `frame-src` allow exactly that origin). Cloudflare does not support SRI for this script; accepted because Cloudflare already terminates our TLS and is in the trust boundary.
- **Residual.** An attacker who solves challenges (human farms) is still limited by the per-IP, per-pair and per-email limits; owner notification for counter (b) arrives with the audit/notification PR.

### 6.13 TOTP two-step verification implementation notes (Sprint 1, PR "MFA API")

Written before the code; it fixes how D-4, D-5 and T-13…T-17 are met in this PR and what is deferred.

- **Endpoints (all need a session, the session's CSRF token and the Origin + JSON guard).** `GET /api/v1/account/mfa` (status: TOTP on/off, recovery codes left); `POST /api/v1/account/mfa/totp/setup {password}` → a new 160-bit secret, returned once as base32 and as an `otpauth://totp/PaxofiCloud:<email>?secret=…&issuer=PaxofiCloud&algorithm=SHA1&digits=6&period=30` URI; `POST …/totp/confirm {code}` → turns TOTP on and returns 10 recovery codes once; `POST …/recovery-codes {password}` → a new set (the old set stops working); `POST …/disable {password, code | recovery_code}`.
- **Step-up (T-17).** Setup, new recovery codes and disable each require the current password in the same request (verified with Argon2id; 5 wrong passwords per identity per 15 min → 429). The general 5-minute step-up of T-22 comes with the first high-impact action that needs it.
- **Secrets at rest (D-4, T-15).** `identity_totp.secret` holds a `SecretCipher` envelope (XChaCha20-Poly1305, key ID for rotation) with associated data `totp:<identity id>`, so a ciphertext copied to another row does not decrypt. Keys: `MFA_SECRET_KEYS` / `MFA_SECRET_ACTIVE_KEY`, separate from the provider-credential KEK (which the web tier never holds, P-6). A setup that is never confirmed is replaced by the next setup; a confirmed TOTP must be disabled before a new setup.
- **Verification (D-4, T-13).** RFC 6238 over `hash_hmac('sha1')` (no own primitive), 6 digits, 30 s step, ±1 step, compared with `hash_equals`. The accepted step is stored; a code for that step or an earlier one is refused, so a code works once. RFC 6238 test vectors are unit tests.
- **Recovery codes (D-1, D-5).** 10 codes of 16 base32 characters (80 bits), shown as `XXXX-XXXX-XXXX-XXXX`; only `BLAKE2b(code, MFA_RECOVERY_KEY)` is stored; single use; input is case- and dash-insensitive.
- **Sign-in second step (T-14, T-18).** When TOTP is on, a correct password no longer creates a session: it creates an MFA ticket (256-bit, Redis `pc:mfa:{SHA-256}`, 5 min, bound to identity and account) in a `__Host-pc_mfa` HttpOnly cookie, and the API answers `200 {"status":"mfa_required"}`. `POST /api/v1/auth/sign-in/mfa {code | recovery_code}` then issues the session (new ID, as in §6.11) and deletes the ticket. 5 wrong codes delete the ticket and lock the second step for that identity for 15 min (logged; the owner email arrives with notifications). The ticket can only finish sign-in; it grants nothing else.
- **Keys on the host.** The deploy agent generates `MFA_SECRET_KEYS`, `MFA_SECRET_ACTIVE_KEY` and `MFA_RECOVERY_KEY` on first release into `identity-keys.env` (0600), mounted into the php container only; values never leave the host or reach logs. Without keys TOTP setup answers `503 mfa_unavailable` (sign-in unaffected); production refuses to start without them.
- **Deferred:** support-assisted MFA removal with 24 h delay (T-16, needs the admin console); owner email on MFA changes and lockouts (notifications); revoking other sessions on MFA changes (needs the session list, T-23); WebAuthn for customers (Phase 2).

### 6.14 Security audit log implementation notes (Sprint 1, PR "audit log")

- **What is recorded (AUD-001).** Registration (new, repeated while pending, attempt on an existing address), email verified, verification resent, sign-in succeeded / failed (with a fixed reason) / needs second step / many failures on one account, second step succeeded (TOTP or recovery code) / failed / locked, two-step verification turned on / off, recovery codes regenerated, sign-out. Each event has a type, an outcome (`success` or `failure`), the subject identity and account when known, the request ID and a small details object of fixed reason codes. Sign-in failures for unknown addresses are recorded without an identity, so credential stuffing is visible without storing the attempted address.
- **Request context (CEO request, 18 Oct 2026).** Every event also captures who and where: full client IP and its network (/24 or /64), country, region and city from Cloudflare's visitor-location headers, and browser, OS and device type parsed from the user agent (plus the raw user agent, truncated to 512 bytes). Location is approximate (VPNs, mobile carriers) and marked as reported by Cloudflare; nothing is decided from it (T-50). City and region need the Cloudflare managed transform "Add visitor location headers" on the zone; without it only the country is present.
- **Hash chain (D-14, T-39).** `audit_events` is written in one transaction that locks the single-row `audit_chain_head`, so sequence numbers are gap-free and each row stores `hash = SHA-256(prev_hash ‖ canonical JSON of the row)`. Details are stored as the exact canonical JSON string, not a MySQL JSON column, so verification recomputes byte-identical input. `bin/paxoficloud audit:verify` walks the chain, checks sequence, links, hashes, context commitments and the head, and exits 2 with the first broken sequence number. It prints the head hash, which serves as a manual anchor until O-2 is decided.
- **Context outside the chain (T-51).** The context row holds a random 128-bit salt; the event stores `SHA-256(salt ‖ canonical context)`. While the row exists it is verified; after purging (180 days, `audit:purge-context`) the chain still verifies and the commitment reveals nothing.
- **Failure policy.** Recording is best effort after the action has happened: a failed audit write never blocks sign-in or a settings change; it is logged as `audit.write_failed` at error level for alerting. Writes happen outside the action's transaction so a rolled-back action is never recorded as done.
- **Customer view (T-52).** `GET /api/v1/account/activity` (session required) returns the 20 most recent events about the signed-in identity with time, event, outcome, location, browser, OS, device and IP, so the owner can spot sign-in attempts from elsewhere.
- **Deferred / residual.** Database-level append-only enforcement needs a separate runtime MySQL user without `UPDATE`/`DELETE` on `audit_events` (D-14 grants): MySQL with binary logging refuses triggers for a non-SUPER user, so this lands with the deploy-agent change that creates that user (until then tampering is *detected* by `audit:verify`, not prevented). Daily external anchor (O-2). Scheduled purge and verification (needs the job runner, Sprint 4). Owner emails (notifications PR, CEO decision 18 Oct 2026): an email on every successful sign-in, and a distinct "new device" email instead of it when the device is not yet known (known devices are recognised by a long-lived random device cookie whose hash is stored per identity); both carry browser, OS, device type, approximate location, IP and time. Also many failed sign-ins with the same details, and MFA changes. Staff events (T-34) arrive with the admin console.

### 6.15 Sign-in notification implementation notes (Sprint 1, PR "sign-in notifications")

- **Every sign-in emails the owner (CEO decision 18 Oct 2026).** When a session is issued (password only, or after the second step) the owner gets "You signed in to PaxofiCloud" with the device (browser, OS, type), approximate location (city, region, country), IP address and time in WAT. If the device is not yet known for this identity, the same details arrive instead as "New device signed in to your PaxofiCloud account", worded as a warning, and an `auth.sign_in.new_device` audit event is recorded. One sign-in never sends both.
- **Known devices (T-55).** Every sign-in response sets `__Host-pc_dev` (256-bit random, HttpOnly, Secure, SameSite=Lax, 400 days) unless the browser already has one; `identity_devices` stores SHA-256(device token) per identity with the device label and first/last seen. Clearing cookies or a new browser profile counts as a new device, which errs on the side of warning. The cookie grants nothing.
- **Other security emails.** More than 10 failed sign-ins in an hour on one account (T-07 counter (b)) sends "Unsuccessful sign-in attempts" with the latest attempt's device, location, IP and time, once per window. Turning two-step verification on or off and regenerating recovery codes each send "Security settings changed" with the same details.
- **Limits and failure (T-54).** At most 10 sign-in alerts per identity per hour. Alerts are queued in the sealed outbox after the action, best effort: a failure to queue is logged (`notify.enqueue_failed`) and never blocks sign-in. The alert is not a substitute for the audit log, which records every event regardless.
- **Content (T-53).** Only the first name, the device label, the location, the IP and the time are inserted, each validated against a strict pattern and HTML-escaped; the only link is `APP_URL/security`. Staging only delivers to the allow-list (E-10).

### 6.16 Staff RBAC implementation notes (Sprint 1, PRs "staff RBAC core" and "staff sign-in")

Scope (CEO decision 18 Oct 2026, following the SRS): staff roles and permissions for the admin API (ACC-005) and WebAuthn-only staff sign-in (IAM-008). Customer team members (ACC-008) remain Phase 2. The admin console screens arrive in Sprint 9; this sprint builds the API foundation they will use.

- **Permission catalogue (T-32).** Every capability in SRS §2.2 is one named permission (for example `billing.refund.issue`, `billing.refund.approve`, `service.suspend.abuse`). Roles are fixed sets of permissions written in code, reviewed like code, and checked by a test that is a literal transcription of the SRS §2.2 table, so code and specification cannot drift silently. There is no wildcard and no "admin" permission; Super Admin is simply the role that holds every permission.
- **Deny by default.** `StaffAuthorizer` answers only "does this staff member hold this permission now"; anything not granted is refused, the refusal is audited (`staff.permission_denied`, with the permission) and the API returns 403. Roles are loaded from the database on every request, never cached in the session, so revoking a role takes effect at the next request.
- **Role grants and two-person rule (SEC-017, T-33).** Staff and their role assignments live in `staff_members` and `staff_role_assignments`. Granting a role needs `staff.manage`. A Super Admin grant counts only after a *different* Security Admin or Super Admin approves it (enforced by a database CHECK and by the application). Every grant, approval and revocation is audited.
- **Suspensions carry their reason.** The SRS gives Billing, Provisioning and Abuse each their own right to suspend; they are three permissions (`service.suspend.billing`, `.operations`, `.abuse`), so a Billing Officer cannot suspend for abuse and the audit trail shows on whose authority a service was suspended.
- **Four-eyes refunds (BR-17).** `billing.refund.approve` is separate from `billing.refund.issue`; the billing module (Sprint 3) will additionally refuse an approval by the person who issued the refund.
- **Separation from customers (T-31, D-6).** Staff identities are a separate table from customer identities; a staff session (next PR) uses `__Host-pc_staff`, its own Redis prefix and the admin origin, and is never accepted on customer routes or vice versa.
- **Staff sign-in (next PR, T-29, T-30, D-10, D-11).** Invitation-only enrolment (single-use 24 h link straight to WebAuthn registration, at least two authenticators), WebAuthn assertions verified by the ADR-002 library with user verification required, ES256/EdDSA only, sign-count regression rejected and alerted, no password and no fallback. The first Security Admin is created on the host with an operator CLI command; every later staff member is invited from the admin API.

### 6.17 Staff passkey implementation notes (Sprint 1, PR "staff passkeys")

WebAuthn (passkeys and security keys) is the only way staff sign in (IAM-008, D-10, D-11). Verification is done by `web-auth/webauthn-lib` (ADR-002), wrapped in `src/Integration/WebAuthn`; the application only sees our own `PasskeyCeremonies` contract.

- **Invitation (D-11).** A staff member exists first as `invited`. The invitation token is 256 bits from the CSPRNG, stored only as SHA-256, valid 24 hours, delivered by email as a link to the admin origin with the token in the URL fragment (never sent to servers in logs or `Referer`). It is never printed: not by the API, not by the operator CLI (public Actions logs). Inviting needs `staff.manage`; the very first Security Admin is invited by the operator bootstrap, which refuses once any active staff manager exists.
- **Enrolment.** The invitation token authorises registering passkeys for that one staff member only. The member becomes `active` when the **second** passkey is registered (D-11: at least two authenticators, so losing one key is not a lock-out); the invitation is consumed in the same transaction. At most 5 passkeys per member.
- **Registration policy.** `userVerification = required`, `residentKey = required` (discoverable, so sign-in needs no email field and cannot enumerate staff), attestation conveyance `none`, algorithms **ES256 (-7) and EdDSA (-8) only** (the options offer only these and the verifier's algorithm manager holds only these). The relying-party ID is the admin host and the only allowed origin is the admin origin (`ADMIN_URL`), so a credential phished on another origin cannot be used (T-29). Each member has a random 32-byte WebAuthn user handle, never the staff ID or email.
- **Ceremony challenges.** 32 random bytes, stored in Redis keyed by SHA-256 of a random ceremony ID, single use (read and deleted atomically), 5-minute expiry, bound to the ceremony type (registration or sign-in) and, for registration, to the staff member.
- **Sign-in.** Usernameless: empty `allowCredentials`. The credential ID from the response is looked up (unique index); unknown, disabled, or belonging to a non-active member ⇒ one uniform failure. The library checks challenge, origin, RP ID hash, user presence, user verification, signature and counter; the user handle must match the credential's owner.
- **Cloned authenticators (T-30).** A sign counter that does not increase (when either value is non-zero) rejects the sign-in, **disables that credential** and is audited as `staff.passkey.clone_suspected` (alerting); passkeys that always report 0 skip the check, as the spec allows.
- **Throttling.** Ceremony starts and finishes count against per-IP limits (Redis) before any verification work.
- **Audit (T-34).** Invitations, passkey registrations, activation, sign-in success and failure (fixed reason codes) and clone suspicion are audited with the staff ID in details and `identity_id` NULL.

## 8. Residual risks

| ID | Risk | Why accepted | Owner | Revisit |
|---|---|---|---|---|
| R-1 | Compromise of a customer's email account enables password reset | MFA still required (D-8); customers without MFA remain exposed. Encourage MFA enrolment in the portal; consider requiring it for accounts holding VPS or high-value domains | CEO / product | Sprint 8 |
| R-2 | Breached-password check fails open during third-party outages | Blocking registration on a third-party outage harms availability; the check re-runs at the next sign-in | Engineering | Sprint 11 |
| R-3 | A malicious insider with database and backup access could rewrite the audit chain between anchors | Daily anchoring limits the window to 24 h; tighter anchoring when an external log store is chosen | Engineering | Sprint 9 |
| R-4 | XSS in the SPA can act as the user within the session even without stealing the cookie | Strict CSP and output encoding; independent penetration test before launch | Engineering | Sprint 11 |

## 9. Open items

| ID | Item | Decision needed by |
|---|---|---|
| O-1 | **Resolved 2026-10-18 (CEO):** `web-auth/webauthn-lib` 5.x behind our own contract; see `docs/architecture/ADR-002-WEBAUTHN-LIBRARY.md` | Sprint 0 end (23 Oct 2026) |
| O-2 | ADR: where the daily audit-chain anchor is stored (object storage with object lock, or a separate append-only service) | Sprint 1 end |
| O-3 | Choice of breached-password source (hosted range API vs. downloaded offline corpus) — privacy and availability trade-off | Sprint 1 planning |
| O-4 | **Resolved 2026-10-14:** Postmark primary, Resend secondary; see THREAT-MODEL-TRANSACTIONAL-EMAIL.md | Sprint 1 planning |

## 10. Review log

| Date | Change | By |
|---|---|---|
| 2026-10-05 | Baseline created before Sprint 1 implementation | Claude (security engineering) |
| 2026-10-17 | T-46 pre-hijacking, T-47 email bombing, T-48 cross-origin API POSTs, §6.10 registration notes; written before the registration code | Claude (security engineering) |
| 2026-10-17 | T-49 resend verification email (enumeration and email bombing); written before the resend code | Claude (security engineering) |
| 2026-10-17 | T-06 breached-password check at registration (k-anonymity range API, padding, fail-open + log), written before the code | Claude (security engineering) |
| 2026-10-17 | §6.11 sign-in and session implementation notes (T-06…T-12, T-18…T-24), written before the sign-in code | Claude (security engineering) |
| 2026-10-18 | §6.12 Turnstile implementation notes (T-02, T-06, T-07, T-47, T-49; fail closed; challenge counter made address-independent), written before the Turnstile code | Claude (security engineering) |
| 2026-10-18 | §6.13 TOTP two-step verification notes (D-4, D-5, T-13…T-17), written before the MFA code | Claude (security engineering) |
| 2026-10-18 | §6.14 audit log notes, T-50 forged context, T-51 personal data in the audit trail, T-52 activity isolation; written before the audit code | Claude (security engineering) |
| 2026-10-18 | §6.17 staff passkey notes (invitation tokens, two-passkey activation, ES256/EdDSA only, discoverable usernameless sign-in, single-use challenges, clone detection disables the credential); written before the staff passkey code | Claude (security engineering) |
| 2026-10-18 | §6.16 staff RBAC notes (ACC-005 permission catalogue from SRS §2.2, deny by default, two-person Super Admin grants, reason-scoped suspensions); O-1 resolved by ADR-002; written before the staff RBAC code | Claude (security engineering) |
| 2026-10-18 | §6.15 sign-in notifications (every sign-in and new device, CEO decision), T-53 alert phishing/injection, T-54 alert flooding, T-55 forged device cookie; written before the notification code | Claude (security engineering) |
