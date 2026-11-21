<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

interface OfficialRollStore
{
    /**
     * Implementations MUST serialize concurrent commands for the same request,
     * authorize the actor against locked session/character state, resolve the
     * pinned preset's dice parameters, and atomically commit the outcome,
     * consumed attempt and outbox event.
     *
     * Idempotency keys MUST be scoped by actor_id. Repeated requests with the
     * same actor_id/key and canonical payload return the persisted outcome;
     * a different payload with the same scoped key MUST fail as a conflict.
     * The callback runs only if no outcome is persisted for the scoped key.
     *
     * @param callable(RollAuthorization):OfficialRollOutcome $execute
     */
    public function executeAtomically(
        string $actorId,
        AuthorizedRoll $command,
        string $payloadFingerprint,
        callable $execute,
    ): OfficialRollOutcome;
}
