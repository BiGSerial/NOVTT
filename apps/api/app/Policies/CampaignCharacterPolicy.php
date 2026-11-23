<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\CampaignCharacterStatus;
use App\Domain\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Character-level authorization. Field-level visibility is a separate concern (SheetProjector):
 * `view` only decides whether the character exists for the actor.
 */
final class CampaignCharacterPolicy
{
    use ResolvesMembership;

    /** Masters see every character; a player sees only characters they play. */
    public function view(User $user, CampaignCharacter $character): bool
    {
        if ($this->isMaster($user, $character->campaign_id)) {
            return true;
        }

        return $this->isMember($user, $character->campaign_id) && $character->player_user_id === $user->getKey();
    }

    /** Draft editing and proposal submission belong to whoever is in control (custodian first). */
    public function propose(User $user, CampaignCharacter $character): bool
    {
        return $this->isOpen($character)
            && $this->isMember($user, $character->campaign_id)
            && $character->controllerId() === $user->getKey();
    }

    /** Review, approval, grants, transfer, custody and archiving are master authority. */
    public function master(User $user, CampaignCharacter $character): bool
    {
        return $this->isOpen($character) && $this->isMaster($user, $character->campaign_id);
    }

    private function isOpen(CampaignCharacter $character): bool
    {
        return $character->status !== CampaignCharacterStatus::Archived
            && Campaign::query()->whereKey($character->campaign_id)->where('status', CampaignStatus::Active)->exists();
    }
}
