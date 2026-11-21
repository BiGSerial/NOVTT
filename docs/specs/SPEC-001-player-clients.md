# SPEC-001: Android and PWA player clients

Status: ACCEPTED (requirements); implementation status: PARTIAL

## Objective

NOVTT MUST provide a native Android player client (Kotlin/Jetpack Compose) and an installable PWA (Vue/TypeScript) for iPhone/iPad and supported browsers. The two player clients MUST implement the same approved player journeys, navigation concepts, terminology, rules semantics and design tokens, within documented OS/browser constraints. The web master console is a different product surface.

## Functional requirements

- PC-01: Players MUST be able to view authorized character sheets, inventory and session state when online.
- PC-02: Editing a character sheet or inventory MUST follow server-enforced GM permissions; client-side controls alone are insufficient.
- PC-03: Both clients MUST support standalone free dice without login or connectivity once their offline runtime is available.
- PC-04: Free dice MUST use cryptographically secure platform randomness and MUST be visibly labeled LOCAL / UNOFFICIAL.
- PC-05: Free dice history MUST remain separate from official session rolls. Local results MUST NOT be uploaded as official outcomes.
- PC-06: Official rolls MUST use server-issued authorizations, authoritative server results, and stable idempotency keys. No official action is available while disconnected.
- PC-07: Dice animation is presentation only. It MUST reflect the result returned by the server for official rolls. Reduced-motion and instant-result modes MUST preserve the same outcome.
- PC-08: Cached character information MAY be displayed offline as read-only, with a visible stale/offline indicator. Sensitive storage retention and logout cleanup MUST be explicitly implemented.
- PC-09: Reconnection MUST reconcile sequence gaps from the server and MUST NOT create duplicate official rolls.
- PC-10: PWA caching MUST be limited to public shell and safe static assets. Authenticated responses, tokens and private game data MUST NOT be indiscriminately cached by the service worker.
- PC-11: Each delivered feature MUST have Android and PWA acceptance coverage. Platform limitations MUST be documented, not silently ignored.

## Acceptance scenarios

1. Without network, a previously installed PWA can open free dice and generate an unofficial result without credentials.
2. A local free roll cannot be submitted to an official session.
3. An official roll received after reconnection displays the server result without rolling again.
4. Both clients enforce the same authorized dice range and attempts, with the backend making the final decision.
5. iOS Safari browser and standalone modes are manually checked for installation, safe-area layout and offline behavior.
6. A player with revoked access cannot read protected character data from API or websocket subscriptions.

## Out of scope

Pixel-identical operating-system chrome; a mandatory battlemap; a full video-game simulation. SVG icons and service worker scaffolding alone do not establish verified iOS installability.

## Traceability

ADR 0002, 0003, 0005; `docs/PLAYER-PARITY-MATRIX.md`; `apps/player-pwa/`; future Android client.
