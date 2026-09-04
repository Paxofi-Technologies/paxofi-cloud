# Paxofi Core Framework v1.0.0 Integration

## Decision

PaxofiCloud backend/application engineering uses the Paxofi Core Framework (PCF) as its PHP application framework boundary. PaxofiCloud does not use Laravel or another PHP application framework.

The first formally accepted PCF release is **v1.0.0**, published from the PCF repository and tagged `v1.0.0`.

## Verified compatibility

- PCF: `v1.0.0`
- PHP minimum: `8.4`
- PHP engineering reference baseline: `8.5`
- Redis: `7`
- MySQL acceptance environment: `8.4`

PCF v1.0.0 was accepted following its Implementation Readiness Review and Framework Acceptance Review. Its accepted evidence covers bootstrap, HTTP kernel/routing, controller dispatch, dependency injection, PDO persistence, transactions, idempotency/repeated-request behavior, request IDs, security headers, static analysis, coding standards, PHPUnit/coverage, and dependency/security validation.

## Consumer boundary

PCF owns reusable framework capabilities. PaxofiCloud owns product-specific business logic, domain rules, tenant authorization policy, catalogue/pricing rules, commerce state, billing, payment orchestration, provisioning policy, provider selection, and customer-facing product behavior.

PaxofiCloud application code remains under the `PaxofiCloud\\` namespace. PCF is consumed as the Composer package `paxofi-technologies/paxofi-core-framework` and exposes its framework namespace independently.

## Dependency policy

The dependency is pinned to the accepted `1.0.0` release rather than a moving branch. The repository is declared as a VCS source so the private GitHub package can be resolved by Composer.

Future PCF upgrades must be treated as governed dependency changes and validated against the PaxofiCloud compatibility surface before adoption.

## Release references

- PCF release: `v1.0.0`
- PCF repository: `Paxofi-Technologies/paxofi-core-framework`

## Implementation rule

All new PaxofiCloud backend implementation must be designed to consume PCF services, contracts, kernel/runtime capabilities, and infrastructure abstractions where applicable. Application code must not recreate framework infrastructure or introduce Laravel-style compatibility layers merely for familiarity.
