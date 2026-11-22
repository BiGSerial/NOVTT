<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\OperationDecisionValue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['change_operation_id', 'change_request_id', 'decision', 'reason', 'is_final', 'review_version', 'decided_by', 'decided_at'])]
class OperationDecision extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'operation_decisions';

    /** @var array<string, mixed> */
    protected $attributes = ['is_final' => false, 'reason' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => OperationDecisionValue::class,
            'is_final' => 'boolean',
            'review_version' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
