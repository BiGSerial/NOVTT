# SPEC-003: Official rolls and session event integrity

Status: ACCEPTED (requirements); implementation status: PARTIAL

## Objective

Official rolls MUST be authorized and resolved by the server according to the campaign-pinned ruleset, never by user-controlled dice parameters.

## Functional requirements

- OR-01: A GM request specifies the applicable test context. The server resolves allowed dice bounds, faces, threshold, success weighting and attempt budget from the pinned preset and approved campaign overrides.
- OR-02: The player MAY choose a dice count only when the preset allows it, and only inside the authorized range. Fixed pools MUST reject alternative counts.
- OR-03: The server MUST validate actor, campaign/session membership, character, request status, expiry, revision, allowed count and remaining attempts atomically.
- OR-04: Expiry MUST be evaluated against an injected clock, not solely against a stored EXPIRED status. An authorization expires at its expiry instant.
- OR-05: Official randomness MUST be generated server-side using a cryptographically secure source. The client MUST NOT specify faces, threshold or successes-per-die.
- OR-06: The same actor_id and idempotency key with the same canonical command MUST return the previously committed result without consuming another attempt. The same scoped key with a different payload MUST fail.
- OR-07: Successful official rolls MUST atomically persist the outcome, consumed attempt, revision and outbox event. No event is broadcast before commit.
- OR-08: Authoritative session events MUST carry request_id, revision, successes and a strictly increasing per-session sequence. Consumers MUST deduplicate and reconcile sequence gaps.
- OR-09: GM overrides and rerolls MUST be explicitly authorized and audited; a universal single-attempt rule MUST NOT be imposed.
- OR-10: Standalone offline free dice MUST remain unofficial and MUST NOT be promoted to official session results.

## Acceptance scenarios

1. Attempting a dice count above the preset cap fails without consuming randomness.
2. A stale expected revision, expired request, exhausted attempts or unauthorized actor fails.
3. Retrying a committed command with the same actor/key/payload returns the exact original outcome.
4. Reusing the same actor/key with a different payload is rejected; keys from different actors are independently scoped.
5. A preset with successesPerDie > 1 produces correctly weighted successes.
6. Two concurrent submissions cannot consume the same remaining attempt.
7. After a websocket interruption, a client fetches missing session sequences without duplicate application.

## Contract and implementation references

- `contracts/http/authorized-roll-command.schema.json`
- `contracts/http/roll-status.schema.json`
- `contracts/events/dice-roll-resolved.schema.json`
- `packages/engine/src/Engine/Rolls/`

The current `OfficialRollStore` is an interface. A real transactional Laravel/PostgreSQL implementation and outbox/replay service are still required. PHPUnit tests with an in-memory fake do not prove concurrency or durable persistence.
