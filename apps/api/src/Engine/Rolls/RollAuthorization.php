<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use InvalidArgumentException;

final readonly class RollAuthorization
{
    public function __construct(
        public string $requestId,
        public int $minDice,
        public int $maxDice,
        public int $attemptsAllowed,
        public int $attemptsConsumed,
        public int $revision,
        public bool $allowsPlayerChoice,
        public string $status = 'OPEN',
    ) {
        if ($requestId === '' || $minDice < 1 || $maxDice < $minDice || $maxDice > 100) {
            throw new InvalidArgumentException('Invalid dice authorization.');
        }
        if ($attemptsAllowed < 1 || $attemptsConsumed < 0 || $attemptsConsumed > $attemptsAllowed || $revision < 0) {
            throw new InvalidArgumentException('Invalid attempt authorization.');
        }
        if (!in_array($status, ['OPEN', 'EXHAUSTED', 'CANCELLED', 'EXPIRED', 'CLOSED'], true)) {
            throw new InvalidArgumentException('Invalid request status.');
        }
    }
}
