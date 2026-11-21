# ADR 0007: User-interface localization

Status: Proposed (awaiting owner approval)

## Context

NOVTT must let third parties translate the application so a user can switch the interface language. Presets (see ADR 0008) describe game mechanics and are not part of the translation system. Today the PWA contains hard-coded Portuguese strings (for example in `apps/player-pwa/src/App.vue`), and no locale infrastructure exists in any client or in the contracts.

## Decision

1. **Only user-interface text is translatable.** Buttons, notices, validation messages, accessibility labels and system messages of the PWA, the master console and the Android app.
2. **Not translatable through catalogs:** preset content, character-sheet labels and any text the user authors (campaign data, notes, names). These are data, stored exactly as entered, and are never rewritten by a locale change.
3. **Stable message keys.** Clients reference namespaced keys (for example `roll.action.submit`); catalogs map keys to text. Plural and gender forms use ICU MessageFormat.
4. **Base and fallback.** `pt-BR` is the source locale and `en` is the fallback. A missing key falls back to the source locale and never renders blank text or a raw key in production builds.
5. **Server and contracts speak in codes, not prose.** HTTP errors, roll statuses and event payloads carry stable machine-readable codes (for example `roll.expired`). Each client translates them. Contracts MUST NOT embed localized sentences.
6. **Translations are untrusted input.** Catalog values are plain text or ICU only. Raw HTML in a catalog value is forbidden, and clients MUST NOT render translations with HTML-injecting APIs.
7. **Language preference is per user.** It defaults to the device language, can be overridden manually, and is persisted locally and, for signed-in users, on the account. In a mastered session each participant sees their own language.

## Alternatives considered

- **Server-side translation of API messages.** Rejected: couples contracts to prose, breaks offline use, and multiplies cache variants.
- **Per-platform catalogs maintained by hand (Android `strings.xml` and PWA JSON).** Rejected as the default because the two copies drift. Whether one canonical catalog generates the other is an open decision in SPEC-004.
- **Translating preset and sheet labels through catalogs.** Rejected by the owner: presets encode traditional mechanics, and labels are user-editable data.

## Consequences

- Positive: external contributors can add a language without touching code; contracts stay stable across languages; reduced XSS surface from translated text.
- Cost: every user-visible string needs a key, and CI must verify catalog consistency.
- Cost: the service worker must cache the active locale for offline use, without caching credentials (ADR 0005).
- The choice of PWA library (for example `vue-i18n`) and the Android generation strategy are implementation decisions to be confirmed in SPEC-004.
