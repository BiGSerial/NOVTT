<?php

declare(strict_types=1);

use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\CampaignMember;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

// Private realtime channels (SPEC-MVP-002 LT-03). Default deny: anything not matched is refused.
$member = static fn (User $user, string $campaignId): ?CampaignMember => Str::isUuid($campaignId)
    ? CampaignMember::query()->where(['campaign_id' => $campaignId, 'user_id' => $user->getKey(), 'status' => MemberStatus::Active])->first()
    : null;

// Table audience: every active member of the campaign.
Broadcast::channel('campaign.{campaignId}', static fn (User $user, string $campaignId): bool => $member($user, $campaignId) !== null);

// Master audience: masters only (compass, notes, NPC numbers, secret rolls, suggestions).
Broadcast::channel('campaign.{campaignId}.master', static fn (User $user, string $campaignId): bool => $member($user, $campaignId)?->role === CampaignRole::Master);

// Personal audience: whispers and secret results addressed to this user.
Broadcast::channel('user.{userId}', static fn (User $user, string $userId): bool => (string) $user->getKey() === $userId);

// Ephemeral master workspace (selection follow between paired devices, ADR 0011). Client events only.
Broadcast::channel('workspace.{userId}', static fn (User $user, string $userId): bool => (string) $user->getKey() === $userId);
