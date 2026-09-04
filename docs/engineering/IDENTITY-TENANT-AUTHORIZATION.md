# Identity and Tenant Authorization

## Purpose

PaxofiCloud treats tenant isolation as a server-side authorization invariant. A
client-provided tenant identifier is not an authorization decision.

## Request boundary

The PCF HTTP/application boundary supplies an immutable `RequestContext` with:

- authenticated identity identifier;
- authenticated tenant identifier;
- correlation identifier.

Application use cases must authorize the effective tenant before reading or
mutating tenant-owned state.

## Authorization rule

`AuthoritativeTenantAuthorizer` enforces both conditions:

1. the requested tenant equals the authenticated tenant in the request context;
2. the authenticated identity has authoritative membership in that tenant.

Failure produces `UnauthorizedTenantAccess` and must prevent the operation from
continuing.

## Persistence boundary

`TenantMembershipReader` is deliberately a port. The first implementation wave
does not prescribe a database schema or identity provider. A persistence adapter
will be introduced only when the identity/tenant storage decision is approved.

## Security invariants

- Never trust a tenant ID supplied only by the frontend.
- Never infer tenant membership from URL structure, session decoration, or UI
  state.
- Do not expose membership existence through distinguishable unauthorized
  responses unless the endpoint explicitly requires that behaviour.
- Authorization occurs before tenant-scoped reads and mutations.
- Cross-tenant access is denied even when the requested tenant identifier is
  syntactically valid.
