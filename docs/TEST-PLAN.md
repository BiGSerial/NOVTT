# NOVTT test plan

## Dice trust boundary
- Local free rolls function without login or network.
- A local result cannot be submitted as an official roll.
- Server controls official randomness and validates rule context.

## Network interruption
- Disconnect before sending: no official roll created.
- Disconnect after send but before acknowledgement: retry status lookup using the original idempotency key.
- Duplicate request with identical key and payload: one official result.
- Duplicate key with different payload: reject as conflict.
- Out-of-order and duplicate realtime events: no duplicated state changes.
- Missing event sequence: snapshot reconciliation.
- Expired credentials: require reauthentication.
- Cached character sheets: read-only when offline.

## Visual behavior
- 3D dice settle on server-provided results in mastered sessions.
- Free dice clearly marked unofficial.
- Reduced motion and instant result produce the same underlying values.

## Sprint 001: sheets and review
- API tests run on PostgreSQL 18 (partial unique indexes, JSONB, locks, triggers are part of the contract).
- Authorization matrix and 404 non-disclosure for every resource; hidden fields absent from projections, errors and rebase previews.
- Database invariants enforced without application code (one active proposal, immutable versions and history, rejection reasons, frozen final decisions).
- Partial approval: invalid approved subset changes nothing; forced failure during finalization rolls back everything.
- Idempotent preset import, campaign creation, submission, finalization and grants; key reuse with another payload is 409.
- Real concurrency with a second database connection (row lock on finalize; unique index on submissions).
- Budget provenance, refunds to the paying budget, XP after approval without automatic spending.
- UI: keyboard operation of dot ratings, error summary linking, no rendering of hidden fields, no HTML injection, axe-core WCAG 2.1 A/AA rules in jsdom. Colour contrast and screen-reader behaviour still need manual checks in real browsers.
