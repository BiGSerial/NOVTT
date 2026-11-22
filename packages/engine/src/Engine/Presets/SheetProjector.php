<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use Novtt\Engine\Sheets\FieldAccess;
use Novtt\Engine\Sheets\JsonPointer;

/**
 * Allow-list projection of a sheet for a viewer. Only paths declared in the layout are copied,
 * and for players only those whose access allows reading. Anything undeclared (including
 * reserved /_ engine data) is never projected, so new data cannot leak by default.
 */
final readonly class SheetProjector
{
    public function __construct(private SheetLayout $layout)
    {
    }

    /**
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    public function forPlayer(array $sheet): array
    {
        return $this->project($sheet, static fn (FieldAccess $a): bool => $a->playerCanRead());
    }

    /**
     * Masters read every declared field. Undeclared data is still dropped (sanitized copy).
     *
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    public function forMaster(array $sheet): array
    {
        return $this->project($sheet, static fn (FieldAccess $a): bool => true);
    }

    /** Whether a player may read a field, a collection, an item (/c/<id>) or an item field (/c/<id>/<field>). */
    public function playerCanRead(JsonPointer $path): bool
    {
        $element = $this->layout->element($path);
        if ($element !== null) {
            return $element['access']->playerCanRead();
        }
        $segments = $path->segments;
        $count = count($segments);
        foreach ([2 => true, 1 => false] as $tail => $isField) {
            if ($count <= $tail) {
                continue;
            }
            $collection = JsonPointer::fromSegments(array_slice($segments, 0, $count - $tail));
            $candidate = $this->layout->element($collection);
            if ($candidate === null || $candidate['kind'] !== 'collection') {
                continue;
            }
            if (!$isField || $segments[$count - 1] === 'id') {
                return $candidate['access']->playerCanRead();
            }

            return $this->layout->itemFieldAccess($collection, $segments[$count - 1])->playerCanRead();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $sheet
     * @param callable(FieldAccess): bool $readable
     * @return array<string, mixed>
     */
    private function project(array $sheet, callable $readable): array
    {
        $out = [];
        foreach ($this->layout->elements as $path => $element) {
            if (!$readable($element['access'])) {
                continue;
            }
            $pointer = JsonPointer::parse($path);
            [$found, $value] = self::read($sheet, $pointer->segments);
            if (!$found) {
                continue;
            }
            if ($element['kind'] === 'collection') {
                if (!is_array($value) || !array_is_list($value)) {
                    continue;
                }
                $items = [];
                foreach ($value as $item) {
                    if (!is_array($item) || !isset($item['id'])) {
                        continue;
                    }
                    $projected = ['id' => $item['id']];
                    foreach ($element['item_fields'] as $field => $access) {
                        if ($readable($access) && array_key_exists($field, $item)) {
                            $projected[$field] = $item[$field];
                        }
                    }
                    $items[] = $projected;
                }
                $value = $items;
            } elseif (is_array($value)) {
                continue; // declared fields are scalars; never copy subtrees
            }
            self::write($out, $pointer->segments, $value);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     * @param list<string> $segments
     * @return array{0: bool, 1: mixed}
     */
    public static function read(array $doc, array $segments): array
    {
        $node = $doc;
        foreach ($segments as $segment) {
            if (!is_array($node) || array_is_list($node) && $node !== [] || !array_key_exists($segment, $node)) {
                return [false, null];
            }
            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * @param array<string, mixed> $doc
     * @param list<string> $segments
     */
    public static function write(array &$doc, array $segments, mixed $value): void
    {
        $node = &$doc;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $node[$segment] = $value;
                break;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
    }
}
