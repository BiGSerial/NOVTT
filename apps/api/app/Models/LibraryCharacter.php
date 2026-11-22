<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_user_id', 'preset_version_id', 'name', 'sheet', 'archived_at'])]
class LibraryCharacter extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'character_library';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sheet' => JsonDocumentCast::class,
            'archived_at' => 'immutable_datetime',
        ];
    }
}
