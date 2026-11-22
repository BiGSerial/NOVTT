<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use App\Domain\CampaignCharacterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'library_character_id', 'player_user_id', 'custodian_user_id', 'preset_version_id', 'name', 'status', 'sheet', 'sheet_revision', 'approved_by', 'approved_at', 'archived_at'])]
class CampaignCharacter extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'campaign_characters';

    /** @var array<string, mixed> Mirrors database defaults so new instances are complete. */
    protected $attributes = ['status' => 'draft', 'sheet' => '{}', 'sheet_revision' => 0, 'custodian_user_id' => null, 'library_character_id' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignCharacterStatus::class,
            'sheet' => JsonDocumentCast::class,
            'sheet_revision' => 'integer',
            'approved_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<PresetVersion, $this> */
    public function presetVersion(): BelongsTo
    {
        return $this->belongsTo(PresetVersion::class);
    }

    /** The user currently in control: the custodian master while custody lasts, otherwise the player. */
    public function controllerId(): string
    {
        return (string) ($this->custodian_user_id ?? $this->player_user_id);
    }
}
