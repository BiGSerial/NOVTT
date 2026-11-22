<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/** Deterministic JSON: object keys sorted recursively, lists kept in order, no escaping of slashes/unicode. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return (object) array_map(self::normalize(...), $value);
    }
}
