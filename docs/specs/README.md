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


## Approved for Sprint 001 (owner decision 2026-11-21)

| Spec | Scope | Status |
| --- | --- | --- |
| [SPEC-SPRINT-001](SPEC-SPRINT-001-approved.md) | Frozen Sprint 001 scope, authorization, sheet review, atomic approval | ACCEPTED / IMPLEMENTED (API, PWA, master review panel; not VERIFIED: Android and manual a11y pending) |
| [SPEC-MVP-002](SPEC-MVP-002-live-table.md) | Live table PoC: sessions, realtime, official rolls with 3D dice, master table, tablet pairing, Roteiro, Atlas | ACCEPTED (owner 2026-11-22) / IMPLEMENTED: API; clients in progress |
| [SPEC-PRESET-AUTHORING-001](SPEC-PRESET-AUTHORING-001.md) | Preset families, variants, personal library, canonical publication, human/AI guidance | ACCEPTED / IMPLEMENTED for the v1 subset (`docs/presets/PRESET_MANUAL.md`) |

**Status clarification:** The prior SPEC-005 remains PROPOSED only for details **not explicitly accepted** in the two specifications above. For overlapping requirements, these newer owner-approved specifications govern, subject to accepted ADRs. The candidate Sangue-Suga V20 preset has not been integrated or validated against the engine. Do not infer VERIFIED status from documentation.

## Proposed specifications

These are PROPOSED: they await owner approval and MUST NOT be implemented as assumed requirements.

| Spec | Scope | Authority |
| --- | --- | --- |
| [SPEC-004](SPEC-004-ui-localization.md) | Translatable interface, language selection, error codes instead of prose | ADR 0007 (Proposed) |
| [SPEC-005](SPEC-005-presets-and-character-sheet.md) | Preset editions, campaign overrides, fixed vs editable sheet fields | ADR 0008 (Proposed) |

## Change control

A change to a published contract MUST update its schema, compatibility notes and tests. New product decisions MUST be recorded in an ADR or approved spec amendment before implementation. Never infer that scaffolding means the feature is complete.
