<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AuditTrail;
use App\Application\CharacterAdaptation;
use App\Application\Idempotency;
use App\Application\SheetViews;
use App\Domain\CampaignCharacterStatus;
use App\Domain\CampaignRole;
use App\Domain\ChangeRequestStatus;
use App\Domain\MemberStatus;
use App\Http\Problems\ApiProblem;
use App\Http\Resources\CharacterPresenter;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\CampaignNpc;
use App\Models\ChangeRequest;
use App\Models\LibraryCharacter;
use App\Models\LibraryNpc;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Sheets\SheetMigration;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/** Campaign character instances and campaign NPCs (S001-04). */
final class CampaignCharacterController extends ApiController
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly CharacterPresenter $presenter,
        private readonly SheetViews $views,
    ) {
    }

    public function index(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $campaign = $this->visibleCampaign($request, $campaignId);
        $isMaster = $this->isMaster($actor, $campaign->getKey());
        $characters = CampaignCharacter::query()
            ->where('campaign_id', $campaign->getKey())
            ->when(!$isMaster, fn ($q) => $q->where('player_user_id', $actor->getKey()))
            ->orderBy('id')
            ->limit(200)
            ->get();

        return new JsonResponse(['data' => $characters->map(fn (CampaignCharacter $c): array => $this->presenter->campaignCharacter($c, $isMaster))->all()]);
    }

    public function store(Request $request, string $campaignId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $campaign = $this->visibleCampaign($request, $campaignId);
        if (!Gate::forUser($actor)->allows('addCharacter', $campaign)) {
            throw ApiProblem::conflict('campaign.archived');
        }
        $data = $this->input($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'library_character_id' => ['sometimes', 'uuid'],
            'from_campaign_character_id' => ['sometimes', 'uuid'],
        ]);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'campaign_character.create:' . $campaign->getKey(), $key, hash('sha256', CanonicalJson::encode($data)), function () use ($actor, $campaign, $data): array {
            $libraryId = $data['library_character_id'] ?? null;
            if ($libraryId !== null) {
                // Only the owner's own, non-archived library entries can be brought in.
                LibraryCharacter::query()->where('owner_user_id', $actor->getKey())->whereNull('archived_at')->find($libraryId)
                    ?? throw ApiProblem::validation(new ValidationResult([new ValidationIssue('character.library_unavailable', Severity::Error, [], ['/library_character_id'])]));
                if (CampaignCharacter::query()->where('campaign_id', $campaign->getKey())->where('library_character_id', $libraryId)->exists()) {
                    throw ApiProblem::conflict('character.already_in_campaign');
                }
            }
            // Bringing one's own character from another campaign: the same game carries the sheet
            // (adapted to this campaign's version); another game starts a new sheet with the name.
            [$sheet, $origin, $carried] = [(object) [], null, false];
            if (isset($data['from_campaign_character_id'])) {
                $origin = CampaignCharacter::query()->where('player_user_id', $actor->getKey())->where('status', '!=', CampaignCharacterStatus::Archived->value)->find($data['from_campaign_character_id'])
                    ?? throw ApiProblem::validation(new ValidationResult([new ValidationIssue('character.source_unavailable', Severity::Error, [], ['/from_campaign_character_id'])]));
                $target = PresetVersion::query()->findOrFail($campaign->preset_version_id);
                $source = PresetVersion::query()->findOrFail($origin->preset_version_id);
                if ($source->definition_id === $target->definition_id) {
                    $migrated = SheetMigration::migrate((array) $origin->sheet, (array) $target->sheet_schema)['sheet'];
                    unset($migrated[SheetMigration::LEDGER]); // points are re-checked by this campaign's master
                    $sheet = $migrated === [] ? (object) [] : $migrated;
                    $carried = true;
                }
            }
            $character = CampaignCharacter::query()->create([
                'campaign_id' => $campaign->getKey(),
                'library_character_id' => $libraryId,
                'player_user_id' => $actor->getKey(),
                'preset_version_id' => $campaign->preset_version_id,
                'name' => $data['name'],
                'sheet' => $sheet,
                'portrait_image_id' => null,
            ]);
            $this->audit->record($actor, $campaign->getKey(), 'campaign_character.created', $character, ['library_character_id' => $libraryId, 'from_campaign_character_id' => $origin?->getKey(), 'sheet_carried' => $carried]);
            $this->audit->emit($campaign->getKey(), 'campaign_character.created', $character, ['campaign_character_id' => $character->getKey(), 'sheet_revision' => 0]);

            return [201, $this->presenter->campaignCharacter($character->refresh(), $this->isMaster($actor, $campaign->getKey()))];
        }));

        return new JsonResponse($body, $status);
    }

    public function show(Request $request, string $campaignCharacterId): JsonResponse
    {
        $character = $this->visibleCharacter($request, $campaignCharacterId);

        return new JsonResponse($this->presenter->campaignCharacter($character, $this->isMaster($this->actor($request), $character->campaign_id)));
    }

    public function archive(Request $request, string $campaignCharacterId): JsonResponse
    {
        return $this->masterAction($request, $campaignCharacterId, function (User $actor, CampaignCharacter $character): void {
            $character->update(['status' => CampaignCharacterStatus::Archived, 'archived_at' => now()]);
            ChangeRequest::query()->where('campaign_character_id', $character->getKey())->whereIn('status', ChangeRequestStatus::ACTIVE)
                ->update(['status' => ChangeRequestStatus::Withdrawn->value]);
            $this->audit->record($actor, $character->campaign_id, 'campaign_character.archived', $character);
            $this->audit->emit($character->campaign_id, 'campaign_character.archived', $character, ['campaign_character_id' => $character->getKey()]);
        });
    }

    public function transfer(Request $request, string $campaignCharacterId): JsonResponse
    {
        $data = $this->input($request, ['to_user_id' => ['required', 'uuid']]);

        return $this->masterAction($request, $campaignCharacterId, function (User $actor, CampaignCharacter $character) use ($data): void {
            $target = CampaignMember::query()->where([
                'campaign_id' => $character->campaign_id, 'user_id' => $data['to_user_id'], 'role' => CampaignRole::Player, 'status' => MemberStatus::Active,
            ])->first();
            if ($target === null) {
                throw ApiProblem::validation(new ValidationResult([new ValidationIssue('character.transfer_target_invalid', Severity::Error, [], ['/to_user_id'])]));
            }
            if ($this->hasActiveProposal($character)) {
                throw ApiProblem::conflict('character.active_proposal');
            }
            $from = $character->player_user_id;
            $character->update(['player_user_id' => $target->user_id, 'library_character_id' => null]);
            $this->audit->record($actor, $character->campaign_id, 'campaign_character.transferred', $character, ['from_user_id' => $from, 'to_user_id' => $target->user_id]);
            $this->audit->emit($character->campaign_id, 'campaign_character.transferred', $character, ['campaign_character_id' => $character->getKey()]);
        });
    }

    public function takeCustody(Request $request, string $campaignCharacterId): JsonResponse
    {
        return $this->masterAction($request, $campaignCharacterId, function (User $actor, CampaignCharacter $character): void {
            if ($character->custodian_user_id === $actor->getKey()) {
                return;
            }
            if ($character->custodian_user_id !== null) {
                throw ApiProblem::conflict('character.in_custody');
            }
            $character->update(['custodian_user_id' => $actor->getKey()]);
            $this->audit->record($actor, $character->campaign_id, 'campaign_character.custody_taken', $character);
        });
    }

    public function releaseCustody(Request $request, string $campaignCharacterId): JsonResponse
    {
        return $this->masterAction($request, $campaignCharacterId, function (User $actor, CampaignCharacter $character): void {
            if ($character->custodian_user_id === null) {
                return;
            }
            if ($this->hasActiveProposal($character, $character->custodian_user_id)) {
                throw ApiProblem::conflict('character.active_proposal');
            }
            $character->update(['custodian_user_id' => null]);
            $this->audit->record($actor, $character->campaign_id, 'campaign_character.custody_released', $character);
        });
    }

    public function npcs(Request $request, string $campaignId): JsonResponse
    {
        $campaign = $this->masterCampaign($request, $campaignId, allowArchived: true);
        $npcs = CampaignNpc::query()->where('campaign_id', $campaign->getKey())->whereNull('archived_at')->orderBy('id')->limit(200)->get();

        return new JsonResponse(['data' => $npcs->map(CharacterPresenter::campaignNpc(...))->all()]);
    }

    public function storeNpc(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $campaign = $this->masterCampaign($request, $campaignId);
        $data = $this->input($request, [
            'source_npc_id' => ['sometimes', 'uuid'],
            'name' => ['required_without:source_npc_id', 'string', 'min:1', 'max:120'],
        ]);
        $source = null;
        $sheet = [];
        if (isset($data['source_npc_id'])) {
            $source = LibraryNpc::query()->where('owner_user_id', $actor->getKey())->whereNull('archived_at')->find($data['source_npc_id'])
                ?? throw ApiProblem::validation(new ValidationResult([new ValidationIssue('npc.source_unavailable', Severity::Error, [], ['/source_npc_id'])]));
            if ($source->preset_version_id !== null && $source->preset_version_id !== $campaign->preset_version_id) {
                throw ApiProblem::validation(new ValidationResult([new ValidationIssue('npc.preset_mismatch', Severity::Error, [], ['/source_npc_id'])]));
            }
            // Sanitized copy: declared fields only; the library's private notes are never copied.
            $sheet = $source->preset_version_id === null ? [] : $this->views->projector($campaign->preset_version_id)->forMaster((array) $source->sheet);
        }
        $npc = DB::transaction(function () use ($actor, $campaign, $data, $source, $sheet): CampaignNpc {
            $npc = CampaignNpc::query()->create([
                'campaign_id' => $campaign->getKey(),
                'source_npc_id' => $source?->getKey(),
                'preset_version_id' => $campaign->preset_version_id,
                'name' => $data['name'] ?? $source?->name,
                'sheet' => $sheet === [] ? (object) [] : $sheet,
                'created_by' => $actor->getKey(),
            ]);
            $this->audit->record($actor, $campaign->getKey(), 'campaign_npc.created', $npc, ['source_npc_id' => $source?->getKey()]);

            return $npc->refresh();
        });

        return new JsonResponse(CharacterPresenter::campaignNpc($npc), 201);
    }

    /** @param callable(User, CampaignCharacter): void $action */
    /** What adapting this character to the campaign's current sheet version would keep and drop. */
    public function adaptation(Request $request, string $campaignCharacterId, CharacterAdaptation $adaptation): JsonResponse
    {
        $character = $this->visibleCharacter($request, $campaignCharacterId);
        $this->requireOwnerOrMaster($this->actor($request), $character);

        return new JsonResponse($adaptation->preview($character));
    }

    /** Adapts the character to the campaign's current sheet version (owner or master). */
    public function adapt(Request $request, string $campaignCharacterId, CharacterAdaptation $adaptation): JsonResponse
    {
        $actor = $this->actor($request);
        $this->visibleCharacter($request, $campaignCharacterId);
        $data = $this->input($request, ['expected_sheet_revision' => ['required', 'integer', 'min:0']]);
        [$report, $character] = DB::transaction(function () use ($actor, $campaignCharacterId, $data, $adaptation): array {
            $character = CampaignCharacter::query()->lockForUpdate()->findOrFail($campaignCharacterId);
            $this->requireOwnerOrMaster($actor, $character);
            if ($character->status === CampaignCharacterStatus::Archived) {
                throw ApiProblem::conflict('character.archived');
            }

            return [$adaptation->adapt($actor, $character, (int) $data['expected_sheet_revision']), $character->refresh()];
        });

        return new JsonResponse(['report' => $report, 'character' => $this->presenter->campaignCharacter($character, $this->isMaster($actor, $character->campaign_id))]);
    }

    private function requireOwnerOrMaster(User $actor, CampaignCharacter $character): void
    {
        $owner = ($character->custodian_user_id ?? $character->player_user_id) === $actor->getKey();
        if (!$owner && !$this->isMaster($actor, $character->campaign_id)) {
            throw new ApiProblem(403, 'character.not_yours');
        }
    }

    private function masterAction(Request $request, string $campaignCharacterId, callable $action): JsonResponse
    {
        $actor = $this->actor($request);
        $this->visibleCharacter($request, $campaignCharacterId);
        $character = DB::transaction(function () use ($actor, $campaignCharacterId, $action): CampaignCharacter {
            $character = CampaignCharacter::query()->lockForUpdate()->findOrFail($campaignCharacterId);
            if (!$this->isMaster($actor, $character->campaign_id)) {
                throw ApiProblem::notFound();
            }
            if (!Gate::forUser($actor)->allows('master', $character)) {
                throw ApiProblem::conflict($character->status === CampaignCharacterStatus::Archived ? 'character.archived' : 'campaign.archived');
            }
            $action($actor, $character);

            return $character->refresh();
        });

        return new JsonResponse($this->presenter->campaignCharacter($character, true));
    }

    private function hasActiveProposal(CampaignCharacter $character, ?string $authorId = null): bool
    {
        return ChangeRequest::query()
            ->where('campaign_character_id', $character->getKey())
            ->whereIn('status', ChangeRequestStatus::ACTIVE)
            ->when($authorId !== null, fn ($q) => $q->where('author_user_id', $authorId))
            ->exists();
    }

    private function visibleCampaign(Request $request, string $campaignId): Campaign
    {
        $campaign = Campaign::query()->find($campaignId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $campaign);

        return $campaign;
    }

    private function masterCampaign(Request $request, string $campaignId, bool $allowArchived = false): Campaign
    {
        $campaign = $this->visibleCampaign($request, $campaignId);
        if (!$this->isMaster($this->actor($request), $campaign->getKey())) {
            throw ApiProblem::notFound();
        }
        if (!$allowArchived && !Gate::forUser($this->actor($request))->allows('manage', $campaign)) {
            throw ApiProblem::conflict('campaign.archived');
        }

        return $campaign;
    }

    private function visibleCharacter(Request $request, string $campaignCharacterId): CampaignCharacter
    {
        $character = CampaignCharacter::query()->find($campaignCharacterId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $character);

        return $character;
    }

    private function isMaster(User $user, string $campaignId): bool
    {
        return CampaignMember::query()->where([
            'campaign_id' => $campaignId, 'user_id' => $user->getKey(), 'role' => CampaignRole::Master, 'status' => MemberStatus::Active,
        ])->exists();
    }
}
