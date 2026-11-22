<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\MemberStatus;
use App\Domain\PresetVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\PresetDefinition;
use App\Models\PresetVersion;
use App\Models\User;

final class PresetVersionPolicy
{
    /**
     * Canonical published versions are visible to every authenticated user. Personal versions
     * are visible to their owner and to active members of campaigns pinned to them.
     */
    public function view(User $user, PresetVersion $version): bool
    {
        $ownerId = PresetDefinition::query()->whereKey($version->definition_id)->value('owner_user_id');
        if ($ownerId === null) {
            return $version->status === PresetVersionStatus::Published || $this->usedByMemberCampaign($user, $version);
        }

        return $ownerId === $user->getKey() || $this->usedByMemberCampaign($user, $version);
    }

    /** Pinning to a new campaign: canonical published, or the owner's own engine-compatible import. */
    public function pin(User $user, PresetVersion $version): bool
    {
        $ownerId = PresetDefinition::query()->whereKey($version->definition_id)->value('owner_user_id');
        if ($ownerId === null) {
            return $version->status === PresetVersionStatus::Published;
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
