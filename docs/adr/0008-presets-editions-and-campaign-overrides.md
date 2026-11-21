# ADR 0008: Presets, editions and campaign overrides

Status: Proposed (awaiting owner approval)

## Context

A preset is the default configuration of a game mode. The owner's direction is that the platform ships character sheets and mechanics for a traditional system, without carrying any brand, art or rulebook text, and that the person running a campaign decides what game they are narrating. Limits and fixed lists vary by edition of a system, which is why `family` and `edition` already exist in `presets/<family>/<edition>/manifest.json`.

The first edition is `sangue-suga` / `v20`. The owner supplied a blank commercial sheet as the reference for its structure. That sheet is a reference only and MUST NOT be committed to the repository.

## Decision

1. **The edition is the unit of rules.** A preset edition defines the fixed attribute list, field kinds, limits, meters, penalty tables and derived or conditional rules for one system edition. Limits are data in the edition, never constants in the engine.
2. **Three layers, resolved on the server in this order:** preset edition (immutable once published) -> campaign patch (changes made by the game master) -> character values (entered by players). Updating a preset never destroys a campaign patch; a campaign stays pinned to an edition version until the game master upgrades it.
3. **Fixed vs free.** Fixed fields have stable ids, a kind and limits defined by the preset. Free fields are entries a player creates and names. Players edit values only; the game master changes structure through the campaign patch.
4. **Labels are data, not translations.** Default labels ship in `pt-BR` and are editable per campaign. They are outside the localization catalogs of ADR 0007.
5. **Rules are declarative data.** Derived values and conditional modifiers are validated, bounded expressions over fields. The engine stays generic and contains no per-game code (`packages/engine` independence is preserved).
6. **Free entries do not influence official rolls by default.** A player-created entry changes an official roll only through a modifier that the campaign policy allows and the game master approves. This protects ADR 0004: the server resolves pools, and the client never chooses them.
7. **Content boundary.** Presets contain structure, mathematics and short functional default labels. They contain no rulebook prose, ability descriptions, lore, art, logos, titles, fonts or reproductions of a sheet's visual design.
8. **Private by default.** Campaigns, patches, labels and sheets are visible only to campaign members and are not publicly listable. Any sharing or public gallery is a separate decision that requires terms of use, a content-removal path and legal review first.
9. **Naming.** The ids `sangue-suga` and `v20` are internal identifiers chosen by the owner. User-facing text (interface chrome, store listing, documentation, marketing) MUST NOT use third-party trademarks or imply affiliation. What a user types into their own private campaign is their content.
10. **Edition inheritance (`extends`) is deferred.** The first version ships a single edition without inheritance.

## Alternatives considered

- **Translatable preset labels through catalogs.** Rejected by the owner; labels are editable data.
- **Copying only attributes and leaving everything else free.** Replaced by the owner's later decision to carry the printed structure of the edition sheet as the preset.
- **One mutable preset per campaign (no layering).** Rejected: upgrades would overwrite GM changes and the difference from the base would be unrecoverable.
- **Hard-coded rules per game in the engine.** Rejected: violates the generic-engine rule in `AGENTS.md`.

## Consequences

- A resolver and a validator for the patch layer are required (acyclic dependencies, bounded size and evaluation cost, numeric limits, division by zero).
- Patch changes are audited and reversible.
- The concrete catalogue of derived values and buffs for `v20` still has to be specified by the owner (see SPEC-005 open decisions) before it is implemented.
- Sharing features are blocked until the legal prerequisites in decision 8 are met.
