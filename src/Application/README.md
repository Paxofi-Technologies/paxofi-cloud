# Application boundary

Application services orchestrate use cases and coordinate domain ports. They do not contain framework bootstrap logic or provider-specific behavior.

The PCF runtime is responsible for HTTP/bootstrap concerns. PaxofiCloud application services consume framework-provided contracts at this boundary.
