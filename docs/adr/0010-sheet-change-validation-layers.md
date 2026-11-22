# ADR 0010: Sheet change validation layers and persistence

Status: Accepted (technical decision delegated by the owner for Sprint 001, 2026-11-22)

## Context

SPEC-SPRINT-001 requires validating the whole approved set of operations "in the engine" before an atomic apply, with three validation stages (JSON syntax, structural contracts, real engine compatibility). `packages/engine` must stay free of Laravel, HTTP and database dependencies and currently depends only on PHP.

## Decision

1. **Engine owns the pipeline.** `packages/engine` defines typed sheet operations (`set_field`, `unset_field`, `add_item`, `set_item_field`, `remove_item`, `reorder_items`), RFC 6901 JSON Pointer handling, field access levels, the operation applier, mechanical rules (limits, budgets, costs, structural dependencies) and the `novtt.validation-result/1` model.
2. **Structural validation is a port.** The engine declares `StructuralValidator`; the API implements it with a JSON Schema Draft 2020-12 library. The engine stays dependency-free and is tested with fakes; the adapter is tested in the API.
3. **Order of validation:** syntax (API, at the transport boundary) → structure (port, against the pinned version's `sheet.schema.json`) → engine rules (limits, budgets, dependencies, cross-section constraints). The full candidate sheet is validated, never operations in isolation.
4. **Persistence is PostgreSQL.** JSONB for canonical and historical snapshots, partial unique indexes for "one active proposal per campaign character", row locks (`SELECT … FOR UPDATE`) plus monotonic revision checks for concurrency, and a transactional outbox. API tests run against PostgreSQL, not SQLite, because these guarantees are database features.
5. **Errors** follow RFC 9457 (successor of RFC 7807) `application/problem+json` with stable codes (ADR 0007 direction): 409 for revision/state conflicts, 422 for validation failures, 404 instead of 403 when revealing existence would leak private data.

## Consequences

- One place (engine) decides mechanical validity for official and personal presets.
- The API adds one dependency for JSON Schema validation, isolated behind the port.
- Local API tests need the PostgreSQL test service (`docker compose` or CI service container).
