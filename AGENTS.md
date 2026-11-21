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

## Workflow for each task

1. Read the relevant ADRs, specifications, contracts and tests before editing.
2. State assumptions and unresolved decisions. Ask the owner when a choice changes scope or an accepted architecture.
3. Implement the smallest coherent change; use small topic-focused commits.
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
