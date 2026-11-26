<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;

/** A roll request does not fit the preset's declaration. `ruleId` is a stable code. */
final class RollRejected extends InvalidArgumentException
{
    /** @param array<string, scalar|null> $params */
    public function __construct(public readonly string $ruleId, public readonly array $params = [])
    {
        parent::__construct($ruleId);
    }
}
