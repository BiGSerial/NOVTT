<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_character_id', 'author_user_id', 'base_sheet_revision', 'draft_version', 'change_set'])]
class CharacterDraft extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'character_drafts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'change_set' => JsonDocumentCast::class,
            'base_sheet_revision' => 'integer',
            'draft_version' => 'integer',
        ];
    }
}
