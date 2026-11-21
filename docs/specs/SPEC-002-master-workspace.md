# SPEC-002: Multi-window master workspace

Status: ACCEPTED (requirements); implementation status: PARTIAL

## Objective

A GM MUST be able to open multiple independent browser windows across monitors, each showing a chosen NOVTT panel and synchronizing relevant state in real time.

## Functional requirements

- MW-01: The master console MUST provide independent panels for session overview, character details/sheet, inventory, dice feed and private notes.
- MW-02: A panel MUST support follow-selection and pin-to-character modes. While pinned it MUST retain the latest incoming selection internally, without changing the rendered character. Unpinning MUST immediately render that latest selection.
- MW-03: Invalid/blank character identifiers MUST be rejected before any render or broadcast. A local selection MUST render once, not twice.
- MW-04: Same-origin windows in the same workspace SHOULD synchronize ephemeral selection through BroadcastChannel with a supported fallback. Different workspace IDs MUST remain isolated.
- MW-05: Ephemeral selection MUST NOT persist a server write for every click, and MUST NOT be exposed to players. Workspace messages MUST NOT contain authentication tokens.
- MW-06: Official rolls, character changes, inventory transfers and saved notes MUST be persisted by the backend before being projected via authorized Reverb channels.
- MW-07: Each window MUST independently recover from disconnection using authoritative snapshot/sequence reconciliation. Missing or duplicate events MUST NOT corrupt state.
- MW-08: Private GM notes MUST be protected by HTTP and websocket authorization, use autosave with conflict detection, and MUST NOT be visible to players.
- MW-09: The UI MUST distinguish live, reconnecting and stale state. Opening a panel in a new window MUST be user-initiated; monitor placement is user-controlled.
- MW-10: Workspace preferences MAY be restored locally when supported, without relying on browser APIs for automatic cross-monitor placement.

## Acceptance scenarios

1. Window A selects character X; following panel B displays X without reload.
2. B pins Y; A selects Z; B keeps showing Y; after unpin, B immediately displays Z.
3. Selecting an invalid blank ID neither renders nor publishes an event.
4. A local selection causes exactly one render.
5. A selection from workspace A is not delivered to workspace B.
6. An official roll reaches the master dice feed and, after disconnect, missing events are reconciled by session sequence.
7. A non-GM cannot fetch or subscribe to private notes.

## Implementation boundaries

`apps/master-web/resources/js/workspace-sync.ts` currently handles ephemeral messages; `character-panel.ts` handles follow/pin. Neither replaces the server event store, Laravel authorization or realtime backend.

## Traceability

ADR 0003 and 0006; `docs/ARCHITECTURE.md`.
