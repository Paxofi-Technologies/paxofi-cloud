# ADR-001 — PaxofiCloud Application Boundary

- **Status:** Accepted
- **Scope:** First implementation wave
- **Date:** 2026-09-04

## Decision

PaxofiCloud application/domain code will remain independent of provider SDKs and will consume the Paxofi Core Framework through a defined framework boundary. Provider-specific operations are exposed to the application through contracts and adapters.

## Rationale

This preserves the platform's control-plane ownership, keeps provider replacement feasible, and prevents infrastructure concerns from leaking into customer-facing domain contracts.

## Consequences

- Application code can be tested without provider credentials.
- Provider adapters can evolve independently of commerce/domain models.
- PCF remains the framework/runtime owner rather than being reimplemented inside PaxofiCloud.
- Material deviations require a new ADR before implementation.

## First-wave guardrails

1. Tenant authorization is server-side.
2. Correlation IDs are available at the application boundary.
3. Material mutations have an audit recording port.
4. Provisioning is represented as an asynchronous, idempotent operation.
5. No secret or provider credential is committed to source control.
