<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_character_id', 'budget_id', 'amount', 'reason', 'granted_by', 'created_at'])]
class CharacterGrant extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'character_grants';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
