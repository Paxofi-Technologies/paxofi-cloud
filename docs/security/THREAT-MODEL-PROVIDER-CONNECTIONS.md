# Threat Model — Provider connections (entering provider API credentials in the admin console)

| | |
|---|---|
| Epics | SEC-04 Provider credentials, BE-02 pricing (provider cost sync), INT-02 registrar |
| Method | STRIDE per element of the data-flow diagram |
| Requirements | SEC-004, SEC-005 (credentials encrypted at rest, least privilege), ACC-005 (`provider_credentials.manage`), PRC-011 (provider cost sync) |
| Standard | OWASP ASVS 5.0 — **Level 3** (provider credentials, CLAUDE.md §5) |
| Status | Written **before** its code (20 Oct 2026). Extends THREAT-MODEL-VPS-PROVIDERS.md (encrypted credential store, V-05/V-06) and THREAT-MODEL-DEPLOY-PIPELINE.md (P-6: no KEK in the web tier). |

## 1. Scope

Until now provider credentials could only be stored with the operator CLI
`credentials:set`, run inside a container that holds the key-encryption key
(KEK). The CEO needs to enter the DomainNameAPI reseller login, and later the
Hetzner and Vultr tokens, from the admin console without anyone handling a
shell. This model covers:

- **Provider connections page** in the admin console. It lists each supported provider with its status (not configured, waiting to be stored, configured on a date, review due) and has a write-only form to set or replace a credential.
- **Credential intake.** The web tier seals the submitted secret to an intake public key and stores only the sealed box.
- **Provider worker.** A new container, the only one with the intake secret key and the KEK. It opens the box and stores the secret in the existing encrypted credential store (`provider_credentials`, XChaCha20-Poly1305 with row-bound AAD).

Out of scope here: using the credentials (the provider sync, registration and
VPS adapters each have their own model) and rotation of the KEK (runbook
PROVIDER-CREDENTIALS.md, unchanged).

## 2. Assets

| Asset | Why it matters |
|---|---|
| Provider API secrets (DomainNameAPI username and password, Hetzner and Vultr tokens) | Full control of our registrar and server accounts, including spending money and deleting customer servers or domains |
| Provider-credential KEK | Decrypts every stored provider secret |
| Intake key pair | The secret half opens anything submitted but not yet stored |
| Audit trail of credential changes | Shows who changed a credential and when (ASVS V7) |

## 3. Data flow and trust boundaries

```
Staff browser ──(Cloudflare Access + staff session + CSRF + permission + fresh authenticator code)──▶ PHP web tier
   PHP web tier: validate fields → seal with INTAKE PUBLIC key → INSERT provider_credential_intake (sealed only)
Provider worker (intake SECRET key + KEK; no edge network):
   read intake row → open sealed box → validate again → MysqlProviderCredentialStore::save (KEK, AAD) → DELETE intake row
```

Boundaries:
1. The browser is untrusted.
2. The web tier is internet-facing and must not be able to read any stored or submitted secret.
3. The provider worker is reachable only from the data network.

## 4. Design decisions

