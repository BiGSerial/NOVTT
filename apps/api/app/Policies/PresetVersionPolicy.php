<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\MemberStatus;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\PresetDefinition;
use App\Models\PresetVersion;
use App\Models\User;

final class PresetVersionPolicy
{
    /**
     * Canonical published versions with approved rights are visible to every authenticated user. Personal versions
     * are visible to their owner and to active members of campaigns pinned to them.
     */
    public function view(User $user, PresetVersion $version): bool
    {
        $ownerId = PresetDefinition::query()->whereKey($version->definition_id)->value('owner_user_id');
        if ($ownerId === null) {
            return $version->isPubliclyListable() || $this->usedByMemberCampaign($user, $version);
        }

        return $ownerId === $user->getKey() || $this->usedByMemberCampaign($user, $version);
    }

    /**
     * Pinning to a new campaign: canonical published with approved rights, or the owner's own
     * engine-compatible import. Campaigns already pinned keep their version after a quarantine.
     */
    public function pin(User $user, PresetVersion $version): bool
    {
        $ownerId = PresetDefinition::query()->whereKey($version->definition_id)->value('owner_user_id');
        if ($ownerId === null) {
            return $version->isPubliclyListable();
        }

        return $ownerId === $user->getKey() && $version->status->isPinnable();
    }

    private function usedByMemberCampaign(User $user, PresetVersion $version): bool
    {
        return Campaign::query()
            ->where('preset_version_id', $version->getKey())
            ->whereIn('id', CampaignMember::query()->select('campaign_id')
                ->where('user_id', $user->getKey())
                ->where('status', MemberStatus::Active))
            ->exists();
    }
}
