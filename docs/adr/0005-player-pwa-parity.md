# ADR 0005: Player PWA with Android parity

Status: Accepted

## Product decision

NOVTT provides a native Android player app (Kotlin + Jetpack Compose) and an installable player PWA for iPhone/iPad and other browsers. The PWA must reproduce the Android player's screens, navigation, feature set, terminology, rules behavior, accessibility and visual design as closely as platform capabilities allow. The game-master web console remains a separate interface.

## Technology

The PWA uses Vue 3, TypeScript and Vite, with current stable supported releases at implementation time. It shares the same versioned HTTP/event contracts, preset semantics, design tokens, localization strings and test fixtures as Android. Native UI and web UI implementations are separate; pixel-identical OS behavior is not promised.

## Offline rules

- Free dice work offline in both player clients, with secure local randomness (Web Crypto getRandomValues on PWA, Android secure random source on native).
- Offline free rolls are labeled LOCAL / UNOFFICIAL and cannot be promoted to official mastered-session events.
- Cached campaign sheets may be viewed offline, never edited or rolled officially while disconnected.
- Reconnection, idempotency, event replay, auth expiration and reduced-motion behavior follow ADR 0003.
- The PWA service worker must not cache credentials or sensitive API responses indiscriminately. Sensitive local data needs explicit retention and logout cleanup policies.

## Parity gate

A player feature is not complete until implemented and tested on both Android and PWA. Maintain a shared acceptance matrix covering UI, accessibility, API behavior, dice grammar, offline operation, reconnection and animation. Test iOS Safari standalone and browser modes; document platform-specific limitations.

## Scope

The PWA is a player application, not a second master console or a VTT.
