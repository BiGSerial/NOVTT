<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignCharacter;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

final class ChangeRequestPolicy
{
    use ResolvesMembership;

    public function view(User $user, ChangeRequest $request): bool
    {
        $campaignId = $this->campaignId($request);

        return $this->isMaster($user, $campaignId)
            || ($this->isMember($user, $campaignId) && $request->author_user_id === $user->getKey());
    }

    /** Withdraw and rebase: the author, while still a member. */
    public function author(User $user, ChangeRequest $request): bool
    {
        return $request->author_user_id === $user->getKey() && $this->isMember($user, $this->campaignId($request));
    }

    /** Decide and finalize: a master of the campaign. */
    public function review(User $user, ChangeRequest $request): bool
    {
        return $this->isMaster($user, $this->campaignId($request));
    }

    private function campaignId(ChangeRequest $request): string
    {
        return (string) CampaignCharacter::query()->whereKey($request->campaign_character_id)->value('campaign_id');
    }
}
