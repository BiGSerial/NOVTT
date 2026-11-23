<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\CampaignRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'created_by', 'token_hash', 'role', 'max_uses', 'uses', 'expires_at', 'revoked_at'])]
class CampaignInvitation extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'campaign_invitations';

    /** @var array<string, mixed> */
    protected $attributes = ['role' => 'player', 'max_uses' => 1, 'uses' => 0, 'revoked_at' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CampaignRole::class,
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
