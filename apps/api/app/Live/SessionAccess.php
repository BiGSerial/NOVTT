<?php

declare(strict_types=1);

namespace App\Live;

use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Http\Problems\ApiProblem;
use App\Models\CampaignMember;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves the actor's role at a campaign or session. Non-members get 404 (no existence leak);
 * members without the required role get 403 only when they can already see the resource.
 */
final class SessionAccess
{
    public function role(User $user, string $campaignId): ?CampaignRole
    {
        if (! Str::isUuid($campaignId)) {
            return null;
        }

        $role = CampaignMember::query()
            ->where(['campaign_id' => $campaignId, 'user_id' => $user->getKey(), 'status' => MemberStatus::Active])
            ->value('role');

        return $role === null ? null : ($role instanceof CampaignRole ? $role : CampaignRole::from((string) $role));
    }

    public function memberRole(User $user, string $campaignId): CampaignRole
    {
        return $this->role($user, $campaignId) ?? throw ApiProblem::notFound();
    }

    public function requireMaster(User $user, string $campaignId): void
    {
        if ($this->memberRole($user, $campaignId) !== CampaignRole::Master) {
            throw new ApiProblem(403, 'live.master_only');
        }
    }

    /** @return array{0: GameSession, 1: CampaignRole} */
    public function session(User $user, string $sessionId): array
    {
        $session = Str::isUuid($sessionId) ? GameSession::query()->find($sessionId) : null;
        if ($session === null) {
            throw ApiProblem::notFound();
        }

        return [$session, $this->memberRole($user, $session->campaign_id)];
    }

    /** Locks an open (active or paused) session row for the rest of the transaction. */
    public function lockOpen(string $sessionId, bool $requireActive = false): GameSession
    {
        $session = GameSession::query()->lockForUpdate()->findOrFail($sessionId);
        if ($session->status === 'ended') {
            throw ApiProblem::conflict('session.ended');
        }
        if ($requireActive && $session->status !== 'active') {
            throw ApiProblem::conflict('session.paused');
        }

        return $session;
    }

    /** The campaign's open session, locked, or null when none is open. */
    public function lockOpenForCampaign(string $campaignId): ?GameSession
    {
        return GameSession::query()->where('campaign_id', $campaignId)->whereIn('status', ['active', 'paused'])->lockForUpdate()->first();
    }
}
