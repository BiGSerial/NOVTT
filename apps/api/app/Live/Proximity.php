<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Who is near whom (SPEC-MVP-003 NT-13). The master groups characters into named places; two
 * characters are near when they share a place. With no places, or when neither is placed,
 * everyone is together. A placed character is far from an unplaced one.
 */
final class Proximity
{
    /** @param array<string, mixed>|null $places session `places` ({revision, places: [{id, name, character_ids}]}) */
    public static function placeOf(?array $places, string $characterId): ?string
    {
        foreach ((array) ($places['places'] ?? []) as $place) {
            if (in_array($characterId, (array) ($place['character_ids'] ?? []), true)) {
                return (string) $place['id'];
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $places */
    public static function near(?array $places, string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return self::placeOf($places, $a) === self::placeOf($places, $b);
    }
}
