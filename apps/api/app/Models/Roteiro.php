<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Session prep: the master's plan for the next session (LT-07). */
#[Fillable(['campaign_id', 'title', 'content', 'revision', 'archived_at'])]
class Roteiro extends Model
{
    use HasUuids;

    protected $table = 'roteiros';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => JsonDocumentCast::class,
            'revision' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** Compass a session starts with when it is opened from this roteiro. */
    public function compassSeed(): array
    {
        $c = (array) $this->content;
        $scene = (array) (($c['scenes'] ?? [])[0] ?? []);
        $clue = (array) (($scene['clues'] ?? [])[0] ?? []);

        return [
            'scene' => (string) ($scene['title'] ?? ''),
            'objective' => (string) ($scene['goal'] ?? ''),
            'next_clue' => (string) ($clue['title'] ?? ''),
            'clocks' => array_values(array_map(static fn (array $k, int $i): array => [
                'id' => 'c'.($i + 1), 'label' => (string) ($k['label'] ?? ''), 'segments' => max(2, min(12, (int) ($k['segments'] ?? 4))), 'filled' => 0,
            ], (array) ($c['clocks'] ?? []), array_keys((array) ($c['clocks'] ?? [])))),
            'spotlight' => (object) [],
        ];
    }

    /** @return list<array{scene: string, title: string, body: string}> */
    public function clueSeeds(): array
    {
        $out = [];
        foreach ((array) (((array) $this->content)['scenes'] ?? []) as $scene) {
            foreach ((array) ($scene['clues'] ?? []) as $clue) {
                $out[] = ['scene' => mb_substr((string) ($scene['title'] ?? ''), 0, 120), 'title' => mb_substr((string) ($clue['title'] ?? ''), 0, 120), 'body' => mb_substr((string) ($clue['body'] ?? ''), 0, 2000)];
            }
        }

        return array_slice($out, 0, 60);
    }
}
