<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AuditTrail;
use App\Application\Idempotency;
use App\Domain\CampaignRole;
use App\Domain\CampaignStatus;
use App\Domain\MemberStatus;
use App\Http\Problems\ApiProblem;
use App\Http\Resources\CampaignPresenter;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\GameSession;
use App\Models\PresetVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

final class CampaignController extends ApiController
{
    public function __construct(private readonly AuditTrail $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $page = CampaignMember::query()
            ->with('campaign')
            ->where('user_id', $actor->getKey())
            ->where('status', MemberStatus::Active)
            ->orderBy('campaign_id')
            ->cursorPaginate($limit);

        $ids = array_map(static fn (CampaignMember $m): string => $m->campaign_id, $page->items());
        $summaries = $this->summaries($ids);

        return new JsonResponse([
            'data' => array_map(static fn (CampaignMember $m): array => CampaignPresenter::toArray($m->campaign, $m->role->value) + ($summaries[$m->campaign_id] ?? []), $page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Who masters each campaign, how many play and the latest session, in three queries for the
     * whole page (no N+1). Only display names of fellow members: no e-mails.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = array_fill_keys($ids, ['masters' => [], 'players_count' => 0, 'last_session' => null]);
        $members = CampaignMember::query()->join('users', 'users.id', '=', 'campaign_members.user_id')
            ->whereIn('campaign_members.campaign_id', $ids)->where('campaign_members.status', MemberStatus::Active)
            ->orderBy('users.name')
            ->get(['campaign_members.campaign_id', 'campaign_members.role', 'campaign_members.user_id', 'users.name']);
        foreach ($members as $m) {
            $role = is_string($m->role) ? $m->role : $m->role->value;
            if ($role === CampaignRole::Master->value) {
                $out[$m->campaign_id]['masters'][] = ['user_id' => $m->user_id, 'name' => $m->name];
            } else {
                $out[$m->campaign_id]['players_count']++;
            }
        }
        $latest = GameSession::query()->whereIn('campaign_id', $ids)
            ->select(['campaign_id', 'title', 'status', 'started_at', 'ended_at'])
            ->whereIn('id', GameSession::query()->selectRaw('DISTINCT ON (campaign_id) id')->whereIn('campaign_id', $ids)->orderBy('campaign_id')->orderByDesc('started_at'))
            ->get();
        foreach ($latest as $session) {
            $out[$session->campaign_id]['last_session'] = [
                'title' => $session->title,
                'status' => $session->status,
                'started_at' => $session->started_at?->format(DATE_RFC3339_EXTENDED),
                'ended_at' => $session->ended_at?->format(DATE_RFC3339_EXTENDED),
            ];
        }

        return $out;
    }

    public function store(Request $request, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $data = $this->input($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'preset_version_id' => ['required', 'uuid'],
        ]);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'campaign.create', $key, hash('sha256', CanonicalJson::encode($data)), function () use ($actor, $data): array {
            $version = PresetVersion::query()->find($data['preset_version_id']);
            // Same answer for "does not exist", "private" and "not pinnable": no existence leak.
            if ($version === null || !Gate::forUser($actor)->allows('pin', $version)) {
                throw ApiProblem::validation(new ValidationResult([
                    new ValidationIssue('campaign.preset_unavailable', Severity::Error, [], ['/preset_version_id']),
                ]), 'campaign.preset_unavailable');
            }
            $campaign = Campaign::query()->create([
                'owner_user_id' => $actor->getKey(),
                'preset_version_id' => $version->getKey(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            CampaignMember::query()->create([
                'campaign_id' => $campaign->getKey(),
                'user_id' => $actor->getKey(),
                'role' => CampaignRole::Master,
                'joined_at' => now(),
            ]);
            if ($version->definition()->value('owner_user_id') !== null) {
                DB::table('user_preset_library')->insertOrIgnore(['user_id' => $actor->getKey(), 'preset_version_id' => $version->getKey(), 'added_at' => now()]);
            }
            $this->audit->record($actor, $campaign->getKey(), 'campaign.created', $campaign, ['preset_version_id' => $version->getKey()]);
            $this->audit->emit($campaign->getKey(), 'campaign.created', $campaign, ['campaign_id' => $campaign->getKey(), 'revision' => 1]);

            return [201, CampaignPresenter::toArray($campaign, CampaignRole::Master->value)];
        }));

        return new JsonResponse($body, $status);
    }

    public function show(Request $request, string $campaignId): JsonResponse
    {
        $campaign = $this->findVisible($request, $campaignId);

        return new JsonResponse(CampaignPresenter::toArray($campaign, $this->roleOf($request, $campaign)));
    }

    public function update(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $this->findVisible($request, $campaignId);
        $data = $this->input($request, [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $campaign = DB::transaction(function () use ($actor, $campaignId, $data): Campaign {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if (!$this->isMaster($actor->getKey(), $campaign)) {
                throw ApiProblem::notFound();
            }
            if ($campaign->status === CampaignStatus::Archived) {
                throw ApiProblem::conflict('campaign.archived', $campaign->revision);
            }
            if ($campaign->revision !== (int) $data['expected_revision']) {
                throw ApiProblem::conflict('campaign.revision_conflict', $campaign->revision);
            }
            $changes = array_intersect_key($data, array_flip(['name', 'description']));
            $campaign->fill($changes + ['revision' => $campaign->revision + 1])->save();
            $this->audit->record($actor, $campaign->getKey(), 'campaign.updated', $campaign, ['fields' => array_keys($changes), 'revision' => $campaign->revision]);
            $this->audit->emit($campaign->getKey(), 'campaign.updated', $campaign, ['campaign_id' => $campaign->getKey(), 'revision' => $campaign->revision]);

            return $campaign;
        });

        return new JsonResponse(CampaignPresenter::toArray($campaign, CampaignRole::Master->value));
    }

    public function archive(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $this->findVisible($request, $campaignId);

        $campaign = DB::transaction(function () use ($actor, $campaignId): Campaign {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if (!$this->isMaster($actor->getKey(), $campaign)) {
                throw ApiProblem::notFound();
            }
            if ($campaign->status === CampaignStatus::Archived) {
                throw ApiProblem::conflict('campaign.archived', $campaign->revision);
            }
            $campaign->fill(['status' => CampaignStatus::Archived, 'archived_at' => now(), 'revision' => $campaign->revision + 1])->save();
            $this->audit->record($actor, $campaign->getKey(), 'campaign.archived', $campaign);
            $this->audit->emit($campaign->getKey(), 'campaign.archived', $campaign, ['campaign_id' => $campaign->getKey(), 'revision' => $campaign->revision]);

            return $campaign;
        });

        return new JsonResponse(CampaignPresenter::toArray($campaign, CampaignRole::Master->value));
    }

    private function findVisible(Request $request, string $campaignId): Campaign
    {
        $campaign = Campaign::query()->find($campaignId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $campaign);

        return $campaign;
    }

    private function roleOf(Request $request, Campaign $campaign): string
    {
        return (string) CampaignMember::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('user_id', $this->actor($request)->getKey())
            ->where('status', MemberStatus::Active)
            ->value('role')?->value;
    }

    private function isMaster(string $userId, Campaign $campaign): bool
    {
        return CampaignMember::query()->where([
            'campaign_id' => $campaign->getKey(), 'user_id' => $userId, 'role' => CampaignRole::Master, 'status' => MemberStatus::Active,
        ])->exists();
    }
}
