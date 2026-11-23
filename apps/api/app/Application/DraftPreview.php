<?php

declare(strict_types=1);

namespace App\Application;

use Novtt\Engine\Validation\ValidationResult;

/** Draft evaluation shown to its author: filtered issues and the remaining balance per budget. */
final readonly class DraftPreview
{
    /** @param array<string, int> $balances remaining balance per budget if the draft were applied */
    public function __construct(public ValidationResult $validation, public array $balances)
    {
    }
}
