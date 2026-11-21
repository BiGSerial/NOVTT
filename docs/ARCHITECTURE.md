# NOVTT Architecture

## Product boundary

NOVTT is not a VTT. The backend is authoritative for rules, permissions and persistent session state. Android is the native player client; the web application is the game-master console.

## Modules

- Identity: accounts, authentication and campaign membership.
- Campaigns: ownership, configuration and selected preset revision.
- Characters: character sheet instances, revisions and approval.
- Presets: immutable published definitions and campaign-local overrides.
- Dice: deterministic evaluation of dice expressions using injected randomness.
- Rules: expression validation and evaluation without executing user-provided code.
- Sessions: session lifecycle and commands.
- Realtime: authorized projection and delivery of domain events.
- Audit: immutable activity records.

## Dependency rules

1. Domain code must not depend on Laravel, Eloquent, HTTP or WebSockets.
2. Application use cases depend on domain interfaces.
3. Infrastructure implements ports; presentation translates transport DTOs.
4. No module reads or writes another module's private persistence tables directly.
5. All client-visible API/event payloads are versioned contracts.
6. A preset edition, preset package version and campaign revision are distinct identifiers.
7. A campaign pins a preset version. Updating presets never silently mutates campaign rules.
8. The server validates every command and broadcasts only information each participant may see.

## First vertical slice

A master creates a session; a player joins from Android; the player requests a roll; the backend validates and executes it; the master receives an authorized realtime event. Include idempotency and reconnection handling before production release.

## Security

Never run arbitrary PHP, JavaScript or shell from preset definitions. Enforce per-campaign authorization, strict schemas, rate limits, and audit trails. Use secure random number generation for official dice rolls.
