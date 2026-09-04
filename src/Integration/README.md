# Integration boundary

External systems are isolated behind contracts and adapters. Application and domain layers must not call provider SDKs directly.

Initial provider domains include infrastructure, hosting, registrar and payment providers. Provider implementations will be added only after their contracts and operational failure semantics are confirmed by the controlled product specification.
