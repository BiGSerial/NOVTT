# NOVTT functional specification index

These specifications translate approved product discussions into implementation and acceptance criteria for human and local-AI contributors. Read `/AGENTS.md` first.

## Status and language

- **ACCEPTED**: product decision already approved; mandatory unless superseded by an accepted ADR or explicit owner instruction.
- **PROPOSED**: design suggestion awaiting owner approval; MUST NOT be implemented as an assumed requirement.
- **PLANNED**: accepted behavior not yet fully implemented.
- **IMPLEMENTED**: code exists but may lack complete validation.
- **VERIFIED**: acceptance tests passed in the stated environments.

MUST / MUST NOT = requirement. SHOULD = preferred with documented reason to deviate. MAY = optional. Status is tracked per capability, not inferred from existence of a file.

## Accepted specifications

| Spec | Scope | Authority |
| --- | --- | --- |
| [SPEC-001](SPEC-001-player-clients.md) | Android/PWA parity, offline dice and client constraints | ADR 0002, 0003, 0005 |
| [SPEC-002](SPEC-002-master-workspace.md) | Master multi-window workspace and realtime | ADR 0003, 0006 |
| [SPEC-003](SPEC-003-official-rolls.md) | Server-authoritative rolls, attempts, idempotency and events | ADR 0003, 0004 |

## Change control

A change to a published contract MUST update its schema, compatibility notes and tests. New product decisions MUST be recorded in an ADR or approved spec amendment before implementation. Never infer that scaffolding means the feature is complete.
