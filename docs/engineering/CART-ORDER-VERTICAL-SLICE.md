# Cart → Order Vertical Slice

## Boundary

The Cart → Order application service is the controlled commerce mutation boundary following Catalogue → Pricing.

## Security

- The cart is loaded through `CartReader`, which is tenant-scoped at the persistence adapter boundary.
- The service independently invokes `TenantAuthorizer` against the tenant carried by the cart and the authenticated `RequestContext`.
- A client-supplied tenant identifier is never used as proof of authorization.

## Commercial integrity

At conversion time the service re-reads the authoritative catalogue product and current server-side price. A cart line is rejected when the product is missing, unavailable, or has no valid non-negative price.

The resulting order contains immutable commercial snapshot lines containing:

- product identifier
- product name
- quantity
- unit price in integer minor units
- explicit ISO-style currency
- calculated immutable line total

The order total is calculated from the snapshot lines and is constrained to a single currency. Integer overflow is rejected rather than converted to floating-point state.

## Persistence boundary

`CartReader` and `OrderWriter` are application ports. No physical database schema is introduced by this slice. Infrastructure adapters remain responsible for persistence implementation.

## Idempotency and audit

Order creation requires a non-empty idempotency key and records an audit event after the order write succeeds. The persistence adapter is responsible for enforcing durable idempotency semantics at the data boundary.

## Testing

`tests/Unit/CartOrder/CartToOrderServiceTest.php` exercises successful conversion, unavailable-product rejection, missing-price rejection, server-side authorization invocation, integer-money totals and immutable snapshot state. The quality gate executes these invariant tests directly under PHP 8.4.
