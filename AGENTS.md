# NOVTT: instructions for local coding agents

This file defines repository-wide agent behavior. It does not authorize merging, deploying, or changing protected branches.

## Source of truth (in priority order)

1. Explicit instructions from the repository owner for the current task.
2. Accepted ADRs under `docs/adr/` and versioned contracts under `contracts/`.
3. Approved functional specifications under `docs/specs/`.
4. `docs/ARCHITECTURE.md`, `docs/TEST-PLAN.md` and `docs/PLAYER-PARITY-MATRIX.md`.
5. Existing implementation and tests.

If sources disagree, stop and report the contradiction rather than silently choosing a rule. A proposal is not an accepted decision.

## Non-negotiable rules

- NOVTT assists tabletop RPG sessions; it is not a video game or mandatory virtual tabletop.
- The Laravel backend is authoritative for official rolls, rules, permissions and persistent session state. Never accept client-selected dice faces, difficulty or preset settings for official rolls.
- `packages/engine` is framework-independent. Do not introduce Laravel, HTTP or database dependencies into its domain code.
- Use explicit interfaces, small modules, versioned contracts, testable dependencies and SOLID principles.
- Android and PWA player features must meet equivalent functional and visual acceptance criteria, except documented platform limitations.
- The master console supports multiple independently opened, synchronized windows. Separate ephemeral local workspace state from server-authoritative game events.
- Offline free rolls remain LOCAL / UNOFFICIAL and cannot become official events after reconnecting.
- Never copy protected third-party RPG rulebook text, art or lore into presets.
- Never include secrets in code, logs, websocket payloads, service worker caches or test fixtures.
- Do not add AI co-author attribution. Do not merge PRs, deploy or push to `main` without explicit owner approval.

## Engineering decision policy: security, performance and usability

For every implementation decision, the agent MUST seek the best justified balance of **security, performance, usability, maintainability and operational reliability**, with correctness as a prerequisite. Never optimize one dimension by silently compromising another.

1. **Security by design:** default-deny authorization, least privilege, server-side validation, secure secret handling, safe dependencies, explicit trust boundaries, minimal data exposure, auditability and protection against common web/mobile threats. Never bypass a security control for convenience or speed.
2. **Measured performance:** choose efficient algorithms, bounded resource consumption, appropriate indexing and query patterns, pagination, batching and caching only where safe. Avoid N+1 queries, unnecessary polling, redundant network calls and premature optimization. Define measurable performance budgets where feasible and benchmark consequential changes.
3. **Usability and accessibility:** favor predictable workflows, clear feedback and error recovery, responsive interfaces, keyboard and assistive-technology support, reduced motion and consistent Android/PWA behavior. Keep game-master interactions fast during live sessions.
4. **Reliability and data integrity:** prefer atomic operations, idempotency, concurrency guards, recoverable events, safe retries, observability and graceful degradation. A realtime outage must not silently corrupt official game state.
5. **Maintainability:** prefer the simplest design that meets proven requirements. Preserve module boundaries and contracts; avoid speculative abstractions, unnecessary dependencies and cleverness that obscures behavior.
6. **Evidence-based trade-offs:** before choosing between materially different approaches, compare security risks, latency/resource costs, user impact, complexity and operational burden. Document significant choices and rejected alternatives in an ADR.
7. **Escalation:** if the safer or faster approach changes an accepted requirement, contract, infrastructure cost or product scope, propose it with rationale and request owner approval. Do not unilaterally override an accepted decision.
8. **Verification:** tests MUST cover success, failure, authorization and edge cases. For performance-sensitive paths, include profiling or reproducible benchmarks where practical; for security-sensitive paths, include threat-focused tests and dependency checks. Never claim performance or security properties without evidence.

When two options satisfy the requirements, prefer the one with lower security risk and operational complexity, then the one with better measured performance and usability. Security, correctness and data integrity are not negotiable.

## Workflow for each task

1. Read the relevant ADRs, specifications, contracts and tests before editing.
2. State assumptions and unresolved decisions. Ask the owner when a choice changes scope or an accepted architecture.
3. Work on a branch named per `docs/development/branching.md` (`feature/`, `fix/`, `chore/`, `docs/`, `refactor/`, `test/`, `ci/`, `perf/`, `release/`, `hotfix/`), created from `develop` (or `main` for `hotfix/`). Implement the smallest coherent change; use small topic-focused commits.
4. Add or update tests for behavior, validation, authorization and failure modes.
5. Run relevant tests and static checks. Report actual commands and outcomes; never claim unexecuted checks passed.
6. Update the applicable specification, contract and parity matrix if observable behavior changes.
7. Leave PRs as Draft until the owner explicitly requests otherwise.

## Relevant commands

- Engine: `cd packages/engine && composer install && composer test`
- Player PWA: `cd apps/player-pwa && npm install && npm test && npx vue-tsc --noEmit && npm run build`

These are development commands, not proof of test execution. Consult CI and package scripts for updates.

## Specification index

See `docs/specs/README.md`. Use normative MUST / MUST NOT / SHOULD / MAY wording as defined there.
