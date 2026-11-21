# SPEC-005: Presets, editions and character sheet

Status: PROPOSED; implementation status: NOT STARTED (a scaffold exists at `presets/sangue-suga/v20/`)

Authority: ADR 0008 (Proposed). Nothing here is an accepted requirement until the owner approves the ADR.

## Objective

Describe how a preset edition defines the character sheet and its mechanics, how a game master customizes it per campaign, and which parts of the sheet are fixed or editable. Official roll resolution stays as defined in SPEC-003 and ADR 0004.

## Functional requirements

- PRS-01: A preset is identified by `family`, `edition`, `package_version` and `schema_version`. A published edition version is immutable; changes publish a new `package_version`. A campaign pins one edition version.
- PRS-02: A preset MAY contain structure, field kinds, limits, penalty tables, declarative rules and short default labels in `pt-BR`. It MUST NOT contain third-party rulebook prose, ability or power descriptions, lore, art, logos, titles, fonts or a reproduction of a sheet's visual design.
- PRS-03: The server resolves, in order: preset edition, campaign patch, character values. The result is deterministic for the same inputs.
- PRS-04: Field classes: FIXED (stable id, kind and limits from the preset), FREE (entries a player creates and names) and DERIVED (computed by rules).
- PRS-05: Field ids are stable and neutral (no brand terms). Labels are stored separately from ids, are editable per campaign and are not part of the localization catalogs of SPEC-004.
- PRS-06: Authorization is default-deny. The game master edits the campaign patch (rename, hide, add fields, change limits, edit rules). A player edits values and free entries of their own sheet, within resolved limits. Every patch change is audited with actor, time and previous value, and is reversible.
- PRS-07: A free entry MUST NOT change a derived value or an official roll pool unless the campaign policy allows manual modifiers and the game master approves that modifier. The default policy is that it does not.
- PRS-08: Derived values and conditional modifiers are declarative data evaluated by the generic engine. On save, the server MUST reject rules with dependency cycles, undefined references, division by zero, values outside numeric bounds, or size and evaluation cost above defined limits.
- PRS-09: Official rolls use only the pinned edition and approved campaign patch. The client MUST NOT send preset settings, faces, difficulty or limits (AGENTS.md, SPEC-003 OR-05).
- PRS-10: Edition limits (maximums, meter sizes, penalty tables) are data in the edition and MUST NOT be hard-coded in the engine.
- PRS-11: Publishing a newer preset version MUST NOT discard a campaign patch. A campaign stays on its pinned version until the game master upgrades; conflicts between the patch and the new version are reported, not silently resolved.
- PRS-12: Campaigns, patches, labels and sheets are private to campaign members and MUST NOT be publicly listable or indexed. Any export or sharing is an explicit action. A public gallery or marketplace requires terms of use, a content-removal path and legal review before it is built.
- PRS-13: User-facing product text (interface chrome, store listings, documentation, marketing) MUST NOT use third-party trademarks or imply affiliation. This does not restrict what a user writes in their own private campaign.
- PRS-14: The server MUST validate sheet values against the resolved schema on every write, including kind, minimum, maximum and unknown-field rejection.
- PRS-15: Where the edition prints alternative labels for one trait, the choice of label is stored per character and the alternative is selectable by the game master or the player according to the patch.

## Edition `v20`: fixed and editable fields

Source: a blank sheet supplied by the owner (3 pages). The exhaustive list of default labels goes into the preset manifest when implemented, not into this document. The sheet itself MUST NOT be added to the repository.

| Area | Fixed by the edition | Editable |
| --- | --- | --- |
| Identity | Nine labeled text fields | Values |
| Attributes | 9 traits in 3 groups of 3, rating dots | Values; labels and limits by the game master |
| Abilities | 3 groups with 10 named traits each, rating dots | Values; 1 extra free line per group |
| Disciplines | Section and rating dots | 5 player-named lines with values |
| Backgrounds | Section and rating dots | 5 player-named lines with values |
| Virtues | 3 traits; two of them print an alternative label | Values; choice of alternative label |
| Humanity | 10 dots | Values; one free text line for the attitude |
| Willpower | 10 dots and 10 boxes | Current and maximum values |
| Blood pool | Box grid | Current value |
| Health | 7 levels; printed penalties for levels 2 to 6 are -1, -1, -2, -2, -5; level 1 prints none and level 7 is incapacitated | Box marking per level |
| Other traits | Section | Free lines, with or without rating dots |
| Weaknesses, derangements | Section | Free text |
| Merits and flaws | Columns: name, type, cost or bonus | Rows |
| Rituals | Columns: name, level | Rows |
| Paths | Rating dots (5) | 7 named lines with values |
| Experience | Total, total spent, spent on | Values |
| Combat | Columns: weapon or attack, difficulty, damage, range, rate, clip, concealable | Rows |
| Armor | Fields: type, rating, penalty, description | Values |
| Expanded backgrounds | 10 named lists | List entries |
| Possessions | 4 lists (equipped, acquired, hunting grounds, vehicles) | List entries |
| Havens | Location and description columns | Rows |

Health penalties are rule data (PRS-10), not interface text.

## Acceptance scenarios

1. A value above the resolved maximum is rejected by the server and the stored sheet is unchanged.
2. A campaign patch that renames an attribute shows the new label to all members and leaves the id and rule references intact.
3. A rule with a dependency cycle is rejected when saved and the previous patch stays active.
4. A newer `package_version` does not alter a campaign pinned to the previous version, and upgrading reports any patch conflict.
5. A player adds a free entry with a numeric bonus: the derived values and an official roll pool are unchanged until the game master approves the modifier.
6. A non-member cannot read a campaign's labels, patch or sheets.
7. Two requests produce identical resolved sheets for identical preset, patch and values.

## Open decisions for the owner

1. Confirm the printed limits as the `v20` limits: the sheet shows six circles for attributes, abilities, disciplines, backgrounds and other traits, five for virtues and paths, and ten for humanity and willpower. The capacity of the blood pool grid also needs confirming.
2. The catalogue of derived values and buffs for `v20` (which attribute combinations produce which bonuses). This specification defines the mechanism only.
3. The default and the scope of the manual-modifier policy in PRS-07.
4. Which of the two label alternatives for the paired virtues is the default, and who may switch it.
5. Legal review before any public sharing feature (PRS-12), and whether the product needs a visible non-affiliation notice.
6. Whether edition inheritance (`extends`) is needed after the first edition.

## References

- `presets/sangue-suga/v20/manifest.json`, `presets/sangue-suga/v20/sheet.schema.json` (scaffold)
- ADR 0004, SPEC-003, ADR 0007, SPEC-004
