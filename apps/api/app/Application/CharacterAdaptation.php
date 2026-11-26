<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CampaignCharacterStatus;
use App\Domain\ChangeRequestStatus;
use App\Http\Problems\ApiProblem;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\ChangeRequest;
use App\Models\CharacterDraft;
use App\Models\CharacterSheetRevision;
use App\Models\PresetVersion;
use App\Models\User;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Sheets\SheetMigration;

/**
 * Brings a character built on an older (or other) version of the campaign's sheet to the version
 * the campaign uses now (SPEC-MVP-001 sheet versions). Keeps what is still valid, reports what
 * was dropped, keeps the old sheet as a revision, carries the player's draft over (operations on
 * removed fields are dropped) and withdraws proposals made against the old version. An approved
 * character goes back to the master for approval: the rules may have changed.
 */
final readonly class CharacterAdaptation
{
    public function __construct(private AuditTrail $audit)
    {
    }

    /** @return array<string, mixed> */
    public function preview(CampaignCharacter $character): array
    {
        [$from, $to] = $this->versions($character);
        $result = SheetMigration::migrate((array) $character->sheet, (array) $to->sheet_schema);

        return [
            'needed' => $from->getKey() !== $to->getKey(),
            'from_version' => $from->version, 'to_version' => $to->version,
            'from_version_id' => $from->getKey(), 'to_version_id' => $to->getKey(),
            'kept' => $result['kept'],
            'dropped' => array_map(static fn (array $d): array => ['path' => $d['path'], 'reason' => $d['reason']], $result['dropped']),
            'draft_operations_dropped' => $this->draftDrops($character, $to),
            'needs_approval' => $character->status === CampaignCharacterStatus::Approved,
        ];
    }

    /** @return array<string, mixed> the preview that was applied */
    public function adapt(User $actor, CampaignCharacter $character, int $expectedRevision): array
    {
        if ($character->sheet_revision !== $expectedRevision) {
            throw ApiProblem::conflict('character.revision_mismatch', $character->sheet_revision);
        }
        [$from, $to] = $this->versions($character);
        if ($from->getKey() === $to->getKey()) {
            throw ApiProblem::conflict('character.already_current');
        }
        $report = $this->preview($character);
        $result = SheetMigration::migrate((array) $character->sheet, (array) $to->sheet_schema);

        $revision = $character->sheet_revision + 1;
        $sheet = $result['sheet'] === [] ? (object) [] : $result['sheet'];
        // Revisions are append-only: the adapted sheet is a new revision; the old one stays in history.
        CharacterSheetRevision::query()->create([
            'campaign_character_id' => $character->getKey(), 'revision' => $revision,
            'sheet' => $sheet, 'created_by' => $actor->getKey(), 'created_at' => now(),
        ]);
        $navigator = new SchemaNavigator((array) $to->sheet_schema);
        foreach (CharacterDraft::query()->where('campaign_character_id', $character->getKey())->get() as $draft) {
            $set = (array) $draft->change_set;
            $set['operations'] = array_values(array_filter((array) ($set['operations'] ?? []), fn (mixed $op): bool => is_array($op) && $this->declared($navigator, (string) ($op['path'] ?? ''))));
            $set['base_sheet_revision'] = $revision;
            $draft->update(['change_set' => $set, 'base_sheet_revision' => $revision, 'draft_version' => $draft->draft_version + 1]);
        }
        $withdrawn = ChangeRequest::query()->where('campaign_character_id', $character->getKey())->whereIn('status', ChangeRequestStatus::ACTIVE)
            ->update(['status' => ChangeRequestStatus::Withdrawn->value]);
        $character->update([
            'sheet' => $sheet,
            'sheet_revision' => $revision,
            'preset_version_id' => $to->getKey(),
            'status' => $character->status === CampaignCharacterStatus::Approved ? CampaignCharacterStatus::PendingApproval : $character->status,
        ]);
        $this->audit->record($actor, $character->campaign_id, 'campaign_character.adapted', $character, [
            'from_version_id' => $from->getKey(), 'to_version_id' => $to->getKey(),
            'dropped' => $report['dropped'], 'proposals_withdrawn' => $withdrawn,
        ]);
        $this->audit->emit($character->campaign_id, 'campaign_character.adapted', $character, ['campaign_character_id' => $character->getKey(), 'sheet_revision' => $revision]);

        return $report + ['proposals_withdrawn' => $withdrawn];
    }

    /** @return array{0: PresetVersion, 1: PresetVersion} */
    private function versions(CampaignCharacter $character): array
    {
        $campaign = Campaign::query()->findOrFail($character->campaign_id);

        return [PresetVersion::query()->findOrFail($character->preset_version_id), PresetVersion::query()->findOrFail($campaign->preset_version_id)];
    }

    private function draftDrops(CampaignCharacter $character, PresetVersion $to): int
    {
        $navigator = new SchemaNavigator((array) $to->sheet_schema);
        $dropped = 0;
        foreach (CharacterDraft::query()->where('campaign_character_id', $character->getKey())->get() as $draft) {
            foreach ((array) (((array) $draft->change_set)['operations'] ?? []) as $op) {
                if (!is_array($op) || !$this->declared($navigator, (string) ($op['path'] ?? ''))) {
                    $dropped++;
                }
            }
        }

        return $dropped;
    }

    private function declared(SchemaNavigator $navigator, string $path): bool
    {
        try {
            return $navigator->at(JsonPointer::parse($path)) !== null;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
