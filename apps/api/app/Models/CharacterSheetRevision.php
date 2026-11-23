<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_character_id', 'revision', 'sheet', 'change_request_id', 'created_by', 'created_at'])]
class CharacterSheetRevision extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'character_sheet_revisions';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sheet' => JsonDocumentCast::class,
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
