<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['change_request_id', 'operation_key', 'position', 'type', 'path', 'section_id', 'payload', 'created_at'])]
class ChangeOperation extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'change_operations';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => JsonDocumentCast::class,
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
