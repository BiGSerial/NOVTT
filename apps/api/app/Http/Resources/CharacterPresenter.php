<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\SheetViews;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\LibraryCharacter;
use App\Models\LibraryNpc;
use Novtt\Engine\Sheets\CanonicalJson;

final readonly class CharacterPresenter
{
    public function __construct(private SheetViews $views)
    {
    }

    /** @return array<string, mixed> */
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
            'sheet' => self::object($this->views->project($character->preset_version_id, (array) $character->sheet, $asMaster)),
        ];
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
