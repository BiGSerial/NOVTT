<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

interface OfficialRollStore
{
    /**
     * Implementations MUST serialize concurrent commands for the same request
     * and commit the outcome and outbox event in the same transaction.
     *
     * The callback receives current authorization and MUST run only if the
     * idempotency key has no previously persisted outcome.
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
