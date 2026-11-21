# ADR 0003: Free dice, offline behavior, and reconnection

Status: Accepted

## Two trust domains

1. **Free dice**: a standalone Android feature that works without account, campaign or network. Random results are generated on-device and labeled LOCAL / UNOFFICIAL. They can be animated, saved locally, and shared manually. They never become official session rolls.
2. **Mastered session**: the backend alone generates authoritative rolls and enforces preset, character, permission and session rules. Client-generated rolls are not accepted as official events.

Both modes may reuse a versioned dice-expression grammar and deterministic evaluation semantics, but MUST have separate random sources, execution paths, histories and provenance. The server never trusts client-reported outcomes.

## Connection state machine

CONNECTED -> DEGRADED -> OFFLINE -> RECONNECTING -> SYNCING -> CONNECTED.

- CONNECTED: authenticated requests and authorized events are enabled.
- DEGRADED: connection health is uncertain; do not start a second roll for an in-flight request.
- OFFLINE: show cached sheets as read-only, block official commands, and offer a clearly separate free-dice interface.
- RECONNECTING: restore authentication and subscriptions with backoff and jitter.
- SYNCING: fetch authoritative state and event deltas, then re-enable commands.

If authentication expires, return to an authentication-required state rather than silently granting access.

## Ambiguous request outcome

A request can reach the backend even when the response is lost. Generate a stable idempotency key before submission and persist it locally for the pending operation. On reconnect, query the original operation by key. If it is pending, keep waiting; if completed, display the stored outcome. Never generate a new roll using the same user intent. Backend idempotency must bind the key to the authenticated actor and canonical payload; reusing a key with different parameters is a conflict.

## Sync and ordering

Events carry event_id, session_id and a monotonic session sequence. Resume from the last acknowledged sequence. On gaps, fetch a snapshot and reconcile. Duplicate events must not apply state changes twice. Server authorization is rechecked before replaying events.

## UX and accessibility

Display a persistent LOCAL / UNOFFICIAL marker on standalone dice rolls. In a mastered session, offline mode must not mimic a successful official roll. Support reduced motion, mute, and instant-result options.

## Privacy

Free dice history stays on the device by default. Clear local cache and sensitive campaign data on sign-out according to the retention policy. Never include secret GM data in player snapshots.

## Validation

Tests must cover dropped acknowledgements, duplicate commands, reconnect gaps, stale cached sheets, expired credentials, idempotency conflicts and the prohibition on promoting local rolls to official ones.
