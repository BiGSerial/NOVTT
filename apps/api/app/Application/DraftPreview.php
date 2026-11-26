<?php

declare(strict_types=1);

namespace App\Application;

use App\Models\CampaignCharacter;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Draft evaluation shown to its author: filtered issues, the remaining balance per budget and the
 * candidate sheet (the official sheet with the draft applied), which the presenter projects for
 * the viewer with derived values, creation progress and choice availability.
 */
final readonly class DraftPreview
{
    /**
     * @param array<string, int> $balances remaining balance per budget if the draft were applied
     * @param array<string, mixed> $candidate
     */
    public function __construct(
        public ValidationResult $validation,
        public array $balances,
        public ?CampaignCharacter $character = null,
        public array $candidate = [],
        public bool $asMaster = false,
    ) {
    }
}
