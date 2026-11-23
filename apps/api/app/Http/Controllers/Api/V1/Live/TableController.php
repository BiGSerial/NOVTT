<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\AuditTrail;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Audience;
use App\Live\LiveViews;
use App\Live\SessionAccess;
use App\Live\SessionStream;
use App\Live\SheetCombination;
use App\Live\TableSnapshot;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\Clue;
use App\Models\GameSession;
use App\Models\LiveState;
use App\Models\MasterNote;
use App\Models\RollSuggestion;
use App\Models\User;
use App\Models\Whisper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Live table records (SPEC-MVP-002 LT-05, LT-06): resources and conditions, master notes,
 * whispers, clues, NPC reveals and player suggestions. Every change is persisted first and then
 * projected to the audiences allowed to see it.
 */
final class TableController extends ApiController
{
    public function __construct(private readonly SessionAccess $access, private readonly SessionStream $stream, private readonly AuditTrail $audit) {}

    // ---- Resources and conditions (master edits; PCs visible to the table, NPCs to masters) ----

    public function saveLiveState(Request $request, string $campaignId, string $kind, string $subjectId): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->requireMaster($actor, $campaignId);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:0'],
            'resources' => ['present', 'array', 'max:8'],
            'resources.*.id' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,32}$/'],
            'resources.*.label' => ['required', 'string', 'min:1', 'max:40'],
            'resources.*.max' => ['required', 'integer', 'min:1', 'max:999'],
            'resources.*.current' => ['required', 'integer', 'min:-99', 'max:999'],
            'conditions' => ['present', 'array', 'max:12'],
            'conditions.*' => ['string', 'min:1', 'max:40', 'distinct'],
        ]);
        $exists = Str::isUuid($subjectId) && ($kind === 'character'
            ? CampaignCharacter::query()->where('campaign_id', $campaignId)->whereKey($subjectId)->exists()
            : CampaignNpc::query()->where('campaign_id', $campaignId)->whereKey($subjectId)->exists());
        if (! $exists) {
            throw ApiProblem::notFound();
        }
        $resources = array_map(static fn (array $r): array => ['id' => $r['id'], 'label' => $r['label'], 'current' => min((int) $r['current'], (int) $r['max']), 'max' => (int) $r['max']], $data['resources']);
        $conditions = array_values($data['conditions']);

        $state = DB::transaction(function () use ($actor, $campaignId, $kind, $subjectId, $data, $resources, $conditions): LiveState {
            $session = $this->access->lockOpenForCampaign($campaignId);
            $state = LiveState::query()->where(['subject_kind' => $kind, 'subject_id' => $subjectId])->lockForUpdate()->first();
            if (($state?->revision ?? 0) !== $data['revision']) {
                throw ApiProblem::conflict('live_state.revision_mismatch', $state?->revision ?? 0);
            }
            if ($state === null) {
                $state = LiveState::query()->create(['campaign_id' => $campaignId, 'subject_kind' => $kind, 'subject_id' => $subjectId, 'resources' => $resources, 'conditions' => $conditions, 'revision' => 1]);
            } else {
                $state->update(['resources' => $resources, 'conditions' => $conditions, 'revision' => $state->revision + 1]);
            }
            $this->audit->record($actor, $campaignId, 'live_state.updated', $state, ['subject_kind' => $kind]);
            if ($session !== null) {
                $this->stream->append($session, 'live.updated', ['subject' => ['kind' => $kind, 'id' => $subjectId], 'live' => TableSnapshot::live($state)], $kind === 'character' ? Audience::table() : Audience::master());
            }

            return $state;
        });

        return new JsonResponse(['subject' => ['kind' => $kind, 'id' => $subjectId], 'live' => TableSnapshot::live($state)]);
    }

    // ---- Master notes (SPEC-002 MW-08) ----

    public function notes(Request $request, string $campaignId): JsonResponse
    {
        $this->access->requireMaster($this->actor($request), $campaignId);

        return new JsonResponse(['data' => MasterNote::query()->where('campaign_id', $campaignId)->whereNull('deleted_at')
            ->orderByDesc('pinned')->orderByDesc('updated_at')->limit(200)->get()->map(static fn (MasterNote $n): array => LiveViews::note($n))->values()]);
    }

    public function createNote(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->requireMaster($actor, $campaignId);
        $data = $this->input($request, self::noteRules(false));
        $note = DB::transaction(function () use ($actor, $campaignId, $data): MasterNote {
            $session = $this->access->lockOpenForCampaign($campaignId);
            $note = MasterNote::query()->create([
                'campaign_id' => $campaignId, 'session_id' => $session?->getKey(), 'author_user_id' => $actor->getKey(),
                'body' => $data['body'], 'tags' => self::tags($data['tags'] ?? []), 'pinned' => (bool) ($data['pinned'] ?? false), 'revision' => 1,
            ]);
            $this->noteEvent($session, $note, 'note.saved');

            return $note;
        });

        return new JsonResponse(LiveViews::note($note), 201);
    }

    public function updateNote(Request $request, string $noteId): JsonResponse
    {
        $actor = $this->actor($request);
        $note = Str::isUuid($noteId) ? MasterNote::query()->whereNull('deleted_at')->find($noteId) : null;
        if ($note === null || $this->access->role($actor, $note->campaign_id) !== CampaignRole::Master) {
            throw ApiProblem::notFound();
        }
        $data = $this->input($request, self::noteRules(true));
        $note = DB::transaction(function () use ($noteId, $data): MasterNote {
            $note = MasterNote::query()->lockForUpdate()->findOrFail($noteId);
            $session = $this->access->lockOpenForCampaign($note->campaign_id);
            if ($note->revision !== $data['revision']) {
                throw ApiProblem::conflict('note.revision_mismatch', $note->revision);
            }
            $note->update([
                'body' => $data['body'] ?? $note->body,
                'tags' => array_key_exists('tags', $data) ? self::tags($data['tags']) : $note->tags,
                'pinned' => array_key_exists('pinned', $data) ? (bool) $data['pinned'] : $note->pinned,
                'revision' => $note->revision + 1,
            ]);
            $this->noteEvent($session, $note, 'note.saved');

            return $note;
        });

        return new JsonResponse(LiveViews::note($note));
    }

    public function deleteNote(Request $request, string $noteId): JsonResponse
    {
        $actor = $this->actor($request);
        $note = Str::isUuid($noteId) ? MasterNote::query()->whereNull('deleted_at')->find($noteId) : null;
        if ($note === null || $this->access->role($actor, $note->campaign_id) !== CampaignRole::Master) {
            throw ApiProblem::notFound();
        }
        DB::transaction(function () use ($noteId): void {
            $note = MasterNote::query()->lockForUpdate()->findOrFail($noteId);
            $session = $this->access->lockOpenForCampaign($note->campaign_id);
            $note->update(['deleted_at' => now(), 'revision' => $note->revision + 1]);
            $session && $this->stream->append($session, 'note.deleted', ['id' => $note->getKey()], Audience::master());
        });

        return new JsonResponse(null, 204);
    }

    // ---- Whispers: master ↔ one player, never the table ----

    public function whisper(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->access->memberRole($actor, $campaignId);
        $data = $this->input($request, [
            'recipient_user_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        $recipientRole = $this->access->role(User::query()->find($data['recipient_user_id']) ?? new User, $campaignId);
        // Masters whisper to one player; players answer the masters. Nobody whispers to themselves.
        $allowed = $data['recipient_user_id'] !== $actor->getKey() && match ($role) {
            CampaignRole::Master => $recipientRole === CampaignRole::Player,
            CampaignRole::Player => $recipientRole === CampaignRole::Master,
        };
        if (! $allowed) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('whisper.recipient_invalid', Severity::Error, [], ['/recipient_user_id'])]), 'whisper.recipient_invalid');
        }
        $whisper = DB::transaction(fn (): Whisper => $this->sendWhisper($actor, $campaignId, $data['recipient_user_id'], 'message', $data['body'], $role));

        return new JsonResponse(LiveViews::whisper($whisper), 201);
    }

    // ---- Clues: reveal to the table or whisper to one player ----

    public function createClue(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'body' => ['present', 'string', 'max:2000'],
            'scene' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $clue = DB::transaction(function () use ($sessionId, $data): Clue {
            $session = $this->access->lockOpen($sessionId);
            $clue = Clue::query()->create(['campaign_id' => $session->campaign_id, 'session_id' => $session->getKey(), 'scene' => $data['scene'] ?? null, 'title' => $data['title'], 'body' => $data['body']]);
            $this->stream->append($session, 'clue.saved', LiveViews::clue($clue, true), Audience::master());

            return $clue;
        });

        return new JsonResponse(LiveViews::clue($clue, true), 201);
    }

    public function revealClue(Request $request, string $clueId): JsonResponse
    {
        $actor = $this->actor($request);
        $clue = Str::isUuid($clueId) ? Clue::query()->find($clueId) : null;
        if ($clue === null || $clue->session_id === null || $this->access->role($actor, $clue->campaign_id) !== CampaignRole::Master) {
            throw ApiProblem::notFound();
        }
        $data = $this->input($request, [
            'to' => ['required', 'in:table,user'],
            'user_id' => ['required_if:to,user', 'nullable', 'uuid'],
        ]);
        if ($data['to'] === 'user' && $this->access->role(User::query()->find($data['user_id']) ?? new User, $clue->campaign_id) !== CampaignRole::Player) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('whisper.recipient_invalid', Severity::Error, [], ['/user_id'])]), 'whisper.recipient_invalid');
        }
        $clue = DB::transaction(function () use ($actor, $clueId, $data): Clue {
            $clue = Clue::query()->lockForUpdate()->findOrFail($clueId);
            $session = $this->access->lockOpen((string) $clue->session_id);
            if ($data['to'] === 'table') {
                $clue->update(['revealed_to' => 'table', 'revealed_user_id' => null, 'revealed_at' => now()]);
                $this->stream->append($session, 'clue.revealed', LiveViews::clue($clue, false), Audience::table());
                $this->stream->append($session, 'clue.saved', LiveViews::clue($clue, true), Audience::master());
            } else {
                // A clue whispered to a player stays hidden from the rest of the table.
                if ($clue->revealed_to !== 'table') {
                    $clue->update(['revealed_to' => 'user', 'revealed_user_id' => $data['user_id'], 'revealed_at' => now()]);
                }
                $this->sendWhisper($actor, $clue->campaign_id, $data['user_id'], 'clue', trim($clue->title."\n\n".$clue->body), CampaignRole::Master, $session);
                $this->stream->append($session, 'clue.saved', LiveViews::clue($clue, true), Audience::master());
            }
            $this->audit->record($actor, $clue->campaign_id, 'clue.revealed', $clue, ['to' => $data['to']]);

            return $clue;
        });

        return new JsonResponse(LiveViews::clue($clue, true));
    }

    // ---- NPC reveal: name and a public line, never numbers or secrets ----

    public function revealNpc(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'campaign_npc_id' => ['required', 'uuid'],
            'summary' => ['present', 'string', 'max:300'],
        ]);
        $npc = CampaignNpc::query()->where('campaign_id', $session->campaign_id)->find($data['campaign_npc_id']) ?? throw ApiProblem::notFound();
        $entry = ['id' => $npc->getKey(), 'name' => $npc->name, 'summary' => $data['summary']];
        $session = DB::transaction(function () use ($sessionId, $entry): GameSession {
            $session = $this->access->lockOpen($sessionId);
            $revealed = array_values(array_filter($session->revealed_npcs ?? [], static fn (array $n): bool => $n['id'] !== $entry['id']));
            $revealed[] = $entry;
            $session->revealed_npcs = array_slice($revealed, -20);
            $this->stream->append($session, 'npc.revealed', $entry, Audience::table());

            return $session;
        });

        return new JsonResponse(['revealed_npcs' => $session->revealed_npcs]);
    }

    // ---- Player suggestions (D1): the master turns them into requests ----

    public function suggest(Request $request, string $sessionId, SheetCombination $combination): JsonResponse
    {
        $actor = $this->actor($request);
        [$session, $role] = $this->access->session($actor, $sessionId);
        $data = $this->input($request, [
            'campaign_character_id' => ['required', 'uuid'],
            'paths' => ['required', 'array', 'min:1', 'max:4'],
            'paths.*' => ['string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $character = CampaignCharacter::query()->where('campaign_id', $session->campaign_id)->find($data['campaign_character_id']);
        if ($character === null || ($character->custodian_user_id ?? $character->player_user_id) !== $actor->getKey()) {
            throw ApiProblem::notFound();
        }
        $combination->resolve($character->preset_version_id, [], array_values($data['paths']));
        $suggestion = DB::transaction(function () use ($actor, $sessionId, $character, $data): RollSuggestion {
            $session = $this->access->lockOpen($sessionId, requireActive: true);
            $pending = RollSuggestion::query()->where(['session_id' => $sessionId, 'player_user_id' => $actor->getKey(), 'status' => 'pending'])->count();
            if ($pending >= 3) {
                throw ApiProblem::conflict('suggestion.too_many');
            }
            $suggestion = RollSuggestion::query()->create([
                'session_id' => $sessionId, 'campaign_character_id' => $character->getKey(), 'player_user_id' => $actor->getKey(),
                'paths' => array_values($data['paths']), 'note' => $data['note'] ?? null, 'status' => 'pending',
            ]);
            $view = LiveViews::suggestion($suggestion, $character->name);
            $this->stream->append($session, 'suggestion.created', $view, Audience::master(), Audience::user($actor->getKey()));

            return $suggestion;
        });

        return new JsonResponse(LiveViews::suggestion($suggestion, $character->name), 201);
    }

    public function dismissSuggestion(Request $request, string $suggestionId): JsonResponse
    {
        $actor = $this->actor($request);
        $suggestion = Str::isUuid($suggestionId) ? RollSuggestion::query()->find($suggestionId) : null;
        if ($suggestion === null) {
            throw ApiProblem::notFound();
        }
        [$session] = $this->access->session($actor, $suggestion->session_id);
        $this->access->requireMaster($actor, $session->campaign_id);
        DB::transaction(function () use ($suggestionId): void {
            $suggestion = RollSuggestion::query()->lockForUpdate()->findOrFail($suggestionId);
            $session = $this->access->lockOpen($suggestion->session_id);
            if ($suggestion->status !== 'pending') {
                return;
            }
            $suggestion->update(['status' => 'dismissed']);
            $this->stream->append($session, 'suggestion.resolved', ['id' => $suggestion->getKey(), 'status' => 'dismissed', 'roll_request_id' => null], Audience::master(), Audience::user($suggestion->player_user_id));
        });

        return new JsonResponse(null, 204);
    }

    private function sendWhisper(User $sender, string $campaignId, string $recipientId, string $kind, string $body, CampaignRole $senderRole, ?GameSession $session = null): Whisper
    {
        $session ??= $this->access->lockOpenForCampaign($campaignId);
        $whisper = Whisper::query()->create([
            'campaign_id' => $campaignId, 'session_id' => $session?->getKey(), 'sender_user_id' => $sender->getKey(),
            'recipient_user_id' => $recipientId, 'kind' => $kind, 'body' => mb_substr($body, 0, 2000), 'created_at' => now(),
        ]);
        // Metadata only: whisper text never goes to the audit trail.
        $this->audit->record($sender, $campaignId, 'whisper.sent', $whisper, ['kind' => $kind, 'recipient_user_id' => $recipientId]);
        if ($session !== null) {
            // Recipient, the masters' windows, and the sender's other devices. Never the table.
            $audiences = [Audience::user($recipientId), Audience::master()];
            if ($senderRole === CampaignRole::Player) {
                $audiences[] = Audience::user($sender->getKey());
            }
            $this->stream->append($session, 'whisper.received', LiveViews::whisper($whisper), ...$audiences);
        }

        return $whisper;
    }

    private function noteEvent(?GameSession $session, MasterNote $note, string $type): void
    {
        if ($session !== null) {
            $this->stream->append($session, $type, LiveViews::note($note), Audience::master());
        }
    }

    /** @return array<string, mixed> */
    private static function noteRules(bool $update): array
    {
        return [
            'revision' => $update ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'body' => $update ? ['sometimes', 'string', 'max:4000'] : ['required', 'string', 'min:1', 'max:4000'],
            'tags' => ['sometimes', 'array', 'max:8'],
            'tags.*' => ['string', 'min:1', 'max:24'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private static function tags(array $tags): array
    {
        return array_values(array_unique(array_map(static fn (string $t): string => mb_strtolower(trim($t)), $tags)));
    }
}
