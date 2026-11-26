<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\Roteiro;

/**
 * The session plot (SPEC-MVP-003 NT-03): the roteiro's scenes carried into the live session so
 * the master can walk the story scene by scene. Master-only; the table sees only the stage.
 */
final class Plot
{
    /** Original scene illustrations shipped with the client (keys only; no third-party art). */
    public const ART = ['bridge', 'forest', 'tavern', 'ruins', 'city', 'cave', 'sea', 'camp', 'road', 'castle', 'swamp', 'temple'];

    public const MAX_SCENES = 16;

    /**
     * Validation rules for one scene under the given prefix (e.g. "content.scenes.*").
     *
     * @return array<string, list<string>>
     */
    public static function sceneRules(string $prefix): array
    {
        return [
            "{$prefix}.location" => ['sometimes', 'nullable', 'string', 'max:120'],
            "{$prefix}.read_aloud" => ['sometimes', 'nullable', 'string', 'max:1500'],
            "{$prefix}.npcs" => ['sometimes', 'array', 'max:8'],
            "{$prefix}.npcs.*" => ['string', 'min:1', 'max:80'],
            "{$prefix}.art" => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::ART)],
            "{$prefix}.image_id" => ['sometimes', 'nullable', 'uuid'],
            "{$prefix}.complication" => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return array{scenes: list<array<string, mixed>>, current: int|null}
     */
    public static function normalize(array $scenes, ?int $current): array
    {
        $out = [];
        foreach (array_values($scenes) as $i => $s) {
            $out[] = [
                'id' => (string) (($s['id'] ?? '') !== '' ? $s['id'] : 's'.($i + 1)),
                'title' => mb_substr((string) ($s['title'] ?? ''), 0, 120),
                'goal' => mb_substr((string) ($s['goal'] ?? ''), 0, 300),
                'location' => mb_substr((string) ($s['location'] ?? ''), 0, 120),
                'read_aloud' => mb_substr((string) ($s['read_aloud'] ?? ''), 0, 1500),
                'npcs' => array_values(array_map(static fn ($n): string => mb_substr((string) $n, 0, 80), array_slice((array) ($s['npcs'] ?? []), 0, 8))),
                'art' => in_array($s['art'] ?? null, self::ART, true) ? $s['art'] : null,
                'image_id' => isset($s['image_id']) && is_string($s['image_id']) ? $s['image_id'] : null,
                'complication' => mb_substr((string) ($s['complication'] ?? ''), 0, 500),
                'status' => $current === null ? 'pending' : ($i < $current ? 'done' : ($i === $current ? 'current' : 'pending')),
            ];
        }
        $out = array_slice($out, 0, self::MAX_SCENES);
        $current = $current !== null && $current < count($out) ? $current : null;

        return ['scenes' => $out, 'current' => $current];
    }

    /** @return array{scenes: list<array<string, mixed>>, current: int|null} */
    public static function fromRoteiro(?Roteiro $roteiro): array
    {
        $scenes = $roteiro === null ? [] : array_values((array) (((array) $roteiro->content)['scenes'] ?? []));

        return self::normalize($scenes, $scenes === [] ? null : 0);
    }
}
