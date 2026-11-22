<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Idempotency;
use App\Application\ReviewWorkflow;
use App\Application\SheetChanges;
use App\Domain\OperationDecisionValue;
use App\Http\Problems\ApiProblem;
use App\Http\Resources\CharacterPresenter;
use App\Models\CampaignCharacter;
use App\Models\ChangeOperation;
use App\Models\ChangeRequest;
use App\Models\CharacterDraft;
use App\Models\CharacterGrant;
use App\Models\OperationDecision;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Sheets\ChangeOperation as EngineOperation;
use Novtt\Engine\Sheets\ChangeSet;
use Novtt\Engine\Validation\ValidationResult;

/** Drafts, proposals, master review, finalization, rebase, character approval and grants (S001-05). */
final class ReviewController extends ApiController
{
    public function __construct(
        private readonly ReviewWorkflow $workflow,
        private readonly SheetChanges $changes,
        private readonly CharacterPresenter $presenter,
    ) {
    }

    public function showDraft(Request $request, string $campaignCharacterId): JsonResponse
    {
        $actor = $this->actor($request);
        $character = $this->visibleCharacter($request, $campaignCharacterId);
        $draft = CharacterDraft::query()->where(['campaign_character_id' => $character->getKey(), 'author_user_id' => $actor->getKey()])->first()
            ?? throw ApiProblem::notFound('draft.not_found');

        return new JsonResponse($this->draftView($draft, $this->workflow->preview($actor, $character, ChangeSet::fromArray((array) $draft->change_set))));
    }

    public function saveDraft(Request $request, string $campaignCharacterId): JsonResponse
    {
        $this->visibleCharacter($request, $campaignCharacterId);
        $data = $this->input($request, ['draft_version' => ['required', 'integer', 'min:0'], 'change_set' => ['required', 'array']]);
        [$draft, $validation] = $this->workflow->saveDraft($this->actor($request), $campaignCharacterId, (int) $data['draft_version'], $this->rawObjectField($request, 'change_set'));

        return new JsonResponse($this->draftView($draft, $validation));
    }

    public function rebasePreview(Request $request, string $campaignCharacterId): JsonResponse
    {
        $this->visibleCharacter($request, $campaignCharacterId);

        return new JsonResponse($this->workflow->rebasePreview($this->actor($request), $campaignCharacterId));
    }

    public function rebase(Request $request, string $campaignCharacterId): JsonResponse
    {
        $this->visibleCharacter($request, $campaignCharacterId);
        $data = $this->input($request, [
            'preview_token' => ['required', 'string', 'max:128'],
            'resolutions' => ['present', 'array', 'max:200'],
            'resolutions.*.operation_id' => ['required', 'string', 'max:64'],
            'resolutions.*.resolution' => ['required', 'in:keep_mine,take_current,drop'],
        ]);
        [$draft, $validation] = $this->workflow->rebaseConfirm($this->actor($request), $campaignCharacterId, (string) $data['preview_token'], array_values($data['resolutions']));

        return new JsonResponse($this->draftView($draft, $validation));
    }

    public function index(Request $request, string $campaignCharacterId): JsonResponse
    {
        $actor = $this->actor($request);
        $character = $this->visibleCharacter($request, $campaignCharacterId);
        $isMaster = $this->changes->isMaster($actor, $character->campaign_id);
        $requests = ChangeRequest::query()
            ->where('campaign_character_id', $character->getKey())
            ->when(!$isMaster, fn ($q) => $q->where('author_user_id', $actor->getKey()))
            ->orderByDesc('submitted_at')
            ->limit(100)
            ->get();

        return new JsonResponse(['data' => $requests->map(fn (ChangeRequest $r): array => $this->requestView($r, $character, $isMaster, withValidation: false))->all()]);
    }

