# ADR 0013: Platform shells — web, installed PWA and Android from one client

Status: ACCEPTED (owner directive 2026-11-24: "web screens behave like web, PWA like PWA, Android
like Android, including size and layout per device; take the decisions that do not slow
development down").

## Context
SPEC-001 plans a native Android client (Kotlin/Compose) and a PWA. Only the Vue PWA exists. The
owner wants each platform to behave natively now, without blocking feature work.

## Decision
1. One Vue code base serves three platform behaviours, detected at run time
   (`apps/player-pwa/src/live/platform.ts`) and exposed as `<html data-platform data-form>`:
   - `web`: a browser tab. Top navigation, page scroll, hover affordances, keyboard; on a phone
     the sections become a scrolling row under the brand.
   - `pwa`: installed (iOS / desktop standalone). App bar + bottom tabs on phones and tablets,
     safe-area insets, no rubber-band overscroll, dialogs as bottom sheets on phones.
   - `android`: installed on Android. Material conventions: 80 dp navigation bar with an active
     pill, 48 dp targets, pressed states, dialogs as bottom sheets; the system Back closes the
     open dialog before leaving the screen (history entry per dialog, `pushBack`).
   Form factor (`phone` < 640 px ≤ `tablet` < 1024 px ≤ `desktop`) is orthogonal.
2. Layout differences live in CSS keyed by those attributes and in the `AppShell` frame; feature
   components stay platform-agnostic. Table views (master console, player phone, tablet binder)
   keep their own full-screen layouts.
3. The native Android client of SPEC-001 remains the target for store distribution. Until it
   exists, the installed PWA on Android is the Android experience; parity rows in
   PLAYER-PARITY-MATRIX refer to it. A future Compose client consumes the same API and design
   tokens; nothing here couples features to the web shell.

## Consequences
- Features ship once; platform polish is CSS and shell work.
- Tests: `tests/platform.test.ts` (detection), e2e `my-space.mjs` (web desktop top nav, Android
  installed bottom nav, Back closes a sheet).
- Rejected: three separate front-ends now (cost, drift); Capacitor/TWA wrapper now (store
  packaging is a release task, not a behaviour task).
