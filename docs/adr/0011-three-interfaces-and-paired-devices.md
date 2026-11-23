# ADR 0011: Three interfaces, one web codebase, campaign themes and paired master devices

Status: Accepted (owner decisions, 2026-11-22)
Amends: ADR 0005 (player clients), ADR 0006 (multi-window master console)

## Context

The owner wants NOVTT to "transmit the magic of an RPG" (animation, interactivity) without losing
intuitiveness, a game-master flow built for narrative fluidity rather than complexity, and room to
grow worlds and stories across sessions. Tablets must work for players and as an auxiliary screen
for the master. A navigable prototype (desktop master table, paired tablet, player phone, world
Atlas, themes) was produced for review before implementation.

## Decision

1. **Three interfaces.**
   - **WEB**: desktop and tablet, for masters and players. Includes the master console.
   - **PWA**: the player experience on phones, installable.
   - **Android native**: Kotlin + Jetpack Compose (ADR 0005), same themes, gestures and acceptance
     criteria as the PWA.
2. **One web codebase.** WEB and PWA are one Vue 3 + TypeScript application with shared design
   tokens, components, animation and dice code, and responsive shells (phone, tablet, desktop).
   The master console (`apps/master-web`, framework-free TypeScript today) migrates into it. The
   build may still emit separate entry points so the player PWA stays small.
3. **Identity: NOVTT base + campaign themes.** One structure, navigation and gesture vocabulary;
   a campaign (or its preset) picks a theme (initially fantasy, horror, sci-fi) as **data only**:
   colors, display font, backdrop texture, particles and sound. Themes must meet contrast checks
   (text 4.5:1, large text and controls 3:1) and honour reduced motion.
4. **Motion with purpose.** Every animation confirms an action (dice, scene beat, reveal) and lasts
   about 1 s or less; reduced motion shows the outcome directly. Dice roll over the page (PR #16).
5. **Paired master devices (amends ADR 0006).** A tablet (or any second device) joins the master's
   workspace by pairing through the server:
   - The new device shows a one-time code / QR (≈ 2 min, single use, rate limited). The master
     confirms it on an already authenticated device; codes never grant access by themselves.
   - The paired device gets its own revocable session bound to that master and workspace, listed
     and revocable from the console. It never receives the master's credentials.
   - Ephemeral workspace state (selection, follow/pin, panel focus) is relayed through a private,
     authorized channel for that workspace only; it is never sent to players and is never treated
     as a game action. Same-device windows keep using BroadcastChannel (ADR 0006).
   - Authoritative state (rolls, sheets, initiative, notes) still comes only from the backend and
     is reconciled after reconnection.
6. **Narrative-first master flow (direction for specs).** The live table is organised around the
   current scene and its beats, the cast, one-tap roll requests and reveals, and story threads.
   Worlds grow in an **Atlas** of places, people, factions, secrets and threads whose evolution is
   recorded per session. Suggestions shown to the master are derived from the master's own data
   and never applied automatically.

## Consequences

- New specs are needed before implementation: master live table, Atlas (world and threads), player
  sheet navigation, theme tokens contract, device pairing (threat model included).
- Player-initiated official rolls and roll formulas in presets remain open decisions (see
  `docs/development/dice-3d.md`).
- Migrating the master console to Vue replaces `apps/master-web/resources/js/*`; its tests move with
  it. Android follows the same specs with native UI.
- Pairing adds server endpoints, a websocket channel and audit events; it must ship with
  authorization and abuse tests (expired, reused and brute-forced codes; revoked devices; players
  attempting to join).

## Rejected alternatives

- Separate web apps for PWA and WEB: duplicated UI and drifting visuals.
- A single fixed dark-fantasy look: poor fit for horror and sci-fi campaigns.
- Tablet showing only authoritative state without pairing: simpler, but the master could not drive
  the second screen from the main one.
