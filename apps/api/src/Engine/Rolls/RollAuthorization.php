<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use DateTimeImmutable;
use InvalidArgumentException;
use Novtt\Engine\Dice\DiceLimits;

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
        public RollStatus $status = RollStatus::OPEN,
        public ?DateTimeImmutable $expiresAt = null,
        public int $faces = 10,
        public int $threshold = 6,
        public int $successesPerDie = 1,
    ) {
        if ($requestId === '' || $minDice < 1 || $maxDice < $minDice || $maxDice > DiceLimits::MAX_DICE) {
            throw new InvalidArgumentException('Invalid dice authorization.');
        }
        if ($attemptsAllowed < 1 || $attemptsConsumed < 0 || $attemptsConsumed > $attemptsAllowed || $revision < 0) {
            throw new InvalidArgumentException('Invalid attempt authorization.');
        }
        if ($faces < 2 || $faces > DiceLimits::MAX_FACES || $threshold < 1 || $threshold > $faces ||
            $successesPerDie < 1 || $successesPerDie > DiceLimits::MAX_SUCCESS_WEIGHT) {
            throw new InvalidArgumentException('Invalid preset dice configuration.');
        }
    }
}
