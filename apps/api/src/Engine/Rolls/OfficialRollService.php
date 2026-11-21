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
     * The caller must authenticate and authorize actor/character/session
     * membership before calling. Store must enforce these checks again
     * against locked authoritative state.
     */
    public function execute(
        string $actorId,
        AuthorizedRoll $command,
        int $faces,
        int $threshold,
    ): OfficialRollOutcome {
        if ($actorId === '') {
            throw new RollPolicyViolation('Missing actor.');
        }

        // Bind the idempotency key to the canonical roll parameters.
        $fingerprint = hash('sha256', json_encode([
            'request_id' => $command->requestId,
            'chosen_dice_count' => $command->chosenDiceCount,
            'expected_revision' => $command->expectedRevision,
            'faces' => $faces,
            'threshold' => $threshold,
        ], JSON_THROW_ON_ERROR));

        return $this->store->executeAtomically(
            $actorId,
            $command,
            $fingerprint,
            function (RollAuthorization $authorization) use ($command, $faces, $threshold): OfficialRollOutcome {
                $this->policy->assertAllowed($authorization, $command);

                // The store must resolve and verify dice configuration from
                // the pinned preset; these values are not trusted client input.
                $result = $this->roller->roll(
                    new DicePool($command->chosenDiceCount, $faces, $threshold)
                );

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
