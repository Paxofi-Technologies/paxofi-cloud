# PaxofiCloud Build Readiness

## Purpose

This document defines the repository-level evidence required before production implementation is considered Build Ready.

## Gate 3 minimum evidence

1. Approved product requirements and acceptance criteria.
2. Architecture and security baselines reconciled with implementation boundaries.
3. Repository governance and pull-request controls active as engineering policy and, where supported, as GitHub enforcement.
4. CI executes deterministically on pull requests and mainline changes.
5. Automated test strategy and verification matrix established for applicable implementation layers.
6. Dependency and supply-chain controls established.
7. Secrets are not committed; runtime credentials are externalised.
8. Database migration and configuration-change strategy is defined before schema-changing work.
9. Observability requirements are identified for production-facing components.
10. Rollback and recovery expectations are defined for release-impacting changes.
11. ClickUp execution task and authoritative Notion specification references exist.

## GitHub operating baseline

PaxofiCloud is currently a public GitHub repository and operates on the current GitHub Free baseline. The active `PaxofiCloud Main Branch Protection` ruleset targets the default `main` branch and is actively enforced.

The active main-branch ruleset requires exactly these five executable status checks:

- `Repository governance checks`
- `Engineering baseline validation`
- `Quality baseline`
- `Analyze (actions)`
- `Dependency Review`

The generic `strict` status-check entry was deliberately removed. Branch currency is still enforced through the ruleset's strict required-status-check policy (`Require branches to be up to date before merging`).

The repository uses application-layer and supply-chain controls that are independent of paid GitHub plans:

- Pull requests are the required engineering workflow.
- `PaxofiCloud CI` provides repository governance and engineering baseline checks.
- `PaxofiCloud Quality Baseline` provides PHP syntax, PHPStan static analysis, Composer, Node/npm, and repository-quality validation as applicable.
- PHPStan is pinned to an exact tool version in CI and governed by `phpstan.neon.dist` at analysis level 8 with PHP 8.4 as the analysis baseline.
- PHPStan execution is intentionally deferred when no PHP source exists yet; this repository is still pre-production-implementation. Once PCF/PHP source is introduced under `src`, the same required quality gate executes static analysis automatically.
- Dependency Review is enabled for pull requests.
- CodeQL is enabled for supported repository content: JavaScript/TypeScript and GitHub Actions. CodeQL does not analyze PHP; PHP security analysis therefore uses the dedicated PHP/static-analysis strategy.
- OpenSSF Scorecard is enabled for public-repository supply-chain posture monitoring.
- Dependabot is configured for Composer, npm, and GitHub Actions dependencies.
- PR templates require product, ClickUp, Notion, testing, security, observability, rollback and documentation evidence.

### Future GitHub Team/paid-plan upgrade

A future GitHub Team upgrade is not a prerequisite for the current public-repository operating model. If the repository later becomes private or the programme requires additional organization-level controls, the paid-plan decision must be revisited as a capability expansion rather than treated as a reason to redesign the application architecture.

Future paid-plan capabilities should be evaluated for private-repository protection, organization-wide rules, additional security controls, team governance, and other enterprise needs. Existing workflows and controls should be retained where they remain useful.

## Implementation boundary

Gate 3 approval authorizes controlled production implementation; it does not authorize bypassing security, QA, financial-integrity, tenant-isolation, or release controls.

## First vertical slice

Identity → Tenant → Catalogue → Cart → Order → Invoice → Payment → Fulfilment → Provisioning → Service → Portal.

Each slice must retain requirement traceability and satisfy its applicable Definition of Done before progression.
