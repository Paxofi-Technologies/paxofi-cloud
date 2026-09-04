# PaxofiCloud Build Readiness

## Purpose

This document defines the repository-level evidence required before production implementation is considered Build Ready.

## Gate 3 minimum evidence

1. Approved product requirements and acceptance criteria.
2. Architecture and security baselines reconciled with implementation boundaries.
3. Repository governance and pull-request controls active as an engineering policy.
4. CI executes deterministically on pull requests and mainline changes.
5. Automated test strategy and verification matrix established for applicable implementation layers.
6. Dependency and supply-chain controls established.
7. Secrets are not committed; runtime credentials are externalised.
8. Database migration and configuration-change strategy is defined before schema-changing work.
9. Observability requirements are identified for production-facing components.
10. Rollback and recovery expectations are defined for release-impacting changes.
11. ClickUp execution task and authoritative Notion specification references exist.

## GitHub Free operating constraint

PaxofiCloud currently uses GitHub Free with a private repository. Native protected branches/rulesets and GitHub Advanced Security features are not available for this repository under the current plan. This is an accepted programme constraint and must not be represented as technical enforcement.

Compensating controls are mandatory:

- Pull requests are the required engineering workflow by policy.
- CI runs against pull requests and the foundation/mainline workflow.
- PR templates require product, ClickUp, Notion, testing, security, observability, rollback and documentation evidence.
- Merge decisions remain governed by the programme even where GitHub cannot technically prevent direct updates.
- Free/open-source static-analysis and quality tooling must provide application-layer verification.
- Native GitHub branch enforcement may be reconsidered later if contributor scale, risk, compliance or release criticality justifies a paid plan.

## Implementation boundary

Gate 3 approval authorizes controlled production implementation; it does not authorize bypassing security, QA, financial-integrity, tenant-isolation, or release controls.

## First vertical slice

Identity → Tenant → Catalogue → Cart → Order → Invoice → Payment → Fulfilment → Provisioning → Service → Portal.

Each slice must retain requirement traceability and satisfy its applicable Definition of Done before progression.
