<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_user_id', 'slug', 'display_name'])]
class PresetFamily extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'preset_families';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [

        ];
    }
}
