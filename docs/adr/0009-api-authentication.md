# ADR 0009: API authentication

Status: Accepted (technical decision delegated by the owner for Sprint 001, 2026-11-22)

## Context

Sprint 001 needs an authenticated actor for every policy and endpoint. The MVP requires secure authentication; the PWA and the master console are first-party web clients, and a native Android client is planned.

## Decision

1. Use **Laravel Sanctum** (first-party, maintained with the framework).
2. First-party browser clients (PWA, master console) use Sanctum **stateful SPA authentication**: session cookie (`HttpOnly`, `Secure` outside local, `SameSite=Lax`), CSRF protection, CORS restricted to configured origins. No token is stored in `localStorage` or in the service worker cache (ADR 0005).
3. The future Android client uses Sanctum **personal access tokens** with explicit abilities and expiry. Not implemented in Sprint 001.
4. All domain endpoints live under `/api/v1` with the `auth:sanctum` guard. Authorization is default-deny through policies; authentication alone never grants access to a campaign.
5. Account flows (registration, login screen, recovery, e-mail verification) are **out of Sprint 001**; tests authenticate with framework helpers. They require their own specification before implementation.
6. Primary keys of domain records exposed to clients are UUIDv7, so identifiers do not reveal counts or ordering across tenants.

## Consequences

- One mechanism for web and mobile, without a custom token format.
- Cookie auth requires correct `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` and CORS configuration per environment.
- Rate limiting is applied per authenticated user and per IP on mutating endpoints.