| ID | Decision |
|---|---|
| PC-1 | **The web tier can write a secret but never read one.** It holds only the intake **public** key (`crypto_box_seal`, libsodium). It never receives the KEK or the intake secret key (P-6 unchanged). A compromised web tier can replace a credential (that needs a staff session, see PC-3) but cannot read any. |
| PC-2 | **Write-only API.** No endpoint, page, log, email or audit event ever returns or contains a secret, a fragment of one, its length or a hash. Status shows only: configured or not, when, by whom, and review-due date. Re-entering is a full replacement. |
| PC-3 | **Who can change a credential.** `provider_credentials.manage` (Security Admin) through the admin gate: staff session, CSRF token and admin Origin. Each submission also needs a **fresh authenticator code** (step-up, same check as adding a passkey, single use per time step). There are 5 submissions per staff member per 15 minutes, and wrong codes count. |
| PC-4 | **Fixed catalogue of providers and fields.** Only known providers (`domainnameapi`, `hetzner`, `vultr`) and their declared fields are accepted. The credential ID, environment and access level are derived on the server, never taken from the request. Values are length-capped and refused if they contain control characters. Unknown fields are rejected. |
| PC-5 | **Intake is short-lived.** One pending row per credential ID: a newer submission replaces the older one. The worker stores the secret and deletes the row in one pass. A row the worker cannot open or validate is deleted and audited as rejected. The runtime DB user has SELECT/INSERT/DELETE on the intake table, never UPDATE. |
| PC-6 | **Separate worker container.** `provider-worker` runs read-only, with no capabilities and no-new-privileges, on the data network plus the egress network (needed later for the provider sync). It is the only service with the intake secret key and the KEK, both generated on the host by the deploy agent and never shown in CI logs. |
| PC-7 | **Audit and alert.** `provider_credential.submitted` (staff, provider, credential ID) and `provider_credential.stored` / `.rejected` (worker) go to the hash-chained audit log. When the worker stores a credential it emails every active staff manager: provider and time only, never the value. Sending the alert from the worker means any stored credential is announced, however its intake row got there. |
| PC-8 | **Browser handling.** Secret fields are `type=password` with `autocomplete=off` and are cleared from page state right after submission. The page is under the admin CSP (no third-party scripts) and `Cache-Control: no-store`. |

## 5. Threats (STRIDE)

| ID | STRIDE | Threat | Mitigation | Test |
|---|---|---|---|---|
| PC-T1 | I | Attacker reads a provider secret through the admin API or page | PC-2 write-only; no read path exists | `ProviderConnectionsApiTest` (no secret in any response) |
| PC-T2 | I | A compromised web tier reads stored or submitted secrets | PC-1: the web tier holds the public key only; KEK and intake secret key only in the worker; smoke test asserts their absence from the php container | smoke test, `IntakeSealerTest` |
| PC-T3 | E/S | A stolen staff session (or a hijacked browser) replaces a credential with an attacker's account, redirecting registrations or billing | PC-3 fresh authenticator code per submission; Cloudflare Access; alert email to other managers (PC-7); audit | `ProviderConnectionsStepUpTest` |
| PC-T4 | E | Staff without the permission change a credential | Admin route table + `AdminRouteCoverageTest`; permission checked again in the service | coverage test |
| PC-T5 | T | Request smuggles a different credential ID, environment, access level or provider | PC-4 server-derived values; unknown fields rejected | `ProviderConnectionCatalogueTest` |
| PC-T6 | I | Secret leaks into logs, errors, audit details or the outbox | PC-2: fixed log codes; `#[\SensitiveParameter]`; audit details carry IDs only; tests scan logs and audit details for the submitted value | `ProviderConnectionsApiTest` |
| PC-T7 | D | Submission flooding fills the intake table | PC-3 rate limit; PC-5 one row per credential ID | `ProviderConnectionsStepUpTest` |
| PC-T8 | T | A forged intake row (written straight to the database, e.g. through SQL injection elsewhere) is stored as a credential | A sealed box gives confidentiality, not sender authenticity, so the worker revalidates provider, fields and credential ID; failures are deleted and audited as rejected; every stored credential triggers the managers' alert from the worker itself (PC-7), so a forged row cannot be stored silently | `CredentialIntakeWorkerTest` |
| PC-T9 | R | A staff member denies changing a credential | PC-7 audit with staff ID and request context | audit test |
| PC-T10 | I | Credential retained in the browser (autofill, back-forward cache) | PC-8 | review |

## 6. Residual risks

| ID | Risk | Why accepted |
|---|---|---|
| PC-R1 | An attacker who can write to the database can plant an intake row with their own provider account | The worker announces every stored credential to all staff managers (PC-7) and audits it, so the change is visible within minutes and can be reversed by re-entering the real credential |
| PC-R2 | A real-time phishing proxy could relay a staff member's password and codes | Same as identity threat model R-5; passkeys (optional) remove it |
| PC-R3 | Changing the deploy agent means an infrastructure apply, which replaces the staging server (fresh database) | Staging only; documented in the rollout |

## 7. Review log

| Date | Change | By |
|---|---|---|
| 2026-10-20 | Baseline, written before code | Claude (security engineering) |
