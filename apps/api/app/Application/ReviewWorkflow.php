<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CampaignCharacterStatus;
use App\Domain\ChangeRequestStatus;
use App\Domain\OperationDecisionValue;
use App\Http\Problems\ApiProblem;
use App\Infrastructure\Validation\ContractRegistry;
use App\Infrastructure\Validation\JsonDocument;
use App\Models\CampaignCharacter;
use App\Models\ChangeOperation as ChangeOperationRow;
use App\Models\ChangeRequest;
use App\Models\CharacterDraft;
use App\Models\CharacterGrant;
use App\Models\CharacterSheetRevision;
use App\Models\OperationDecision;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Novtt\Engine\Presets\SheetProjector;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Sheets\ChangeOperation;
use Novtt\Engine\Sheets\ChangeSet;
use Novtt\Engine\Sheets\OperationType;
use Novtt\Engine\Sheets\SheetChangeEngine;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Drafts, proposals, granular master review and atomic application (SPEC-SPRINT-001 rules 4–11).
 * Every mutating method runs in one transaction with a row lock on the campaign character, so
 * concurrent requests serialize; revisions and review versions are checked under that lock.
 */
final readonly class ReviewWorkflow
{
    public function __construct(
        private SheetChanges $changes,
        private StructuralValidator $structure,
        private AuditTrail $audit,
        private SheetViews $views,
    ) {
    }

    /**
     * @param array<string, mixed> $changeSet novtt.sheet-change-set/1
     * @return array{0: CharacterDraft, 1: DraftPreview}
     */
    public function saveDraft(User $actor, string $characterId, int $draftVersion, array $changeSet): array
    {
        $set = $this->parseChangeSet($changeSet);

        return DB::transaction(function () use ($actor, $characterId, $draftVersion, $set): array {
            $character = $this->lockCharacterFor($actor, $characterId, 'propose');
            $draft = CharacterDraft::query()->where(['campaign_character_id' => $character->getKey(), 'author_user_id' => $actor->getKey()])->lockForUpdate()->first();
            $current = $draft?->draft_version ?? 0;
            if ($draftVersion !== $current) {
                throw ApiProblem::conflict('draft.version_conflict', $current);
            }
            $attributes = ['base_sheet_revision' => $set->baseSheetRevision, 'draft_version' => $current + 1, 'change_set' => JsonDocument::toJsonValue($set->toArray(), $this->changeSetSchema())];
            $draft = $draft === null
                ? CharacterDraft::query()->create($attributes + ['campaign_character_id' => $character->getKey(), 'author_user_id' => $actor->getKey()])
                : tap($draft)->update($attributes);

            return [$draft->refresh(), $this->preview($actor, $character, $set)];
        });
    }

    /**
     * Validation of a draft against the current official sheet, filtered for the author, with the
     * balance left per budget. Balances are already visible to anyone who can see the character
     * (GET …/grants); budgets are not sheet fields, so no private field is revealed.
     */
    public function preview(User $actor, CampaignCharacter $character, ChangeSet $set): DraftPreview
    {
        $role = $this->changes->actorFor($actor, $character);
        $outcome = $this->changes->evaluate($character, $set->operations, $role);
        $result = $outcome->result;
        if ($set->baseSheetRevision !== $character->sheet_revision) {
            $result = $result->merge(new ValidationResult([new ValidationIssue('draft.stale', Severity::Warning, [], [], ['current_revision' => $character->sheet_revision])]));
        }

        return new DraftPreview($this->changes->filterFor($result, $character, $role), $outcome->balances);
    }

    public function submit(User $actor, string $characterId, int $draftVersion): ChangeRequest
    {
        $character = $this->lockCharacterFor($actor, $characterId, 'propose');
        $draft = CharacterDraft::query()->where(['campaign_character_id' => $character->getKey(), 'author_user_id' => $actor->getKey()])->lockForUpdate()->first()
            ?? throw ApiProblem::conflict('draft.missing');
        if ($draft->draft_version !== $draftVersion) {
            throw ApiProblem::conflict('draft.version_conflict', $draft->draft_version);
        }
        $set = $this->parseChangeSet((array) $draft->change_set);
        if ($set->operations === []) {
            throw ApiProblem::validation(SheetChanges::error('change_request.empty'));
        }
        if ($set->baseSheetRevision !== $character->sheet_revision) {
            throw ApiProblem::conflict('draft.stale', $character->sheet_revision, 'Rebase the draft before submitting.');
        }
        $role = $this->changes->actorFor($actor, $character);
        $outcome = $this->changes->evaluate($character, $set->operations, $role);
        if (!$outcome->result->isValid()) {
            throw ApiProblem::validation($this->changes->filterFor($outcome->result, $character, $role));
        }

        try {
            $request = DB::transaction(fn (): ChangeRequest => ChangeRequest::query()->create([
                'campaign_character_id' => $character->getKey(),
                'author_user_id' => $actor->getKey(),
                'status' => ChangeRequestStatus::Submitted,
                'base_sheet_revision' => $set->baseSheetRevision,
                'draft_version' => $draft->draft_version,
                'idempotency_key' => (string) Str::uuid7(),
                'payload_hash' => $set->hash(),
                'submitted_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::conflict('change_request.active_exists');
        }
        foreach ($set->operations as $position => $op) {
            ChangeOperationRow::query()->create([
                'change_request_id' => $request->getKey(),
                'operation_key' => $op->key,
                'position' => $position,
                'type' => $op->type->value,
                'path' => $op->path->toString(),
                'section_id' => $this->changes->sectionOf($character, $op),
                'payload' => (object) array_diff_key($op->toArray(), array_flip(['id', 'type', 'path'])),
                'created_at' => now(),
            ]);
        }
        $draft->delete();
        if ($character->status === CampaignCharacterStatus::Draft) {
            $character->update(['status' => CampaignCharacterStatus::PendingApproval]);
        }
        $this->audit->record($actor, $character->campaign_id, 'change_request.submitted', $request, ['operations' => count($set->operations), 'base_sheet_revision' => $set->baseSheetRevision]);
        $this->audit->emit($character->campaign_id, 'change_request.submitted', $request, ['change_request_id' => $request->getKey(), 'campaign_character_id' => $character->getKey()]);

        return $request->refresh();
    }

    /**
     * @param list<array{operation_ids: list<string>, decision: string, reason?: string, override?: bool}> $decisions
     */
    public function decide(User $actor, string $requestId, int $reviewVersion, array $decisions): ChangeRequest
    {
        return DB::transaction(function () use ($actor, $requestId, $reviewVersion, $decisions): ChangeRequest {
            $request = $this->lockActiveRequestForReview($actor, $requestId, $reviewVersion);
            $operations = ChangeOperationRow::query()->where('change_request_id', $request->getKey())->get()->keyBy('operation_key');
            $existing = OperationDecision::query()->where('change_request_id', $request->getKey())->get()->keyBy('change_operation_id');

            foreach ($decisions as $entry) {
                $decision = OperationDecisionValue::from($entry['decision']);
                $rows = [];
                foreach ($entry['operation_ids'] as $key) {
                    $rows[] = $operations->get($key) ?? throw ApiProblem::validation(SheetChanges::error('decision.unknown_operation', '/decisions'));
                }
                if (count(array_unique(array_map(static fn ($r): string => $r->section_id, $rows))) > 1) {
                    throw ApiProblem::validation(SheetChanges::error('decision.batch_cross_section', '/decisions'));
                }
                foreach ($rows as $row) {
                    $previous = $existing->get($row->getKey());
                    // A batch approval never overwrites a rejection: that needs an individual override.
                    if ($decision === OperationDecisionValue::Approved && $previous?->decision === OperationDecisionValue::Rejected && empty($entry['override'])) {
                        throw ApiProblem::conflict('decision.rejection_requires_individual_override');
                    }
                    $values = [
                        'decision' => $decision,
                        'reason' => $decision === OperationDecisionValue::Rejected ? trim((string) $entry['reason']) : null,
                        'review_version' => $reviewVersion + 1,
                        'decided_by' => $actor->getKey(),
                        'decided_at' => now(),
                    ];
                    $previous === null
                        ? OperationDecision::query()->create($values + ['change_operation_id' => $row->getKey(), 'change_request_id' => $request->getKey()])
                        : $previous->update($values);
                }
            }
            $decided = OperationDecision::query()->where('change_request_id', $request->getKey())->count();
            $request->update([
                'review_version' => $reviewVersion + 1,
                'status' => $decided === $operations->count() ? ChangeRequestStatus::AwaitingResolution : ChangeRequestStatus::InReview,
            ]);
            $this->audit->record($actor, $this->campaignOf($request), 'change_request.decided', $request, ['review_version' => $reviewVersion + 1, 'entries' => count($decisions)]);

            return $request->refresh();
        });
    }

    /**
     * Applies the approved subset atomically, or nothing. Returns the request and, when the
     * approved set is invalid, the (master-facing) validation result instead of throwing so the
     * caller can report it while nothing changed.
     */
    public function finalize(User $actor, string $requestId, int $reviewVersion): ChangeRequest
    {
        $request = $this->lockActiveRequestForReview($actor, $requestId, $reviewVersion);
        $character = CampaignCharacter::query()->lockForUpdate()->findOrFail($request->campaign_character_id);
        $operations = ChangeOperationRow::query()->where('change_request_id', $request->getKey())->orderBy('position')->get();
        $decisions = OperationDecision::query()->where('change_request_id', $request->getKey())->get()->keyBy('change_operation_id');
        if ($decisions->count() !== $operations->count()) {
            throw ApiProblem::conflict('decision.incomplete');
        }
        if ($character->sheet_revision !== $request->base_sheet_revision) {
            throw ApiProblem::conflict('change_request.stale', $character->sheet_revision);
        }

        $approved = $operations->filter(fn (ChangeOperationRow $row): bool => $decisions[$row->getKey()]->decision === OperationDecisionValue::Approved)
            ->map(fn (ChangeOperationRow $row): ChangeOperation => self::rowToOperation($row))
            ->values()
            ->all();

        $finalized = ['finalized_by' => $actor->getKey(), 'finalized_at' => now()];

        if ($approved === []) {
            // Rejecting everything creates no new revision.
            OperationDecision::query()->where('change_request_id', $request->getKey())->update(['is_final' => true]);
            $request->update($finalized + ['status' => ChangeRequestStatus::Rejected]);
            $this->audit->record($actor, $character->campaign_id, 'change_request.rejected', $request);
            $this->audit->emit($character->campaign_id, 'change_request.finalized', $request, ['change_request_id' => $request->getKey(), 'status' => 'rejected']);

            return $request->refresh();
        }

        // The author's authority is rechecked now: a proposal never gains rights it lost.
        $author = User::query()->findOrFail($request->author_user_id);
        $outcome = $this->changes->evaluate($character, $approved, $this->changes->actorFor($author, $character));
        if (!$outcome->result->isValid()) {
            throw ApiProblem::validation($outcome->result, 'change_request.approved_set_invalid');
        }

        // Validated: from here on everything commits together or not at all.
        OperationDecision::query()->where('change_request_id', $request->getKey())->update(['is_final' => true]);
        $version = PresetVersion::query()->findOrFail($character->preset_version_id);
        $sheet = self::toObject($outcome->sheet, $version->sheetSchemaArray());
        $revision = $character->sheet_revision + 1;
        $character->update(['sheet' => $sheet, 'sheet_revision' => $revision]);
        CharacterSheetRevision::query()->create([
            'campaign_character_id' => $character->getKey(),
            'revision' => $revision,
            'sheet' => $sheet,
            'change_request_id' => $request->getKey(),
            'created_by' => $actor->getKey(),
            'created_at' => now(),
        ]);
        $request->update($finalized + ['status' => ChangeRequestStatus::Applied, 'resulting_sheet_revision' => $revision]);
        $this->audit->record($actor, $character->campaign_id, 'change_request.applied', $request, [
            'approved' => count($approved), 'rejected' => $operations->count() - count($approved), 'sheet_revision' => $revision,
        ]);
        // No sheet content in the event: consumers fetch the filtered projection.
        $this->audit->emit($character->campaign_id, 'character.sheet_revised', $character, ['campaign_character_id' => $character->getKey(), 'sheet_revision' => $revision]);

        return $request->refresh();
    }

    public function withdraw(User $actor, string $requestId): ChangeRequest
    {
        return DB::transaction(function () use ($actor, $requestId): ChangeRequest {
            $request = ChangeRequest::query()->lockForUpdate()->find($requestId) ?? throw ApiProblem::notFound();
            if (!Gate::forUser($actor)->allows('author', $request)) {
                throw ApiProblem::notFound();
            }
            if (!$request->status->isActive()) {
                throw ApiProblem::conflict('change_request.not_active');
            }
            $request->update(['status' => ChangeRequestStatus::Withdrawn]);
            $this->audit->record($actor, $this->campaignOf($request), 'change_request.withdrawn', $request);

            return $request->refresh();
        });
    }

    /**
     * Three-way comparison (base snapshot, current official sheet, draft operations). No mutation.
     *
     * @return array<string, mixed>
     */
    public function rebasePreview(User $actor, string $characterId): array
    {
        return DB::transaction(function () use ($actor, $characterId): array {
            $character = $this->lockCharacterFor($actor, $characterId, 'propose');
            $draft = $this->draftOf($actor, $character);

            return $this->compare($actor, $character, $draft);
        });
    }

    /**
     * @param list<array{operation_id: string, resolution: string}> $resolutions
     * @return array{0: CharacterDraft, 1: DraftPreview}
     */
    public function rebaseConfirm(User $actor, string $characterId, string $token, array $resolutions): array
    {
        return DB::transaction(function () use ($actor, $characterId, $token, $resolutions): array {
            $character = $this->lockCharacterFor($actor, $characterId, 'propose');
            $draft = $this->draftOf($actor, $character);
            $preview = $this->compare($actor, $character, $draft);
            if (!hash_equals($preview['preview_token'], $token)) {
                throw ApiProblem::conflict('rebase.preview_outdated', $character->sheet_revision);
            }
            $byOp = [];
            foreach ($resolutions as $resolution) {
                $byOp[$resolution['operation_id']] = $resolution['resolution'];
            }
            $set = $this->parseChangeSet((array) $draft->change_set);
            $conflicts = array_column($preview['conflicts'], null, 'operation_id');
            foreach (array_keys($conflicts) as $opId) {
                if (!isset($byOp[$opId])) {
                    throw ApiProblem::validation(SheetChanges::error('rebase.resolution_missing', '/resolutions'));
                }
            }
            $kept = [];
            foreach ($set->operations as $op) {
                $resolution = $byOp[$op->key] ?? 'keep_mine';
                if (!isset($conflicts[$op->key]) || $resolution === 'keep_mine') {
                    // Re-anchor the precondition on the value the author has now seen.
                    if (isset($conflicts[$op->key]) && in_array($op->type, [OperationType::SetField, OperationType::UnsetField], true)) {
                        [$exists, $current] = SheetProjector::read((array) $character->sheet, $op->path->segments);
                        $op = ChangeOperation::fromArray(['expected' => $exists ? $current : null] + $op->toArray());
                    }
                    $kept[] = $op;
                }
            }
            $rebased = new ChangeSet($character->sheet_revision, $kept);
            $draft->update(['base_sheet_revision' => $character->sheet_revision, 'draft_version' => $draft->draft_version + 1, 'change_set' => JsonDocument::toJsonValue($rebased->toArray(), $this->changeSetSchema())]);
            $this->audit->record($actor, $character->campaign_id, 'draft.rebased', $draft, ['to_revision' => $character->sheet_revision, 'dropped' => count($set->operations) - count($kept)]);

            return [$draft->refresh(), $this->preview($actor, $character, $rebased)];
        });
    }

    public function approveCharacter(User $actor, string $characterId): CampaignCharacter
    {
        return DB::transaction(function () use ($actor, $characterId): CampaignCharacter {
            $character = $this->lockCharacterFor($actor, $characterId, 'master');
            if ($character->status === CampaignCharacterStatus::Approved) {
                return $character;
            }
            if ($character->sheet_revision < 1) {
                throw ApiProblem::conflict('character.no_applied_sheet');
            }
            if (ChangeRequest::query()->where('campaign_character_id', $character->getKey())->whereIn('status', ChangeRequestStatus::ACTIVE)->exists()) {
                throw ApiProblem::conflict('character.active_proposal');
            }
            $character->update(['status' => CampaignCharacterStatus::Approved, 'approved_by' => $actor->getKey(), 'approved_at' => now()]);
            $this->audit->record($actor, $character->campaign_id, 'campaign_character.approved', $character, ['sheet_revision' => $character->sheet_revision]);
            $this->audit->emit($character->campaign_id, 'campaign_character.approved', $character, ['campaign_character_id' => $character->getKey()]);

            return $character->refresh();
        });
    }

    public function grant(User $actor, string $characterId, string $budgetId, int $amount, ?string $reason): CharacterGrant
    {
        $character = $this->lockCharacterFor($actor, $characterId, 'master');
        $budgets = array_column((array) PresetVersion::query()->findOrFail($character->preset_version_id)->rules['budgets'], 'id');
        if (!in_array($budgetId, $budgets, true)) {
            throw ApiProblem::validation(SheetChanges::error('grant.unknown_budget', '/budget_id'));
        }
        $balances = $this->changes->engine($character->preset_version_id)->balances((array) $character->sheet, $this->changes->grants($character));
        if (($balances[$budgetId] ?? 0) + $amount < 0) {
            throw ApiProblem::validation(SheetChanges::error('grant.would_overdraw', '/amount'));
        }
        $grant = CharacterGrant::query()->create([
            'campaign_character_id' => $character->getKey(),
            'budget_id' => $budgetId,
            'amount' => $amount,
            'reason' => $reason,
            'granted_by' => $actor->getKey(),
            'created_at' => now(),
        ]);
        $this->audit->record($actor, $character->campaign_id, 'grant.created', $grant, ['budget_id' => $budgetId, 'amount' => $amount]);

        return $grant;
    }

    /** @return array<string, mixed> */
    private function compare(User $actor, CampaignCharacter $character, CharacterDraft $draft): array
    {
        $set = $this->parseChangeSet((array) $draft->change_set);
        $base = $set->baseSheetRevision === 0 ? [] : (array) (CharacterSheetRevision::query()
            ->where(['campaign_character_id' => $character->getKey(), 'revision' => $set->baseSheetRevision])
            ->value('sheet') ?? []);
        $current = (array) $character->sheet;
        $rejected = $this->previouslyRejected($character);
        $role = $this->changes->actorFor($actor, $character);
        $projector = $this->views->projector($character->preset_version_id);
        $conflicts = [];
        foreach ($set->operations as $op) {
            $segments = $op->path->segments;
            [$hadBase, $baseValue] = SheetProjector::read($base, $segments);
            [$hasCurrent, $currentValue] = SheetProjector::read($current, $segments);
            $kind = null;
            if ($op->type !== OperationType::AddItem && $op->type !== OperationType::SetField && $op->type !== OperationType::UnsetField && $hadBase && !$hasCurrent) {
                $kind = 'target_removed';
            } elseif ($op->itemId !== null && $op->type !== OperationType::AddItem && self::hasItem($baseValue, $op->itemId) && !self::hasItem($currentValue, $op->itemId)) {
                $kind = 'item_removed';
            } elseif (CanonicalJson::encode($hadBase ? $baseValue : null) !== CanonicalJson::encode($hasCurrent ? $currentValue : null)) {
                $kind = 'value_changed';
            } elseif (isset($rejected[self::fingerprint($op)])) {
                $kind = 'previously_rejected';
            }
            if ($kind === null) {
                continue;
            }
            $visible = $role === Actor::Master || $projector->playerCanRead($op->path);
            $conflicts[] = [
                'operation_id' => $op->key,
                'kind' => $kind,
                'path' => $op->path->toString(),
                'base' => $visible && $hadBase ? $baseValue : null,
                'current' => $visible && $hasCurrent ? $currentValue : null,
                'mine' => $op->value,
            ];
        }

        return [
            'preview_token' => hash_hmac('sha256', implode('|', [$draft->getKey(), $draft->draft_version, $character->sheet_revision]), (string) config('app.key')),
            'base_sheet_revision' => $set->baseSheetRevision,
            'current_sheet_revision' => $character->sheet_revision,
            'conflicts' => $conflicts,
        ];
    }

    /** Fingerprints of operations rejected in finalized requests of this character. @return array<string, true> */
    private function previouslyRejected(CampaignCharacter $character): array
    {
        $rows = ChangeOperationRow::query()
            ->join('operation_decisions', 'operation_decisions.change_operation_id', '=', 'change_operations.id')
            ->join('change_requests', 'change_requests.id', '=', 'change_operations.change_request_id')
            ->where('change_requests.campaign_character_id', $character->getKey())
            ->where('operation_decisions.decision', OperationDecisionValue::Rejected)
            ->where('operation_decisions.is_final', true)
            ->select('change_operations.*')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[self::fingerprint(self::rowToOperation($row))] = true;
        }

        return $out;
    }

    private static function fingerprint(ChangeOperation $op): string
    {
        $data = $op->toArray();
        unset($data['id'], $data['expected']);

        return hash('sha256', CanonicalJson::encode($data));
    }

    private static function hasItem(mixed $collection, string $itemId): bool
    {
        if (!is_array($collection)) {
            return false;
        }
        foreach ($collection as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $itemId) {
                return true;
            }
        }

        return false;
    }

    private static function rowToOperation(ChangeOperationRow $row): ChangeOperation
    {
        return ChangeOperation::fromArray(['id' => $row->operation_key, 'type' => $row->type, 'path' => $row->path] + (array) $row->payload);
    }

    /** @param array<string, mixed> $changeSet */
    private function parseChangeSet(array $changeSet): ChangeSet
    {
        $result = $this->structure->validateContract($changeSet, ChangeSet::CONTRACT);
        if (!$result->isValid()) {
            throw ApiProblem::validation(new ValidationResult(array_map(
                static fn (ValidationIssue $i): ValidationIssue => new ValidationIssue($i->ruleId, $i->severity, [], array_map(static fn (string $p): string => '/change_set' . $p, $i->paths), $i->params),
                $result->issues,
            )), 'change_set.invalid');
        }
        try {
            return ChangeSet::fromArray($changeSet);
        } catch (InvalidArgumentException $e) {
            throw ApiProblem::validation(SheetChanges::error($e->getMessage(), '/change_set'), 'change_set.invalid');
        }
    }

    /** @return array<string, mixed> */
    private function changeSetSchema(): array
    {
        return app(ContractRegistry::class)->schema(ChangeSet::CONTRACT);
    }

    /** @param array<string, mixed> $sheet @param array<string, mixed> $schema */
    private static function toObject(array $sheet, array $schema): object
    {
        $ledger = $sheet[SheetChangeEngine::LEDGER] ?? null;
        unset($sheet[SheetChangeEngine::LEDGER]);
        $object = JsonDocument::toJsonValue($sheet, $schema);
        $object = is_object($object) ? $object : (object) [];
        if ($ledger !== null) {
            $object->{SheetChangeEngine::LEDGER} = $ledger;
        }

        return $object;
    }

    private function lockCharacterFor(User $actor, string $characterId, string $ability): CampaignCharacter
    {
        $character = CampaignCharacter::query()->lockForUpdate()->find($characterId) ?? throw ApiProblem::notFound();
        if (!Gate::forUser($actor)->allows('view', $character) && !$this->changes->isMaster($actor, $character->campaign_id)) {
            throw ApiProblem::notFound();
        }
        if (!Gate::forUser($actor)->allows($ability, $character)) {
            // Master authority is not advertised to players (404); a viewer who is simply not in
            // control right now (custody, archived) gets an explicit conflict.
            $visible = $ability !== 'master' || $this->changes->isMaster($actor, $character->campaign_id);
            throw $visible && Gate::forUser($actor)->allows('view', $character) ? ApiProblem::conflict('character.not_editable_by_actor') : ApiProblem::notFound();
        }

        return $character;
    }

    private function lockActiveRequestForReview(User $actor, string $requestId, int $reviewVersion): ChangeRequest
    {
        $request = ChangeRequest::query()->lockForUpdate()->find($requestId) ?? throw ApiProblem::notFound();
        if (!Gate::forUser($actor)->allows('review', $request)) {
            throw ApiProblem::notFound();
        }
        if (!$request->status->isActive()) {
            throw ApiProblem::conflict('change_request.not_active');
        }
        if ($request->review_version !== $reviewVersion) {
            throw ApiProblem::conflict('change_request.review_version_conflict', $request->review_version);
        }

        return $request;
    }

    private function draftOf(User $actor, CampaignCharacter $character): CharacterDraft
    {
        return CharacterDraft::query()->where(['campaign_character_id' => $character->getKey(), 'author_user_id' => $actor->getKey()])->lockForUpdate()->first()
            ?? throw ApiProblem::notFound('draft.not_found');
    }

    private function campaignOf(ChangeRequest $request): string
    {
        return (string) CampaignCharacter::query()->whereKey($request->campaign_character_id)->value('campaign_id');
    }
}
