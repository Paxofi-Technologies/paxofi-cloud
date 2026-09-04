# Catalogue and Pricing Contracts

## Scope

This implementation wave establishes the first read-side Catalogue → Pricing boundary for the PaxofiCloud vertical slice. It intentionally keeps persistence behind application contracts and does not introduce a physical schema.

## Invariants

1. Catalogue access is performed through `CatalogueReader`.
2. Pricing access is performed through `PricingReader`.
3. Product identity is represented by `ProductId`; callers do not rely on mutable display names as identity.
4. Monetary state is represented by `Money` integer minor units plus an explicit three-letter uppercase currency code.
5. No floating-point value is used for monetary state.
6. Tenant authorization is executed server-side before catalogue or pricing data is returned to an application caller.
7. The frontend cannot establish authorization by supplying a tenant identifier.
8. Database persistence remains behind ports until the approved data-model gate permits implementation.

## Next boundary

The next implementation wave is Cart → Order. It must validate product and price data server-side and persist an immutable commercial snapshot at order creation rather than trusting values supplied by the frontend.
