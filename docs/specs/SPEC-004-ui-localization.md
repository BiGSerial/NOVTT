# SPEC-004: User-interface localization

Status: PROPOSED; implementation status: NOT STARTED

Authority: ADR 0007 (Proposed). Nothing here is an accepted requirement until the owner approves the ADR.

## Objective

Allow the interface to be translated by third parties and switched by the user, on every client, without affecting contracts, presets or user-authored data.

## Scope

In scope: PWA player, master console and Android app interface text, accessibility labels, client-side rendering of server error and status codes, number and date formatting.

Out of scope: preset content, character-sheet labels and user-authored text (see SPEC-005), translation of official dice results (they are numeric), automatic machine translation.

## Functional requirements

- LOC-01: Every user-visible string MUST be referenced by a stable, namespaced message key. No literal user-facing text in components, except pre-localization developer logs.
- LOC-02: Catalogs MUST use ICU MessageFormat for plurals and parameters. Parameter names are part of the key's contract.
- LOC-03: `pt-BR` is the source locale and `en` the fallback. A missing key MUST fall back to the source locale, then to the fallback, and MUST NOT render a blank or a raw key in production. Development and CI builds MUST report missing keys.
- LOC-04: The active locale is chosen in this order: explicit user choice, saved account preference (when signed in), device language, `pt-BR`. The choice persists across sessions and devices of a signed-in user, and locally for anonymous use.
- LOC-05: In a mastered session each participant's interface uses their own locale. Locale MUST NOT alter game state, rolls or event payloads.
- LOC-06: Server errors, roll statuses and events MUST expose stable codes. Clients map codes to message keys. Contracts MUST NOT include localized sentences; adding a code is a backward-compatible change that requires a contract note.
- LOC-07: Catalog values MUST be plain text or ICU. Raw HTML, script or markup in a value is invalid, and clients MUST NOT render translations through HTML-injecting APIs. Placeholders in a translation MUST match the source message.
- LOC-08: Dates, times and numbers MUST use locale-aware formatting. The document language attribute MUST follow the active locale, and layout MUST tolerate right-to-left locales once one is added.
- LOC-09: The PWA MUST load locale data on demand and the service worker MUST be able to serve the active locale offline. Locale files MUST NOT contain secrets, and the service worker MUST NOT cache credentials (ADR 0005).
- LOC-10: Switching language MUST take effect without losing local workspace state, and MUST NOT interrupt an in-flight official roll.
- LOC-11: Accessibility labels (screen reader text, button names, notices) are translatable on the same terms as visible text.
- LOC-12: Android and PWA MUST expose the same set of supported locales and fall back identically.

## Quality gates

- CI MUST fail on: keys present in the source locale but missing in a supported locale (warning for community locales, failure for `pt-BR` and `en`), keys that exist only in a translation, placeholder mismatches and markup inside values.
- A pseudo-locale (expanded and accented text) SHOULD be used in tests to expose truncation and hard-coded strings.
- Contributor documentation (`docs/i18n.md`) MUST explain adding a locale, key naming and review rules before the first community locale is accepted.

## Acceptance scenarios

1. A user with a device language that has no catalog sees `pt-BR` and no raw keys.
2. A key missing in a community locale renders the `pt-BR` text, and CI reports it.
3. Changing language in the PWA while a free roll is displayed keeps the result and changes only the surrounding text.
4. A server `roll.expired` code renders the localized message in two different locales with the same payload.
5. A catalog value containing markup is rejected by the CI check.
6. Offline, a previously used locale loads from the service worker cache.
7. Two participants of one session use different locales and see identical roll outcomes and sequence numbers.

## Open decisions for the owner

1. Canonical catalog format and whether the Android resources are generated from it.
2. PWA library (for example `vue-i18n`) and bundling strategy for on-demand locales.
3. Initial supported locales beyond `pt-BR` and `en`, and whether any right-to-left locale is in scope for the first release.
4. Translation workflow and review ownership for community contributions.
5. Where the account-level language preference is stored once the Laravel application exists.

## References

- `apps/player-pwa/src/App.vue` (current hard-coded strings)
- `apps/player-pwa/public/sw.js`
- ADR 0005 (PWA parity), SPEC-001 (player clients)
