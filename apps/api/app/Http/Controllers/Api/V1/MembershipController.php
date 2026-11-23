<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AuditTrail;
use App\Domain\CampaignRole;
use App\Domain\CampaignStatus;
use App\Domain\MemberStatus;
use App\Http\Problems\ApiProblem;
use App\Models\Campaign;
use App\Models\CampaignInvitation;
use App\Models\CampaignMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Invitations and members (S001-03). Tokens are 256-bit random secrets stored only as SHA-256. */
final class MembershipController extends ApiController
{
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly AuditTrail $audit)
    {
    }

    public function members(Request $request, string $campaignId): JsonResponse
    {
        $campaign = $this->visibleCampaign($request, $campaignId);
        $members = CampaignMember::query()
            ->with('user:id,name')
            ->where('campaign_id', $campaign->getKey())
            ->where('status', MemberStatus::Active)
            ->orderBy('joined_at')
            ->get();

        return new JsonResponse(['data' => $members->map(self::member(...))->all()]);
    }

    public function invitations(Request $request, string $campaignId): JsonResponse
    {
        $campaign = $this->managedCampaign($request, $campaignId);
        $invitations = CampaignInvitation::query()->where('campaign_id', $campaign->getKey())->orderByDesc('created_at')->limit(100)->get();

        return new JsonResponse(['data' => $invitations->map(self::invitation(...))->all()]);
    }

    public function invite(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $campaign = $this->managedCampaign($request, $campaignId);
        $data = $this->input($request, [
            'expires_in_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'max_uses' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');

        $invitation = DB::transaction(function () use ($actor, $campaign, $data, $token): CampaignInvitation {
            $invitation = CampaignInvitation::query()->create([
                'campaign_id' => $campaign->getKey(),
                'created_by' => $actor->getKey(),
                'token_hash' => hash('sha256', $token),
                'role' => CampaignRole::Player,
                'max_uses' => (int) ($data['max_uses'] ?? 1),
                'expires_at' => now()->addHours((int) $data['expires_in_hours']),
            ]);
            // The token itself is never audited or logged.
            $this->audit->record($actor, $campaign->getKey(), 'invitation.created', $invitation, ['max_uses' => $invitation->max_uses, 'expires_at' => $invitation->expires_at->toIso8601String()]);

            return $invitation->refresh();
        });

        return new JsonResponse(self::invitation($invitation) + ['token' => $token], 201);
    }

    public function revoke(Request $request, string $campaignId, string $invitationId): Response
    {
        $actor = $this->actor($request);
        $campaign = $this->managedCampaign($request, $campaignId);

        DB::transaction(function () use ($actor, $campaign, $invitationId): void {
            $invitation = CampaignInvitation::query()->where('campaign_id', $campaign->getKey())->lockForUpdate()->find($invitationId)
                ?? throw ApiProblem::notFound();
            if ($invitation->revoked_at === null) {
                $invitation->update(['revoked_at' => now()]);
                $this->audit->record($actor, $campaign->getKey(), 'invitation.revoked', $invitation);
            }
        });

        return response()->noContent();
    }

    public function accept(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->input($request, ['token' => ['required', 'string', 'min:43', 'max:128']]);
        $hash = hash('sha256', (string) $data['token']);

        $member = DB::transaction(function () use ($actor, $hash): CampaignMember {
            // One uniform answer for unknown, expired, revoked, exhausted or archived.
            $notFound = ApiProblem::notFound('invitation.not_found');
            $invitation = CampaignInvitation::query()->where('token_hash', $hash)->lockForUpdate()->first() ?? throw $notFound;
            $campaign = Campaign::query()->lockForUpdate()->find($invitation->campaign_id);
            if ($campaign === null || $campaign->status !== CampaignStatus::Active) {
                throw $notFound;
            }
            $existing = CampaignMember::query()->where('campaign_id', $campaign->getKey())->where('user_id', $actor->getKey())->lockForUpdate()->first();
            if ($existing?->status === MemberStatus::Active) {
                return $existing; // idempotent: already a member, no use consumed
            }
            if ($invitation->revoked_at !== null || $invitation->expires_at->isPast() || $invitation->uses >= $invitation->max_uses) {
                throw $notFound;
            }
            $invitation->update(['uses' => $invitation->uses + 1]);
            $attributes = ['role' => $invitation->role, 'status' => MemberStatus::Active, 'invitation_id' => $invitation->getKey(), 'joined_at' => now(), 'removed_at' => null];
            $member = $existing !== null
                ? tap($existing)->update($attributes)
                : CampaignMember::query()->create($attributes + ['campaign_id' => $campaign->getKey(), 'user_id' => $actor->getKey()]);
            $this->audit->record($actor, $campaign->getKey(), 'member.joined', $member, ['invitation_id' => $invitation->getKey(), 'rejoined' => $existing !== null]);
            $this->audit->emit($campaign->getKey(), 'member.joined', $member, ['campaign_id' => $campaign->getKey(), 'member_id' => $member->getKey(), 'role' => $member->role->value]);

            return $member;
        });

        return new JsonResponse(self::member($member->load('user:id,name')));
    }

    public function remove(Request $request, string $campaignId, string $memberId): Response
    {
        $actor = $this->actor($request);
        $campaign = $this->visibleCampaign($request, $campaignId);

        DB::transaction(function () use ($actor, $campaign, $memberId): void {
            // Serialize membership changes per campaign so "last master" checks cannot race.
            Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first();
            $member = CampaignMember::query()->where('campaign_id', $campaign->getKey())->where('status', MemberStatus::Active)->lockForUpdate()->find($memberId)
                ?? throw ApiProblem::notFound();
            $self = $member->user_id === $actor->getKey();
            if (!$self && !Gate::forUser($actor)->allows('manage', $campaign)) {
                throw ApiProblem::notFound();
            }
            if (!$self && $member->role === CampaignRole::Master) {
                throw ApiProblem::conflict('member.cannot_remove_master');
            }
            if ($member->role === CampaignRole::Master) {
                $masters = CampaignMember::query()->where(['campaign_id' => $campaign->getKey(), 'role' => CampaignRole::Master, 'status' => MemberStatus::Active])->count();
                if ($masters <= 1) {
                    throw ApiProblem::conflict('member.last_master');
                }
            }
            $member->update(['status' => MemberStatus::Removed, 'removed_at' => now()]);
            $this->audit->record($actor, $campaign->getKey(), $self ? 'member.left' : 'member.removed', $member);
            $this->audit->emit($campaign->getKey(), 'member.removed', $member, ['campaign_id' => $campaign->getKey(), 'member_id' => $member->getKey()]);
        });

        return response()->noContent();
    }

    private function visibleCampaign(Request $request, string $campaignId): Campaign
    {
        $campaign = Campaign::query()->find($campaignId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $campaign);

        return $campaign;
    }

    private function managedCampaign(Request $request, string $campaignId): Campaign
    {
        $campaign = $this->visibleCampaign($request, $campaignId);
        if (!Gate::forUser($this->actor($request))->allows('manage', $campaign)) {
            // Members learn nothing about invitations; archived campaigns are frozen.
            throw $campaign->status === CampaignStatus::Archived ? ApiProblem::conflict('campaign.archived') : ApiProblem::notFound();
        }

        return $campaign;
    }

    /** @return array<string, mixed> */
    private static function member(CampaignMember $member): array
    {
        /** @var User $user */
        $user = $member->user;

        return ['id' => $member->getKey(), 'user_id' => $member->user_id, 'display_name' => $user->name, 'role' => $member->role->value];
    }

    /** @return array<string, mixed> */
    private static function invitation(CampaignInvitation $invitation): array
    {
        return [
            'id' => $invitation->getKey(),
            'role' => $invitation->role->value,
            'max_uses' => $invitation->max_uses,
            'uses' => $invitation->uses,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'revoked' => $invitation->revoked_at !== null,
        ];
    }
}
