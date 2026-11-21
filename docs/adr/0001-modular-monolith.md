# ADR 0001: Modular monolith with contract-first boundaries

Status: Accepted

## Context

NOVTT needs a universal mechanics engine and two distinct clients, without early distributed-system complexity.

## Decision

Use a modular Laravel backend with domain/application/infrastructure/presentation boundaries. Maintain a native Kotlin Android client and a web master interface. Define HTTP contracts using OpenAPI, realtime events using AsyncAPI, and presets using JSON Schema.

## Consequences

Fast initial delivery and simpler transactions; module boundaries must be enforced by tests and review. Extract services only if measured scaling or organizational needs justify it.
