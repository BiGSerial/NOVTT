<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

use RuntimeException;

/** A formula could not be evaluated. `ruleId` is a stable code such as `formula.division_by_zero`. */
final class FormulaException extends RuntimeException
{
    /** @param array<string, scalar|null> $params */
    public function __construct(public readonly string $ruleId, public readonly array $params = [])
    {
        parent::__construct($ruleId);
    }
}
