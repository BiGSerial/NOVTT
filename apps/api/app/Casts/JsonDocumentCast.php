<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use stdClass;

/**
 * JSONB column holding a JSON object document.
 *
 * Reading returns associative arrays. Writing accepts arrays or stdClass trees; callers that
 * must preserve `{}` vs `[]` (sheets, preset content) pass stdClass trees built with
 * JsonDocument::toJsonValue() or JsonDocument::decode(). An empty root is always stored as `{}`.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|stdClass>
 */
final class JsonDocumentCast implements CastsAttributes
{
    private const MAX_DEPTH = 64;

    /** @throws JsonException */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode((string) $value, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
    }

    /** @throws JsonException */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === []) {
            return '{}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
