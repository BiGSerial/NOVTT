# SPEC-PRESET-ENGINE-001: Universal preset engine (Sprint 001 of the owner handoff)

**Status: ACCEPTED** (owner handoff 2026-11-23, `novtt-handoff/docs/agent/SPRINT-001-IMPLEMENTATION.md`).
Implementation: IMPLEMENTED in engine and API for the items marked below; not VERIFIED on real
devices. Authority: [ADR 0012](../adr/0012-universal-preset-engine.md), ADR 0008, ADR 0010,
SPEC-SPRINT-001, SPEC-PRESET-AUTHORING-001.

## Requirements

| Id | Requirement | Where |
|---|---|---|
| PE-01 | Versioned manifest with licence, rights status, provenance, rules revision, source/errata, capabilities, coverage, validated by JSON Schema and semantic checks | `contracts/presets/preset-manifest.v2.schema.json`, `PresetCompatibility` |
| PE-02 | Sheet schema, typed fields and collections, derived values through a safe AST with dependency analysis | `Formula/*`, `Rules/Mechanics`, `RulesCompatibility` |
| PE-03 | Creation in parameterized steps: pools, priorities, choices, prerequisites, validation trace | `rules/2 creation`, `Mechanics::creationProgress`, `check` |
| PE-04 | Progression by point purchase and by level/milestone, atomic, with approval and audit | cost models, `progression.tracks`, existing review workflow |
| PE-05 | Declarative roll strategies: d20 + modifiers and d10 success pools with failures and criticals | `Resolution/*` |
| PE-06 | Resources, items and effects as typed extensions | `rules/2 resources`, `inventory`, `effects` |
| PE-07 | Visibility/editability per user and master, sheet lock during a live session, auditable approval | sheet-ui access, `governance.lock_during_session`, API |
| PE-08 | Authorial demo exercising every flow, no proprietary content | `presets/novtt-demo/*` |
| PE-09 | Docs for human authors and agents, structured errors, valid and invalid examples | `docs/presets/PRESET_MANUAL.md`, `presets/novtt-demo/invalid-examples.json` |
| PE-10 | Rights gate: nothing without approved rights, attribution where required, quarantine hidden | `PublicationGate`, catalogue query, rights command |
| PE-11 | Premium never gates presets or core sheet capabilities (entitlements by service) | ADR 0012 §11 (boundary only, no billing) |

MUST: the core never branches on a system or family id; every game meaning comes from the preset.
MUST: clients never choose dice faces, counts, modifiers or limits for official rolls.
MUST NOT: publish third-party proprietary names, text or art without recorded rights.

## Acceptance tests (handoff SPRINT-001) and evidence

| Test | Covered by |
|---|---|
| 1. Pool creation 7/5/3, attributes start at 1, over-budget rejected, derived read-only and recomputed | `PresetEngineAcceptanceTest` (test_priority_*, test_allocating_*, test_a_derived_*) |
| 2. Steps identity → origin → attributes → review, choice prerequisite, incomplete does not activate | `PresetEngineAcceptanceTest` (choices, steps); API creation gate test |
| 3. XP purchase `current × 4` = 12, insufficient blocks, no double debit, rejection does not debit, approval atomic with audit | `PresetEngineAcceptanceTest`; API feature and concurrency tests |
| 4. XP/milestone unlocks level and benefits; a newer revision does not change the pinned snapshot | `PresetEngineAcceptanceTest` (levels, milestones, revision isolation); API pinning test |
| 5. `1d20+5`, `5d10` with variable difficulty and cancelling 1s by strategies; deterministic tests, secure production randomness | `ResolutionStrategyTest` |
| 6. Player cannot alter a locked/active sheet outside policy; master cannot forge the player; history records origin and snapshot | API feature tests |
| 7. Rights: not approved, CC BY without attribution, quarantined | `PresetPackageTest`; API catalogue tests |
| 8. Schemas and examples validate; suites pass; docs explain authoring | `PresetPackageTest`, `ContractSchemasTest`, CI |

## Entitlements boundary (PE-11)

Capabilities that MAY be premium: `ai.*`, `workspace.advanced_layout`, `storage.extra`, advanced
preparation and analysis for masters. Capabilities that MUST NOT consult entitlements:
`character.create`, `character.progress`, `roll.basic`, `preset.use` (for distributable presets),
`character.export`, reading one's own sheets after a cancellation. No billing is implemented.

## Out of scope (unchanged from the handoff)

Proprietary Vampire/World of Darkness names or text; full D&D books; D&D Beyond integrations or
scrapers; tactical maps or 3D simulation; operational billing. SRD import is prepared only as the
rights gate and provenance fields: no SRD content is shipped.

## Open decisions for the owner

1. Whether the D&D SRD 5.1 / 5.2.1 import pipeline is the next sprint, and who performs the human
   rights review (the gate records it, it does not decide it).
2. Whether preset-declared rolls (`rules/2 rolls`) replace the master-chosen dice of live roll
   requests (SPEC-MVP-002), or are offered alongside them. This changes the live roll contract.
3. Whether opposed rolls/resistances and timed effect expiry are required for the next preset.
4. The written answer from Paradox (LICENSE-MATRIX) before any World of Darkness content.