    public function submit(Request $request, string $campaignCharacterId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $character = $this->visibleCharacter($request, $campaignCharacterId);
        $data = $this->input($request, ['draft_version' => ['required', 'integer', 'min:1']]);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'change_request.submit:' . $character->getKey(), $key, hash('sha256', CanonicalJson::encode($data)), function () use ($actor, $character, $data): array {
            $created = $this->workflow->submit($actor, $character->getKey(), (int) $data['draft_version']);

            return [201, $this->requestView($created, $character->refresh(), $this->changes->isMaster($actor, $character->campaign_id))];
        }));

        return new JsonResponse($body, $status);
    }

    public function show(Request $request, string $changeRequestId): JsonResponse
    {
        $actor = $this->actor($request);
        [$changeRequest, $character] = $this->visibleRequest($request, $changeRequestId);

        return new JsonResponse($this->requestView($changeRequest, $character, $this->changes->isMaster($actor, $character->campaign_id)));
    }

    public function withdraw(Request $request, string $changeRequestId): JsonResponse
    {
        [, $character] = $this->visibleRequest($request, $changeRequestId);
        $withdrawn = $this->workflow->withdraw($this->actor($request), $changeRequestId);

        return new JsonResponse($this->requestView($withdrawn, $character, $this->changes->isMaster($this->actor($request), $character->campaign_id)));
    }

    public function decide(Request $request, string $changeRequestId): JsonResponse
    {
        [, $character] = $this->visibleRequest($request, $changeRequestId);
        $data = $this->input($request, [
            'review_version' => ['required', 'integer', 'min:1'],
            'decisions' => ['required', 'array', 'min:1', 'max:200'],
            'decisions.*.operation_ids' => ['required', 'array', 'min:1', 'max:200'],
            'decisions.*.operation_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'decisions.*.decision' => ['required', 'in:approved,rejected'],
            'decisions.*.reason' => ['required_if:decisions.*.decision,rejected', 'prohibited_if:decisions.*.decision,approved', 'string', 'min:10', 'max:1000'],
            'decisions.*.override' => ['sometimes', 'accepted'],
        ]);
        foreach ($data['decisions'] as $i => $entry) {
            // Every rejection carries its own reason: one operation per rejection entry.
            if (($entry['decision'] === 'rejected' || !empty($entry['override'])) && count($entry['operation_ids']) !== 1) {
                throw ApiProblem::validation(SheetChanges::error('decision.individual_entry_required', "/decisions/{$i}/operation_ids"));
            }
            if ($entry['decision'] === 'rejected' && mb_strlen(trim((string) $entry['reason'])) < 10) {
                throw ApiProblem::validation(SheetChanges::error('decision.reason_too_short', "/decisions/{$i}/reason"));
            }
        }
        $updated = $this->workflow->decide($this->actor($request), $changeRequestId, (int) $data['review_version'], array_values($data['decisions']));

        return new JsonResponse($this->requestView($updated, $character, true));
    }

    public function finalize(Request $request, string $changeRequestId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        [, $character] = $this->visibleRequest($request, $changeRequestId);
        $data = $this->input($request, ['review_version' => ['required', 'integer', 'min:1']]);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'change_request.finalize:' . $changeRequestId, $key, hash('sha256', CanonicalJson::encode($data)), function () use ($actor, $changeRequestId, $character, $data): array {
            $finalized = $this->workflow->finalize($actor, $changeRequestId, (int) $data['review_version']);

            return [200, $this->requestView($finalized, $character->refresh(), true, withValidation: false)];
        }));

        return new JsonResponse($body, $status);
    }

    public function approve(Request $request, string $campaignCharacterId): JsonResponse
    {
        $this->visibleCharacter($request, $campaignCharacterId);
        $character = DB::transaction(fn (): CampaignCharacter => $this->workflow->approveCharacter($this->actor($request), $campaignCharacterId));

        return new JsonResponse($this->presenter->campaignCharacter($character, true));
    }

    public function grants(Request $request, string $campaignCharacterId): JsonResponse
    {
        $character = $this->visibleCharacter($request, $campaignCharacterId);
        $grants = CharacterGrant::query()->where('campaign_character_id', $character->getKey())->orderBy('created_at')->limit(500)->get();
        $balances = $this->changes->engine($character->preset_version_id)->balances((array) $character->sheet, $this->changes->grants($character));

        return new JsonResponse([
            'data' => $grants->map(static fn (CharacterGrant $g): array => [
                'id' => $g->getKey(), 'budget_id' => $g->budget_id, 'amount' => $g->amount, 'reason' => $g->reason, 'created_at' => $g->created_at->toIso8601String(),
            ])->all(),
            'balances' => (object) $balances,
        ]);
    }

    public function grant(Request $request, string $campaignCharacterId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $this->visibleCharacter($request, $campaignCharacterId);
        $data = $this->input($request, [
            'budget_id' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'amount' => ['required', 'integer', 'between:-10000,10000', 'not_in:0'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'grant.create:' . $campaignCharacterId, $key, hash('sha256', CanonicalJson::encode($data)), function () use ($actor, $campaignCharacterId, $data): array {
            $grant = $this->workflow->grant($actor, $campaignCharacterId, (string) $data['budget_id'], (int) $data['amount'], $data['reason'] ?? null);

            return [201, ['id' => $grant->getKey(), 'budget_id' => $grant->budget_id, 'amount' => $grant->amount, 'reason' => $grant->reason, 'created_at' => $grant->created_at->toIso8601String()]];
        }));

        return new JsonResponse($body, $status);
    }

    /** @return array<string, mixed> */
    private function draftView(CharacterDraft $draft, ValidationResult $validation): array
    {
        return [
            'campaign_character_id' => $draft->campaign_character_id,
            'draft_version' => $draft->draft_version,
            'base_sheet_revision' => $draft->base_sheet_revision,
            'change_set' => json_decode((string) $draft->getRawOriginal('change_set'), false, 64, JSON_THROW_ON_ERROR),
            'validation' => $validation->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function requestView(ChangeRequest $changeRequest, CampaignCharacter $character, bool $asMaster, bool $withValidation = true): array
    {
        $layout = $this->changes->layout($character);
        $operations = ChangeOperation::query()->where('change_request_id', $changeRequest->getKey())->orderBy('position')->get();
        $decisions = OperationDecision::query()->where('change_request_id', $changeRequest->getKey())->get()->keyBy('change_operation_id');
        $sections = [];
        foreach ($operations as $row) {
            $decision = $decisions->get($row->getKey());
            $payload = (array) $row->payload;
            $sections[$row->section_id] ??= ['section_id' => $row->section_id, 'label' => $layout->sectionLabels[$row->section_id] ?? $row->section_id, 'operations' => []];
            $sections[$row->section_id]['operations'][] = [
                'id' => $row->operation_key,
                'type' => $row->type,
                'path' => $row->path,
                'section_id' => $row->section_id,
                'item_id' => $payload['item_id'] ?? null,
                'field' => $payload['field'] ?? null,
                'value' => $payload['value'] ?? null,
                'order' => $payload['order'] ?? null,
                'decision' => $decision === null ? null : [
                    'decision' => $decision->decision->value,
                    'reason' => $decision->reason,
                    'final' => $decision->is_final,
                ],
            ];
        }
        $view = [
            'id' => $changeRequest->getKey(),
            'campaign_character_id' => $changeRequest->campaign_character_id,
            'status' => $changeRequest->status->value,
            'base_sheet_revision' => $changeRequest->base_sheet_revision,
            'review_version' => $changeRequest->review_version,
            'resulting_sheet_revision' => $changeRequest->resulting_sheet_revision,
            'sections' => array_values($sections),
        ];
        if ($withValidation && $changeRequest->status->isActive()) {
            // Validation of the currently approved subset against the current official sheet.
            $approved = $operations->filter(fn (ChangeOperation $row): bool => $decisions->get($row->getKey())?->decision !== OperationDecisionValue::Rejected)
                ->map(fn (ChangeOperation $row): EngineOperation => EngineOperation::fromArray(['id' => $row->operation_key, 'type' => $row->type, 'path' => $row->path] + (array) $row->payload))
                ->values()->all();
            $author = User::query()->findOrFail($changeRequest->author_user_id);
            $result = $this->changes->evaluate($character, $approved, $this->changes->actorFor($author, $character))->result;
            $view['validation'] = $this->changes->filterFor($result, $character, $asMaster ? Actor::Master : Actor::Player)->toArray();
        }

        return $view;
    }

    /** Re-decodes one top-level field of the body as an object tree, preserving {} vs []. @return array<string, mixed> */
    private function rawObjectField(Request $request, string $field): array
    {
        $decoded = json_decode((string) $request->getContent(), true, 64, JSON_THROW_ON_ERROR);

        return (array) ($decoded[$field] ?? []);
    }

    private function visibleCharacter(Request $request, string $campaignCharacterId): CampaignCharacter
    {
        $character = CampaignCharacter::query()->find($campaignCharacterId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $character);

        return $character;
    }

    /** @return array{0: ChangeRequest, 1: CampaignCharacter} */
    private function visibleRequest(Request $request, string $changeRequestId): array
    {
        $changeRequest = ChangeRequest::query()->find($changeRequestId) ?? throw ApiProblem::notFound();
        if (!Gate::forUser($this->actor($request))->allows('view', $changeRequest)) {
            throw ApiProblem::notFound();
        }

        return [$changeRequest, CampaignCharacter::query()->findOrFail($changeRequest->campaign_character_id)];
    }
}
