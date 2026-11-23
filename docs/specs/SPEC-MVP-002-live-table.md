# SPEC-MVP-002: Mesa ao vivo (prova de conceito funcional)

Status: ACCEPTED (owner, 2026-11-22: "o protótipo está bom; quero ver um MVP funcional com todas as
funcionalidades e interatividades"); implementation status: IMPLEMENTED (API + web clients, local Docker); NOT VERIFIED on real devices or Android
Authority: SPEC-MVP-001, SPEC-002, SPEC-003, ADR 0004, ADR 0006, ADR 0011.
Prototype (approved look and flow): https://claude.ai/artifact/98nL3Haurj6Jjk8fWhMtcu

## Owner decisions recorded here (2026-11-22)

- D1 Official rolls: **the master requests; the player suggests.** Only a roll request created by
  the master (or a master's own roll for an NPC) is official (ADR 0004 unchanged). A combination the
  player composes during a session becomes a *suggestion* the master can turn into a request with
  one tap. Anything else is a free, LOCAL / UNOFFICIAL roll.
- D2 Visibility: player rolls are **visible to the whole table** by default; the master MAY mark a
  request *secret* (result delivered only to the master and that player). Master rolls for NPCs
  are **secret by default** and MAY be revealed later.
- D3 The master chooses the dice of a request (owner: "o mestre tem de dizer qual dado ele deve
  utilizar"): die type, count rule (pool from the combination, or fixed), reading (sum, highest,
  successes), target and attempts. This is an authorized GM command validated by the server within
  engine limits. The player never sends faces, threshold, reading or target. Preset-defined roll
  formulas (rules contract v2) remain a later, separate decision.
- D4 The dice everyone sees are NOVTT's own 3D dice rolling over the page (PR #16). Every client
  replays the same seeded throw and the dice settle on the server's faces; the result card then
  comes to the front.

## Scope of the proof of concept

Infrastructure: Laravel 13 API, PostgreSQL 18, Redis (queues, cache, Reverb scaling), Laravel Reverb
(WebSocket, Pusher protocol), a queue worker and an outbox relay, all in Docker Compose.

### LT-01 Accounts
Register, sign in and sign out with email and password through the first-party session (ADR 0009),
rate limited. Account recovery stays out of the PoC (needs mail delivery).

### LT-02 Sessions
The master starts, pauses, resumes and ends a campaign session. At most one open (active or paused)
session per campaign. Members see the session state. Ending preserves history.

### LT-03 Session event stream
Every authoritative change in a session is appended to `session_events` with a strictly increasing
per-session `seq` and an **audience**: `table` (all members), `master` or `user:<id>`. Events are
written in the transaction of the change and broadcast only after commit (relay with retries).
Channels: `private-campaign.<id>` (table audience), `private-campaign.<id>.master`,
`private-user.<id>`. Channel authorization uses campaign membership and role. A client recovers
with `GET /sessions/{id}/events?after=<seq>`, which returns only events whose audience includes the
actor; consumers deduplicate by `seq` and refetch on gaps.

### LT-04 Roll requests and official rolls
- The master creates a request for a campaign character: label, attribute + skill (any number of
  sheet paths), situational modifier, dice (D3), attempts (1–5), optional secret, optional expiry.
  The server computes the pool / bonus from the character's **official sheet**, never from the
  client.
- The player (owner of the character) executes it; the server rolls with a CSPRNG, enforces status,
  revision, attempts, count range and idempotency atomically (SPEC-003), and stores faces, reading,
  total, successes and outcome (success / failure) plus a server-chosen animation seed.
- The master can cancel an open request and grant one extra attempt (audited).
- NPC rolls: the master rolls for a campaign NPC with the same dice options; secret by default; the
  master can reveal it to the table.
- Player suggestion: the player sends attribute + skill (+ optional note); the master accepts (it
  becomes a request with the master's dice) or dismisses it.

### LT-05 Live character state
Per campaign character and NPC: resource tracks (label, current, max) and conditions (short
labels). Only the master changes them during the session (players read their own; NPC state is
master-only). Changes carry an expected revision; conflicts return 409.

### LT-06 Master orientation (live table)
- Scene compass: current scene title, objective, threat clocks (label, segments, filled), next clue
  hint and spotlight counts (rolls and actions per character). Master-only.
- Initiative: ordered list of characters/NPCs with the current turn. Visible to the table (NPC
  names only; never NPC numbers).
- Notes: private master notes with tags and pin, autosave with revision conflict detection
  (SPEC-002 MW-08).
- Whispers: the master sends a private message to **one** player; the player can answer the master.
  Never delivered to other players or to the table channel.
- Clues: prepared clues per scene; reveal to all (table event) or whisper to one player.
- NPC reveal: show an NPC's name and portrait text to the table (no numbers, no secrets).

### LT-07 Session prep (Roteiro) and world (Atlas)
- Roteiro per session: strong start, scenes (with clues), NPCs to feature, clocks and what each
  player character wants. Starting a session from a roteiro seeds the compass, clues and clocks.
- Atlas per campaign: entries of type place, person, faction, secret or thread, with links between
  entries and a dated timeline of how each entry evolved (per session). Master-only; an entry MAY be
  marked "known to players" to show its public summary.

### LT-08 Paired tablet (ADR 0011)
One-time pairing code (6 digits, 2 minutes, single use, rate limited) shown on the new device and
confirmed by the master on an authenticated device. The paired device receives its own session
bound to the master and listed / revocable by the master; it cannot create pairing codes or manage
devices. Selection follow ("seguir a mesa") is relayed through `private-workspace.<user-id>` client
events, never persisted and never sent to players.

### LT-09 Clients (one Vue codebase, ADR 0011)
- Desktop/tablet master table, tablet binder (sheets of PCs and NPCs), player phone view, Roteiro,
  Atlas; themes fantasia / horror / sci-fi as data with contrast checks.
- Live / reconnecting / stale indicator; reconnection reconciles events by `seq`.
- Official results animate with the 3D dice over the page (D4) and honour reduced motion.

## Acceptance (end-to-end, demo data)

1. Master and two players sign in (three browsers); master starts a session; everyone sees it live.
2. Master requests "Agilidade + Furtividade, d10 pool, sucessos 6+, precisa 2"; the player's phone
   shows the locked request; the player rolls; all three see the same dice settle on the same faces
   and the same outcome; the feed survives a reload.
3. A secret request result reaches only the master and that player.
4. A secret NPC roll reaches only the master; after "revelar" the table sees it.
5. A whisper reaches only its recipient; another player's event stream never contains it.
6. A player cannot create requests, change resources, read notes, the compass, NPC numbers, Atlas
   secrets or another player's whispers (HTTP and channel authorization tests).
7. Retrying a roll with the same idempotency key returns the original result without consuming an
   attempt; concurrent submissions cannot exceed attempts.
8. Disconnecting a client and reconnecting fetches the missed events once, in order.
9. A tablet pairs with a code confirmed by the master, follows the master's selection, and stops
   working after the master revokes it; expired, reused and guessed codes fail.

## Out of scope (PoC)

Account recovery, Android native client, maps, inventory transfer, AI, production deployment and
TLS termination (local Docker only).
