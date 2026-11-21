# ADR 0002: Secure runtime and animated dice

Status: Accepted

## Runtime policy

- Use the latest stable Laravel major (currently Laravel 13), with current security patches.
- Target PHP 8.5 with security-patched releases; never deploy a vulnerable unpatched runtime.
- Prefer latest stable supported Kotlin, Jetpack Compose, and dependencies; validate compatibility before upgrades.
- Use Composer audit, dependency scanning, CI tests and security advisory monitoring. Review CVEs and severity, not version numbers alone.

## Dice animation policy

- The backend generates and records authoritative random results.
- Realtime events deliver validated outcomes to authorized clients.
- Android may animate 3D dice and must settle on the authoritative face values; visuals cannot change the result.
- Keep rendering behind a client-side DiceAnimator interface and support reduced-motion / instant-result modes.
- The server never depends on a graphics engine; the animation is a client concern.
- Do not infer fairness from physics animation. Audit and idempotency remain server responsibilities.

## Implementation status

This ADR is architectural policy; no Android 3D renderer is implemented yet.
