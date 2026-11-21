# ADR 0004: Master-requested tests, player-selected dice, and attempt limits

Status: Accepted

## Core rule

During a mastered session, the GM initiates or authorizes a test request, selecting the relevant attributes, skills, difficulty and contextual modifiers. The authoritative rules engine resolves the permitted dice range and attempt budget from the campaign's pinned preset edition and overrides.

The player chooses the dice quantity within the authorized range. Choosing fewer dice is allowed only if the selected ruleset permits it. The player cannot exceed the calculated cap or exceed the number of authorized attempts. An attempt means an official submission, not the number of dice inside one roll.

The server, never the Android UI, enforces both limits atomically. The GM may explicitly grant a reroll or override within campaign permissions, producing an audited event. Some systems use fixed pools, exploding dice, advantage, rerolling individual dice, or other mechanics: preset policies define these behaviors; do not force a universal one-roll-per-test rule.

## Suggested aggregate

RollRequest: id, campaign_id, session_id, character_id, requested_by, attributes[], skills[], modifiers[], rule_reference, min_dice, max_dice, attempts_allowed, attempts_consumed, status, expires_at, revision.

Statuses: OPEN, EXHAUSTED, CANCELLED, EXPIRED, CLOSED.

Each roll command references the roll request, expected revision, chosen dice count and stable idempotency key. Enforce authorization, ownership, request status, dice range, remaining attempts and session rules in one transaction with a concurrency guard. Persist the result and audit event before broadcasting. Duplicate retries return the original result without consuming a second attempt.

## Example

Destreza 3 + Furtividade 2 -> nominal pool of five d10, before modifiers. If the ruleset permits choosing fewer dice, the player may select between one and five. The GM's request may allow one official attempt. This is an example, not a universal V20 rule.

## Free dice

Standalone local dice are not subject to GM requests or campaign attempt budgets and are always labeled unofficial.
