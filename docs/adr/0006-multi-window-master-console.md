# ADR 0006: Multi-window master console

Status: Accepted

## Goal

The GM may open independent panels across multiple monitors. Panels communicate instantly and may either follow the active character selection or remain pinned to a specific character. Panels include session overview, character sheet, dice feed, inventory and private notes.

## Two synchronization domains

- **Ephemeral workspace state**: active character selection, panel focus and pinning. Synchronize across same-origin windows via BroadcastChannel with a storage-event fallback when supported. Do not write every selection to the server. Do not broadcast private GM selection to players.
- **Authoritative session state**: rolls, sheet changes, inventory transfers and persisted GM notes. Persist on the Laravel backend and deliver permission-filtered events through Reverb. Each window independently authenticates, subscribes and reconciles missed events.

## Contract and security

Each workspace message has schema_version, workspace_id, sender_id, message_id, type, payload and timestamp. Ignore messages from other workspaces, own sender and unsupported versions. BroadcastChannel is not a trust boundary: validate payloads and never transmit tokens or secrets. A local selection is never interpreted as a server-authorized game action.

GM notes remain private to authorized masters; enforce this in HTTP and websocket channel authorization. Persist notes using optimistic concurrency or conflict detection. Client-side auto-save should be debounced and must surface conflicts instead of overwriting silently.

## User experience

- Open panel in new tab/window using a user gesture.
- Follow selection / pin character toggles.
- Dice feed can show authoritative events in real time and recover after disconnect.
- Layout and window positions are user-controlled. Do not require automatic placement on another monitor.
- Recover panel preferences where platform storage permits; support browsers without multi-screen window placement APIs.
- Show connectivity and stale-data states.

## Acceptance tests

Two windows selecting a character must synchronize in the same workspace; pinned panels must not switch; a different workspace must not receive selections; a disconnected panel must resynchronize authoritative state; a player must not access private GM notes.
