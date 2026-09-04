# Domain boundary

Domain modules own PaxofiCloud business concepts and invariants. They must not depend on provider SDKs, HTTP clients, framework internals or frontend concerns.

The first vertical slice will introduce domain behavior incrementally, beginning with Identity and Tenant authorization.
