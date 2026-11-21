<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use Novtt\Engine\Dice\RollResult;

final readonly class OfficialRollOutcome
{
    public function __construct(
        public string $requestId,
        public string $idempotencyKey,
        public RollResult $result,
        public int $revision,
        public int $attemptsConsumed,
    ) {
    }
}
