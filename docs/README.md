# PaxofiCloud Engineering Documentation

This repository contains implementation-facing engineering material only.

Authoritative product and architecture specifications remain in Notion. ClickUp is the execution system. GitHub is the source-control and engineering-change authority.

Do not copy uncontrolled product requirements into this repository.

## Engineering references

- [PCF developer reference](engineering/PCF-DEVELOPER-REFERENCE.md) — the Paxofi Core Framework v1.1.0 API surface as implemented, and the capabilities PaxofiCloud must build on top of it.

- [Database migrations](engineering/MIGRATIONS.md) — writing migrations and the rules `bin/paxoficloud migrate` enforces.

## Security

- [Identity threat model](security/THREAT-MODEL-IDENTITY.md) — STRIDE threat model for identity, sessions, tenant isolation and audit (Sprint 1). Identity PRs cite its threat IDs and add its tests.
