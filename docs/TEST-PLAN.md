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
