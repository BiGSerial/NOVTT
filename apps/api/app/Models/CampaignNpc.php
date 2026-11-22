<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_id', 'source_npc_id', 'preset_version_id', 'name', 'sheet', 'sheet_revision', 'created_by', 'archived_at'])]
class CampaignNpc extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'campaign_npcs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sheet' => JsonDocumentCast::class,
            'sheet_revision' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
