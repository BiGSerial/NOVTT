<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

final readonly class RollEvaluation
{
    public function __construct(
        /** Sum or highest (bonus included), or the success count for SUCCESSES. */
        public int $total,
        public int $successes,
        /** True / false when the request has a target; null when there is nothing to compare. */
        public ?bool $succeeded,
    ) {
    }
}
