# Android and PWA parity matrix

Status legend: PLANNED, IMPLEMENTED, VERIFIED.

| Player capability | Android | PWA | Acceptance requirement |
| --- | --- | --- | --- |
| Authentication and session membership | PLANNED | PLANNED | Same permissions and error semantics |
| Character sheet viewing | PLANNED | IMPLEMENTED (Sprint 001, online only) | Same fields and read-only offline cache |
| Character draft, proposal and master review feedback | PLANNED | IMPLEMENTED (Sprint 001) | Same field access, validation codes and accessibility |
| Inventory | PLANNED | PLANNED | Same authorized actions |
| Master-requested rolls | PLANNED | PLANNED | Same dice limits, attempts and official results |
| Free dice without login | PLANNED | PLANNED | Offline, secure randomness, unofficial provenance |
| Dice animations | PLANNED | IMPLEMENTED for free dice only (3D d6–d20, ≤ 6 dice; official rolls pending; docs/development/dice-3d.md) | Same outcomes, reduced-motion and instant-result modes |
| Reconnection and event replay | PLANNED | PLANNED | Idempotent recovery and gap reconciliation |
| Accessibility | PLANNED | PLANNED | Screen reader, touch targets, contrast and motion settings |
| Installable experience | PLANNED | PLANNED | Native installation / PWA Add to Home Screen |

No capability may be marked VERIFIED until both platforms pass its acceptance tests on supported devices.

Sprint 001 delivered player features on the PWA only (Android out of scope). Under ADR 0005 they are not complete until Android passes the same acceptance tests; see `docs/reports/SPRINT-001-CONFLICTS.md` (C1).
