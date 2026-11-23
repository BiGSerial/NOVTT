<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Campaign;

final class CampaignPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(Campaign $campaign, string $role): array
    {
        return [
            'id' => $campaign->getKey(),
            'name' => $campaign->name,
            'description' => $campaign->description,
            'status' => $campaign->status->value,
            'revision' => $campaign->revision,
            'preset_version_id' => $campaign->preset_version_id,
            'role' => $role,
        ];
    }
}
