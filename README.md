# PaxofiCloud

PaxofiCloud is Paxofi's cloud commerce, control-plane, orchestration and digital-infrastructure platform.

## Authority model

- Notion: authoritative product strategy, requirements, specifications, architecture, governance and readiness records.
- ClickUp: authoritative delivery execution, ownership, priorities, dependencies and milestones.
- GitHub: authoritative source control, engineering changes, CI/CD evidence and releases.

## Engineering boundary

PaxofiCloud application and domain logic is built on the Paxofi Core Framework (PCF). External provider APIs are accessed only through governed provider contracts and adapters.

## Initial provider domains

- Hetzner: compute/cloud infrastructure
- cPanel/WHM: hosting
- Domain providers: governed registrar abstraction
- Payment providers: governed payment abstraction

## Delivery principle

No production implementation bypasses applicable product, architecture, security, QA, operational or release controls.

The controlled product baseline is maintained in the PaxofiCloud Product Documentation Library in Notion.
