# Paxofi Core Framework (PCF) Integration

## Decision

PaxofiCloud backend/application engineering uses the Paxofi Core Framework (PCF) as its PHP application framework boundary. PaxofiCloud does not use Laravel or another PHP application framework.

## Current pinned release

| Item | Value |
|---|---|
| PCF release | **v1.1.0** (commit `cd6a1f5`, published 5 Sept 2026) |
| Previous release | v1.0.0 (4 Sept 2026) |
| PHP minimum | 8.4 (CI: 8.4 required gate, 8.5 compatibility job) |
| MySQL acceptance environment | 8.4 |
| Redis | 7 |

### What v1.1.0 adds over v1.0.0

v1.1.0 is additive and backwards compatible with v1.0.0. It adds the **Configuration & Environment Foundation**:

- `Paxofi\Core\Configuration\EnvLoader` — parses `.env` without mutating process-global environment state; process variables take precedence.
- `Paxofi\Core\Configuration\Environment` — immutable typed accessor (`get`, `integer`, boolean helpers) with canonical environment names (`development`, `testing`, `staging`, `production`).
- `Paxofi\Core\Configuration\FileLoader` / `Bootstrap` / `Cache` — loads `config/*.php` (array or `fn (Environment $env): array`), with an atomic, deployment-local config cache.
- `Paxofi\Core\Configuration\Validator` — fail-fast validation of required variables at bootstrap.

PaxofiCloud will use this for all runtime configuration (database, Redis, provider endpoints). Secrets are supplied only via environment variables / CI secrets, never committed.

Verification of the upgrade (5 Oct 2026, PHP 8.4.26): PCF v1.1.0's own suite passes (179 tests, 6 MySQL-only skips; Redis 7 integration tests green) with PHPStan clean; PaxofiCloud's unit suite and PHPStan (level max) pass against v1.1.0.

## Consumer boundary

PCF owns reusable framework capabilities. PaxofiCloud owns product-specific business logic, domain rules, tenant authorization policy, catalogue/pricing rules, commerce state, billing, payment orchestration, provisioning policy, provider selection, and customer-facing product behavior.

PaxofiCloud application code remains under the `PaxofiCloud\` namespace. PCF is consumed as the Composer package `paxofi-technologies/paxofi-core-framework` under `Paxofi\Core\`.

## Dependency policy

- The dependency is pinned to an exact accepted release and locked in `composer.lock`.
- PCF is a **private** repository. Composer resolves it as a `vcs` repository with `no-api: true` (git transport).
- CI needs a repository secret **`PCF_READ_TOKEN`**: a fine-grained personal access token (or GitHub App token) with **read-only `Contents` access to `paxofi-core-framework` only**. Without it, the quality workflow runs in *standalone* mode (PCF removed, warning annotation) so unit tests and static analysis still run; once PaxofiCloud code imports `Paxofi\Core\*`, standalone mode fails by design and the secret becomes mandatory.
- Dependabot's composer updates need the same read access configured as a Dependabot secret / private registry.
- Future PCF upgrades are governed dependency changes: run PCF's suite, PaxofiCloud's suite and PHPStan against the new tag before bumping the pin.

## Implementation rule

All new PaxofiCloud backend implementation must consume PCF services, contracts, kernel/runtime capabilities, and infrastructure abstractions where applicable. Application code must not recreate framework infrastructure. See `PCF-DEVELOPER-REFERENCE.md` for the API surface and the capability gaps PaxofiCloud must fill in its own infrastructure layer.
