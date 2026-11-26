<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Session clocks (SPEC-MVP-003 NT-11): each one says who may see it. "master" stays on the
 * master's console; "table" shows to every player; "players" only to the listed user ids.
 * Players receive a reduced view (no visibility list, no other players' ids).
 */
final class Clocks
{
    public const TONES = ['threat', 'progress', 'mystery', 'neutral'];

    public const VISIBILITY = ['master', 'table', 'players'];

    /**
     * @param  array<string, mixed>  $c  validated input
     * @param  list<string>  $players  user ids that may be named (active players)
     * @return array<string, mixed>
     */
    public static function normalize(array $c, array $players): array
    {
        $visibility = in_array($c['visibility'] ?? null, self::VISIBILITY, true) ? $c['visibility'] : 'master';

        return [
            'id' => (string) $c['id'],
            'label' => (string) $c['label'],
            'segments' => (int) $c['segments'],
            'filled' => (int) $c['filled'],
            'tone' => in_array($c['tone'] ?? null, self::TONES, true) ? $c['tone'] : 'threat',
            'visibility' => $visibility,
            'player_ids' => $visibility === 'players' ? array_values(array_intersect(array_unique(array_map('strval', (array) ($c['player_ids'] ?? []))), $players)) : [],
        ];
    }

    /**
     * The clocks one player may see, without visibility metadata.
     *
     * @param  array<int, mixed>  $clocks
     * @return list<array<string, mixed>>
     */
    public static function visibleTo(array $clocks, string $userId): array
    {
        $out = [];
        foreach ($clocks as $c) {
            $c = (array) $c;
            $visibility = $c['visibility'] ?? 'master';
            if ($visibility === 'table' || ($visibility === 'players' && in_array($userId, (array) ($c['player_ids'] ?? []), true))) {
                $out[] = ['id' => $c['id'], 'label' => $c['label'], 'segments' => (int) $c['segments'], 'filled' => (int) $c['filled'], 'tone' => $c['tone'] ?? 'threat'];
            }
        }

        return $out;
    }
}
