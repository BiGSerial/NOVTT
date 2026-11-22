<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AuditTrail;
use App\Http\Problems\ApiProblem;
use App\Http\Resources\CharacterPresenter;
use App\Infrastructure\Validation\JsonDocument;
use App\Models\LibraryCharacter;
use App\Models\LibraryNpc;
use App\Models\PresetVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/** Personal character library and NPC library: private to their owner. */
final class LibraryController extends ApiController
{
    public function __construct(private readonly AuditTrail $audit)
    {
    }

    public function characters(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $archived = filter_var($request->query('archived', 'false'), FILTER_VALIDATE_BOOL);
        $page = LibraryCharacter::query()
            ->where('owner_user_id', $this->actor($request)->getKey())
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('id')
            ->cursorPaginate($limit);

        return new JsonResponse([
            'data' => array_map(CharacterPresenter::library(...), $page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function storeCharacter(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->input($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'preset_version_id' => ['sometimes', 'uuid'],
        ]);
        if (isset($data['preset_version_id'])) {
            $this->visibleVersion($request, (string) $data['preset_version_id']);
        }
        $character = DB::transaction(function () use ($actor, $data): LibraryCharacter {
            $character = LibraryCharacter::query()->create([
                'owner_user_id' => $actor->getKey(),
                'preset_version_id' => $data['preset_version_id'] ?? null,
                'name' => $data['name'],
            ]);
            $this->audit->record($actor, null, 'library_character.created', $character);

            return $character->refresh();
        });

        return new JsonResponse(CharacterPresenter::library($character), 201);
    }

    public function showCharacter(Request $request, string $characterId): JsonResponse
    {
        return new JsonResponse(CharacterPresenter::library($this->ownCharacter($request, $characterId)));
    }

    public function archiveCharacter(Request $request, string $characterId): JsonResponse
    {
        $character = $this->ownCharacter($request, $characterId);
        if ($character->archived_at === null) {
            DB::transaction(function () use ($request, $character): void {
                $character->update(['archived_at' => now()]);
                $this->audit->record($this->actor($request), null, 'library_character.archived', $character);
            });
        }

        return new JsonResponse(CharacterPresenter::library($character->refresh()));
    }

    /** Deleting a library entry never touches campaign instances or their history. */
    public function deleteCharacter(Request $request, string $characterId): Response
    {
        $character = $this->ownCharacter($request, $characterId);
        DB::transaction(function () use ($request, $character): void {
            $this->audit->record($this->actor($request), null, 'library_character.deleted', $character, ['name' => $character->name]);
            $character->delete();
        });

        return response()->noContent();
    }

    public function npcs(Request $request): JsonResponse
    {
        $npcs = LibraryNpc::query()->where('owner_user_id', $this->actor($request)->getKey())->whereNull('archived_at')->orderBy('id')->limit(200)->get();

        return new JsonResponse(['data' => $npcs->map(CharacterPresenter::libraryNpc(...))->all()]);
    }

    public function storeNpc(Request $request, StructuralValidator $validator): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $this->input($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'preset_version_id' => ['sometimes', 'uuid'],
            'sheet' => ['sometimes', 'array'],
            'private_notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        $sheet = $data['sheet'] ?? [];
        if ($sheet !== [] && !isset($data['preset_version_id'])) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('npc.sheet_requires_preset', Severity::Error, [], ['/sheet'])]));
        }
        $object = (object) [];
        if (isset($data['preset_version_id'])) {
            $version = $this->visibleVersion($request, (string) $data['preset_version_id']);
            $result = $validator->validate($sheet, $version->sheetSchemaObject());
            if (!$result->isValid()) {
                throw ApiProblem::validation($result);
            }
            $object = JsonDocument::toJsonValue($sheet, $version->sheetSchemaArray());
        }
        $npc = DB::transaction(function () use ($actor, $data, $object): LibraryNpc {
            $npc = LibraryNpc::query()->create([
                'owner_user_id' => $actor->getKey(),
                'preset_version_id' => $data['preset_version_id'] ?? null,
                'name' => $data['name'],
                'sheet' => $object,
                'private_notes' => $data['private_notes'] ?? null,
            ]);
            $this->audit->record($actor, null, 'library_npc.created', $npc);

            return $npc->refresh();
        });

        return new JsonResponse(CharacterPresenter::libraryNpc($npc), 201);
    }

    private function ownCharacter(Request $request, string $characterId): LibraryCharacter
    {
        return LibraryCharacter::query()->where('owner_user_id', $this->actor($request)->getKey())->find($characterId)
            ?? throw ApiProblem::notFound();
    }

    private function visibleVersion(Request $request, string $id): PresetVersion
    {
        $version = PresetVersion::query()->find($id);
        if ($version === null || !Gate::forUser($this->actor($request))->allows('view', $version)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('preset.unavailable', Severity::Error, [], ['/preset_version_id'])]), 'preset.unavailable');
        }

        return $version;
    }
}
