# ADR 0012: Universal preset engine (rules/2), roll strategies and rights gate

Status: Accepted (owner handoff "presets, licenças, Sprint 001", 2026-11-23; adapted to this repository)
Amends: ADR 0008 (presets, editions and campaign overrides), ADR 0010 (sheet change validation layers)
Source: `novtt-handoff/` (ADR-001, ADR-002, PRODUCT-001, LICENSE-MATRIX, SPRINT-001)

## Context

The owner's handoff asks for one engine that creates, validates, rolls and progresses characters
for very different games (d20 with levels, d10 pools with point-buy, original systems) without
system-specific code, with maximum fidelity per preset (ADR-002), immutable versioning of rules,
master governance and a licensing gate. The repository already had most of the governance:

- immutable preset versions pinned by campaigns, three validation stages, typed sheet operations,
  per-field access, budgets with per-increment provenance, phase costs including `current × n`,
  proposals reviewed per operation by the master and applied atomically under row locks
  (ADR 0010, SPEC-SPRINT-001);
- a crypto random source and official server rolls (ADR 0004).

What was missing: derived values, effects, choices with prerequisites, n-ary constraints, creation
steps, level/milestone progression, declarative rolls read from the sheet, a compendium with
provenance, and any notion of distribution rights.

The handoff's example manifest (`schema_version`, `id`, `capabilities`, `publication`) does not
match this repository's `novtt.preset-manifest/1` (`contract`, `family_id`, `variant_id`, `files`),
and its rights lifecycle (`draft, rights_review, approved, quarantined, published, retired`) differs
from the technical lifecycle of `preset_versions.status` (`draft → … → published`). These were
reported as contradictions and resolved as below rather than by silently picking one.

## Decision

1. **Extend, do not duplicate.** The existing `SheetChangeEngine` pipeline stays the single entry
   point for creation and progression changes. New mechanics plug in as whole-candidate checks.
   `novtt.rules/2` is a strict superset of `novtt.rules/1`; rules/1 packages keep their behaviour.
2. **Four layers (ADR-002).** Core primitives (`packages/engine`), ruleset (`rules.json`),
   compendium/taxonomy (`compendium.json`, optional) and presentation (`sheet.ui.json`). The core
   contains no game names; a test fails if it ever does.
3. **Formula language.** An allow-listed JSON AST (`add, subtract, multiply, divide, floor, min,
   max, compare, and, or, not, if, lookup, sum, count` plus `const, ref, derived, budget, var,
   item`). Checked statically (unknown operators and keys rejected, max depth 24, 256 nodes per
   formula, references must exist, context restricts what each formula may read) and evaluated by
   a pure interpreter with a shared step budget (50 000 per evaluation) and numeric bounds
   (±10⁹). No `eval`, no scripts, no remote references.
4. **Derived values are never authority.** They are computed on read with a decision trace (base,
   each effect, source, applied or not) and writes to their paths are rejected for every actor,
   including the master. Dependencies between derived values and effects must be acyclic.
5. **Effects are typed modifiers** of derived values: `add` effects stack per `stack_group`
   (`sum` or `highest`), the highest-priority `set` overrides. Sources are rules or collection
   items (e.g. equipped objects). Duration is declared metadata; timed expiry is a live-session
   concern, not implemented here.
6. **Progression without assuming levels.** Point-buy uses budgets and cost models (now including
   a `formula` model with `current`/`new`). Levels and milestones are `progression.tracks`: an
   integer path, a measure formula (e.g. XP granted, milestones granted, or the larger of both)
   and ascending thresholds; one step per change by default; benefits come from budget amounts
   and derived values that read the level. XP for level tracks is granted, not spent.
7. **Rolls by generic strategies.** `sum` (dice groups, keep highest/lowest, modifier, target,
   natural critical/fumble, edge = one extra die kept best/worst) and `pool` (successes at or
   above a difficulty, optional cancelling faces, bonus successes, bounded explosion, botch).
   Presets declare rolls; requests may only pick declared inputs (e.g. one attribute and one skill
   among declared targets) and bounded parameters (difficulty, target, edge). Faces, dice counts
   and modifiers are never client-chosen (ADR 0004). Tests inject a deterministic source;
   production uses `random_int`.
8. **Three identifiers, never conflated.** `version` (SemVer of the encoding), `rules_revision`
   (edition/revision of the game) and `source_version` / `errata_reference` (document consulted).
   Characters and campaigns pin a preset version; live sessions record the version at start.
   Migration between revisions stays explicit (ADR 0008).
9. **Two status axes.** `preset_versions.status` keeps the technical lifecycle. A new, mutable
   `rights_status` carries the handoff's rights lifecycle. The public (canonical) catalogue lists
   a version only when it is technically `published` **and** rights are `approved` or
   `published`. Canonical publication requires `novtt.preset-manifest/2` and passes the
   `PublicationGate`: approved rights, licence, provenance, verified compendium entries, matching
   content hashes and, for CC-BY (or flagged) licences, the exact attribution text. Quarantined
   content never appears publicly; pinned campaigns keep working.
10. **Governance.** Creation and progression keep the existing proposal → master decision per
    operation → atomic finalize path. New: activation of a rules/2 character requires complete
    creation steps; while a campaign has an active live session and the preset sets
    `governance.lock_during_session`, players cannot submit proposals.
11. **Entitlements boundary (PRODUCT-001).** Premium capabilities are named by service
    (`ai.*`, `workspace.advanced_layout`, `storage.extra`), never by preset or game. Character
    creation, progression, basic rolls, use of distributable presets and export never consult
    entitlements. Billing is out of scope.

## Alternatives considered

- **Formula strings with a parser** (e.g. `"floor((str-10)/2)"`): rejected for now; a JSON AST is
  easier for agents to generate exactly, needs no tokenizer attack surface and is checked with the
  same tools. A string syntax may compile to the AST later.
- **Embedded scripting (Lua, JavaScript sandbox, PHP closures)**: rejected: violates "no
  arbitrary code" and makes cost and determinism hard to bound.
- **A third-party rules library (e.g. JSON Logic)**: rejected: adds a runtime dependency to the
  framework-free engine, lacks path/budget semantics and bounded evaluation guarantees.
- **One engine per game family**: rejected by AGENTS.md and ADR-001.
- **Reusing `preset_versions.status` for rights**: rejected: technical validity and distribution
  rights change independently (a valid preset can be quarantined later).

## Consequences

- `packages/engine` grows `Formula`, `Rules`, `Resolution` and `PublicationGate`; still PHP only.
- Contracts added: `novtt.preset-manifest/2`, `novtt.rules/2`, `novtt.compendium/1`.
- The two canonical fixtures move to manifest/2 (original content, rights approved by the team).
- Known gaps, recorded instead of faked: opposed rolls and resistances as first-class strategies,
  timed effect expiry, resource spending during sessions from rules, preset-declared rolls wired
  into live official rolls, conformance suites shipped inside packages, `extends` between editions,
  and an import pipeline for licensed SRD content. None of these is implemented here.
- No content from D&D, World of Darkness or any other third party is added. Vampire-like
  mechanics are covered only by the authorial `demo-linhagens` preset.
