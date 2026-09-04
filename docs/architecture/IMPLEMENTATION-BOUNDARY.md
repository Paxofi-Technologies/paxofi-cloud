# PaxofiCloud Implementation Boundary

## Controlled baseline

PaxofiCloud implementation shall conform to the controlled product specifications in Notion and the executable work managed in ClickUp.

## Architecture boundary

PaxofiCloud owns product/domain logic. PCF provides the application framework boundary. Provider-specific integrations are isolated behind contracts and adapters.

## Required controls

1. Tenant isolation and authorization are enforced server-side.
2. External operations are asynchronous where appropriate and are idempotent.
3. Provider failures are normalized and observable.
4. Financial state changes are auditable.
5. Secrets and provider credentials never enter source control.
6. Production changes require review, tests and release evidence.
7. Material architectural deviations require an ADR.
8. Frontend clients do not call infrastructure-provider APIs directly.

## Initial vertical slice

Identity → Tenant → Catalogue → Cart → Order → Invoice → Payment → Fulfilment → Provisioning → Service → Portal.

This document is implementation guidance and does not replace the authoritative Notion specifications.
