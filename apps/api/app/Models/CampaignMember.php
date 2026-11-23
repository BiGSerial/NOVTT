<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'user_id', 'role', 'status', 'invitation_id', 'joined_at', 'removed_at'])]
class CampaignMember extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'campaign_members';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'invitation_id' => null, 'removed_at' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CampaignRole::class,
            'status' => MemberStatus::class,
            'joined_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
