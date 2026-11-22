<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/** Default deny. Non-members get false; controllers translate that into 404 (no existence leak). */
final class CampaignPolicy
{
    use ResolvesMembership;

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->isMember($user, $campaign->getKey());
    }

    /** Master-only management while the campaign is active (rename, invitations, members, NPCs, review). */
    public function manage(User $user, Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Active && $this->isMaster($user, $campaign->getKey());
    }

    public function archive(User $user, Campaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }

    /** Players (and masters) may bring a character into an active campaign. */
    public function addCharacter(User $user, Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Active && $this->isMember($user, $campaign->getKey());
    }
}
