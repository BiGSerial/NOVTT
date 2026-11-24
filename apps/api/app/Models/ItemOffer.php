<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['session_id', 'campaign_id', 'from_character_id', 'to_character_id', 'item_id', 'item_label', 'qty', 'status', 'offered_by', 'resolved_by', 'resolved_at'])]
class ItemOffer extends Model
{
    use HasUuids;

    protected $table = 'item_offers';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['qty' => 'integer', 'resolved_at' => 'immutable_datetime'];
    }
}
