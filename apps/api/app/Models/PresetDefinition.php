<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_id', 'owner_user_id', 'variant_slug', 'display_name'])]
class PresetDefinition extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'preset_definitions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [

        ];
    }

    /** @return BelongsTo<PresetFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(PresetFamily::class, 'family_id');
    }
}
