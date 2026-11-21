<?php
declare(strict_types=1);

namespace Novtt\Engine\Dice;

final readonly class RollResult
{
    /** @param list<int> $dice */
    public function __construct(
        public array $dice,
        public int $successes
    ) {
    }
}
