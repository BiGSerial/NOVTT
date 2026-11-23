<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\CampaignMember;
use App\Models\User;

trait ResolvesMembership
{
    protected function membership(User $user, string $campaignId): ?CampaignMember
    {
        return CampaignMember::query()
            ->where('campaign_id', $campaignId)
            ->where('user_id', $user->getKey())
            ->where('status', MemberStatus::Active)
            ->first();
    }

    protected function isMember(User $user, string $campaignId): bool
    {
        return $this->membership($user, $campaignId) !== null;
    }

    protected function isMaster(User $user, string $campaignId): bool
    {
        return $this->membership($user, $campaignId)?->role === CampaignRole::Master;
    }
}
