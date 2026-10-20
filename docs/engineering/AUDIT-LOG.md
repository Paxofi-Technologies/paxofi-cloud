# Security audit log — operator runbook

The audit log records security events (sign-up, verification, sign-in, two-step verification, sign-out) with who, where and on what device. Design and threats: `docs/security/THREAT-MODEL-IDENTITY.md` §6.14 (D-14, T-39, T-50…T-52).

## What is stored

| Table | Contents | Kept |
|---|---|---|
| `audit_events` | Event type, outcome, identity/account IDs, request ID, fixed reason codes, and the hash chain (`prev_hash`, `hash`) | Indefinitely (retention schedule to be set with the NDPA review) |
| `audit_event_context` | Full client IP and its /24 or /64 network, country/region/city from Cloudflare, user agent and the parsed browser, OS and device type, plus a random salt | 180 days, then purged |
| `audit_chain_head` | The last sequence number and hash | One row |

Events never contain email addresses, names, passwords, codes, tokens or session IDs. Each event commits to its context row by `SHA-256(salt ‖ context)`, so purging context does not break the chain.

## Checks

```sh
# Walk the whole chain. Exit 0: prints events=N and head=<hash>. Exit 2: prints the first broken seq.
bin/paxoficloud audit:verify

# Delete request context older than 180 days (events stay).
bin/paxoficloud audit:purge-context
```

Until the external anchor exists (threat model O-2), copy the `head=` hash from `audit:verify` somewhere outside the database (for example the ClickUp ops task) at least daily. A later `audit:verify` whose chain does not pass through that hash means history was rewritten.

If `audit:verify` reports a break, treat it as a security incident: do not "fix" rows; snapshot the database, record the reported `seq` and reason, and escalate to the CEO.

## Location data (Cloudflare)

Country comes from the `CF-IPCountry` header, which Cloudflare always sends. Region and city need **Rules → Settings → Managed Transforms → "Add visitor location headers"** turned on for the `paxofi.com` zone; without it only the country is recorded. Location is approximate (VPNs, mobile networks) and is never used to allow or block anything.

## Known gaps (tracked)

- The web tier and the mail worker connect as `paxoficloud_app`. On `audit_events` that user has `SELECT, INSERT` only, so it cannot change or delete an event (MySQL error 1142). On `audit_chain_head` it has `SELECT, UPDATE`; on `audit_event_context` it has `SELECT, INSERT, DELETE` (for the purge).
- The grants come from `RuntimeGrants::POLICY` and are re-applied by the schema owner after every migration with `bin/paxoficloud db:grants paxoficloud_app`. A new table without a policy entry stops the release. Design: identity threat model §6.22.
- The schema owner (used only by the deploy agent for `migrate` and `db:grants`) and root on the host can still rewrite rows; `audit:verify` detects that, and the daily external anchor (O-2) will bound it.
- Verification and purge are run by hand until the job runner (Sprint 4) schedules them.

## Owner emails (threat model §6.15)

| Event | Email | Limit |
|---|---|---|
| Successful sign-in from a known device | "You signed in to PaxofiCloud" | 10 sign-in emails per account per hour |
| Successful sign-in from a new device (no or unknown `__Host-pc_dev` cookie) | "New device signed in to your PaxofiCloud account", instead of the one above; audited as `auth.sign_in.new_device` | same |
| More than 10 failed sign-ins on one account in an hour | "Unsuccessful sign-in attempts" with the latest attempt | once per window |
| Two-step verification on/off, recovery codes regenerated | "Security settings changed" | none |

Each email shows the device (browser, OS, type), approximate location, IP address and time in WAT, and links only to `/security`. Emails are queued in the sealed outbox after the action; a queueing failure is logged as `notify.enqueue_failed` and never blocks the sign-in. On staging only addresses in `STAGING_ALLOWED_EMAILS` receive them.
