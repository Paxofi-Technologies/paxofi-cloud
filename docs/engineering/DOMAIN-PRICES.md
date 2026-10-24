# Domain cost prices — runbook

How PaxofiCloud gets the cost of every domain extension from the registrar
(DomainNameAPI today). Design: `docs/security/THREAT-MODEL-DOMAIN-REGISTRAR.md`
and CD-13 in `docs/security/THREAT-MODEL-COMMERCE.md`.

## What runs, and when

- The `provider-worker` container checks about once a minute whether the
  daily sync is due. It runs at **02:30 WAT** each day, once.
- It reads the full extension list with one-year register, renew, transfer
  and restore costs, using the credential entered on the admin console's
  Provider connections page.
- Every result is stored as a **snapshot**. Snapshots and run records are
  append-only: nothing is edited or deleted.
- A plausible list is **accepted** and becomes the current cost list.
- An implausible list is **quarantined** and changes nothing: the list is
  empty, more than 20% of extensions disappeared, or more than 20% of
  extensions changed register or renew cost by more than 50%.
- A failed run stores nothing; the last accepted costs stay in use.

Staging uses the Live API key (CEO decision, 24 Oct 2026) but can only read:
every registrar call that spends money or changes a domain is refused outside
production (DR-4).

## Alerts

Every active staff manager is emailed ("Price sync needs attention") when:

| Problem | When |
|---|---|
| Credential refused | The registrar answered 401/403 or the stored credential is unusable. Not retried. One email per streak. Fix: re-enter the credential on Provider connections. |
| Repeated failures | The third failed run in a row (rate limits, provider errors, network). Each run already retried up to 3 times (2 s, 8 s, 30 s). |
| Quarantined | Every quarantined list. Check the registrar's panel; a release flow for held lists comes with the admin cost list. |

## Commands (inside the provider worker)

| Command | What it does | Exit codes |
|---|---|---|
| `prices:sync` | Runs a sync now (`trigger=cli`). Prints provider, outcome, reason and attempts. | 0 accepted, 1 failed, 2 quarantined, 3 another run in progress |
| `prices:status` | Size of the newest accepted list and the last five runs. Codes, times and counts only. | 0 |

Settings (environment): `DOMAINNAMEAPI_ENVIRONMENT` = `live` (default) or
`ote`; production refuses `ote`. `REGISTRAR_WRITES=enabled` has an effect only
in production.
