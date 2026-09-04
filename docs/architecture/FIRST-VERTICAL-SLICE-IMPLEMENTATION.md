# PaxofiCloud First Vertical Slice — Implementation Record

## Status

Wave 1 — application skeleton established.

## Authority

The controlled first-slice implementation specification in Notion is authoritative. This repository implements only the approved engineering boundary and must not invent product behavior where the specification is silent.

## Current slice boundary

```text
PCF runtime boundary
        |
        v
Application contracts
        |
        +--> Identity
        +--> Tenant
        +--> Catalogue
        +--> Cart / Order
        +--> Billing / Payment
        +--> Fulfilment / Provisioning
        +--> Service lifecycle
        +--> Portal read models
        |
        v
Provider contracts / adapters
```

## Wave 1 implementation

- Composer package metadata and PSR-4 application namespace.
- Immutable identity and tenant identifiers.
- Request context carrying authenticated identity, tenant and correlation ID.
- Server-side tenant authorization contract.
- Audit recording contract for material mutations.
- Asynchronous provisioning operation contract with an idempotency key.
- Provider adapter boundary that prevents provider-specific APIs from becoming application contracts.

## Deliberate non-implementation

- No database schema is committed in this wave.
- No provider credentials or provider API calls are implemented.
- No frontend-to-provider integration exists.
- No payment state transitions are implemented until the approved commerce/payment contract is brought into the implementation branch.
- No PCF internals are reproduced inside PaxofiCloud. The application consumes the PCF boundary rather than duplicating the framework.

## Next controlled wave

Implement Identity + Tenant authorization against the approved logical model, followed by the catalogue/pricing read path. Each wave remains reviewable, testable and merge-gated.
