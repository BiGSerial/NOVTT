<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use Novtt\Engine\Dice\DicePool;
use Novtt\Engine\Dice\DicePoolRoller;

final readonly class OfficialRollService
{
    public function __construct(
        private OfficialRollStore $store,
        private RollPolicy $policy,
        private DicePoolRoller $roller,
    ) {
    }

    /**
     * Caller authenticates the actor. Store MUST reauthorize actor/character/session
     * and resolve pinned preset values from locked authoritative state.
     */
    public function execute(string $actorId, AuthorizedRoll $command): OfficialRollOutcome
    {
        if ($actorId === '') {
            throw new RollPolicyViolation('Missing actor.');
        }

        // The key is scoped to the actor by the store. Dice configuration
        // is resolved from the pinned preset, never supplied by the client.
        $fingerprint = hash('sha256', json_encode([
            'request_id' => $command->requestId,
            'chosen_dice_count' => $command->chosenDiceCount,
            'expected_revision' => $command->expectedRevision,
        ], JSON_THROW_ON_ERROR));

        return $this->store->executeAtomically(
            $actorId,
            $command,
            $fingerprint,
            function (RollAuthorization $authorization) use ($command): OfficialRollOutcome {
                $this->policy->assertAllowed($authorization, $command);
                $result = $this->roller->roll(new DicePool(
                    $command->chosenDiceCount,
                    $authorization->faces,
                    $authorization->threshold,
                    $authorization->successesPerDie,
                ));

                return new OfficialRollOutcome(
                    $command->requestId,
                    $command->idempotencyKey,
                    $result,
                    $authorization->revision + 1,
                    $authorization->attemptsConsumed + 1,
                );
            },
        );
    }
}
