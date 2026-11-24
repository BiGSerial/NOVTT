<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\SheetChanges;
use App\Application\SheetViews;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\LibraryCharacter;
use App\Models\LibraryNpc;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Validation\ValidationResult;

final readonly class CharacterPresenter
{
    public function __construct(private SheetViews $views, private SheetChanges $changes)
    {
    }

    /**
     * Derived values are computed on read from the official sheet and the grants (never stored),
     * then the same allow-list projection as stored fields decides what the viewer sees. For
     * rules/2 presets the response also carries creation progress; the decision trace explains
     * every derived value the viewer can read.
     *
     * @return array<string, mixed>
     */
    public function campaignCharacter(CampaignCharacter $character, bool $asMaster): array
    {
        return [
            'id' => $character->getKey(),
            'campaign_id' => $character->campaign_id,
            'name' => $character->name,
            'status' => $character->status->value,
            'sheet_revision' => $character->sheet_revision,
            'player_user_id' => $character->player_user_id,
            'in_custody' => $character->custodian_user_id !== null,
            'preset_version_id' => $character->preset_version_id,
            'portrait_image_id' => $character->portrait_image_id,
            ...$this->sheetView($character, (array) $character->sheet, $asMaster),
        ];
    }

    /**
     * Projection of a sheet document of this character (the official one, or a draft candidate):
     * derived values, filtered decision trace and, for rules/2, creation progress and choice
     * availability.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function sheetView(CampaignCharacter $character, array $raw, bool $asMaster): array
    {
        $versionId = $character->preset_version_id;
        $mechanics = $this->changes->mechanics($versionId);
        $grants = $this->changes->grants($character);
        [$materialized, $trace] = $mechanics->materialize($raw, $grants);
        $projector = $this->views->projector($versionId);
        $readable = static fn (string $path): bool => $asMaster || $projector->playerCanRead(JsonPointer::parse($path));

        $visibleTrace = [];
        foreach ($trace as $entry) {
            if (!$readable($entry['path'])) {
                continue;
            }
            $entry['modifiers'] = array_values(array_filter(
                $entry['modifiers'],
                static fn (array $m): bool => !isset($m['source']['collection']) || $readable((string) $m['source']['collection']),
            ));
            $visibleTrace[] = $entry;
        }

        $out = [
            'sheet' => self::object($this->views->project($versionId, $materialized, $asMaster)),
            'decision_trace' => $visibleTrace,
        ];
        if ($this->changes->usesRulesV2($versionId)) {
            $progress = $mechanics->creationProgress($raw, $grants);
            $viewer = $asMaster ? Actor::Master : Actor::Player;
            $out['creation'] = [
                'complete' => $progress['complete'],
                'steps' => array_map(static function (array $step) use ($readable): array {
                    $step['missing'] = array_values(array_filter($step['missing'], $readable));

                    return $step;
                }, $progress['steps']),
                'issues' => $this->changes->filterFor(new ValidationResult($progress['issues']), $character, $viewer)->toArray()['issues'],
            ];
            $out['choices'] = array_values(array_filter(
                $mechanics->choiceAvailability($raw, $grants),
                static fn (array $choice): bool => $readable($choice['path']),
            ));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function library(LibraryCharacter $character): array
    {
        return [
            'id' => $character->getKey(),
            'name' => $character->name,
            'preset_version_id' => $character->preset_version_id,
            'archived' => $character->archived_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function libraryNpc(LibraryNpc $npc): array
    {
        return [
            'id' => $npc->getKey(),
            'name' => $npc->name,
            'preset_version_id' => $npc->preset_version_id,
            'private_notes' => $npc->private_notes,
        ];
    }

    /** @return array<string, mixed> */
    public static function campaignNpc(CampaignNpc $npc): array
    {
        return [
            'id' => $npc->getKey(),
            'campaign_id' => $npc->campaign_id,
            'source_npc_id' => $npc->source_npc_id,
            'name' => $npc->name,
            'sheet' => self::object((array) $npc->sheet),
        ];
    }

    /** Sheets are JSON objects even when empty. */
    private static function object(array $sheet): array|object
    {
        return $sheet === [] ? (object) [] : json_decode(CanonicalJson::encode($sheet), false, 64, JSON_THROW_ON_ERROR);
    }
}
