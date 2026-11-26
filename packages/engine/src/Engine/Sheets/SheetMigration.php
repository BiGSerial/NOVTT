<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/**
 * Adapts a sheet to another version of its preset's sheet schema (same game, new version).
 *
 * Keeps every value the target schema still declares and accepts (type, enum, bounds, string
 * length, item shape); drops the rest and reports each drop with its reason, so the player and the
 * master see exactly what changed before anyone approves. Ledger entries survive only for fields
 * that still exist and still hold the paid level. Pure and deterministic: no rules are applied
 * here (the change engine and the master's approval do that afterwards).
 */
final class SheetMigration
{
    public const LEDGER = SheetChangeEngine::LEDGER;

    /** @var list<array{path: string, reason: string, value: mixed}> */
    private array $dropped = [];

    private int $kept = 0;

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, mixed> $targetSchema
     * @return array{sheet: array<string, mixed>, dropped: list<array{path: string, reason: string, value: mixed}>, kept: int}
     */
    public static function migrate(array $sheet, array $targetSchema): array
    {
        $m = new self();
        $ledger = (array) ($sheet[self::LEDGER] ?? []);
        unset($sheet[self::LEDGER]);
        $out = $m->object($sheet, $targetSchema, '');
        $out = is_array($out) ? $out : [];
        $kept = [];
        foreach ($ledger as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['level'])) {
                continue;
            }
            [$exists, $value] = self::read($out, (string) $entry['path']);
            // Losses stay recorded (their spending is not given back by adapting the sheet).
            if (($entry['lost'] ?? false) === true || ($exists && is_int($value) && $value >= (int) $entry['level'])) {
                $kept[] = $entry;
            }
        }
        if ($kept !== []) {
            $out[self::LEDGER] = array_values($kept);
        }

        return ['sheet' => $out, 'dropped' => $m->dropped, 'kept' => $m->kept];
    }

    /** @param array<string, mixed> $schema */
    private function value(mixed $value, array $schema, string $path): mixed
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : ($type === null ? [] : [$type]);
        if ($value === null) {
            if ($types === [] || in_array('null', $types, true)) {
                $this->kept++;

                return null;
            }

            return $this->drop($path, 'type', $value);
        }
        if (in_array('object', $types, true) && is_array($value) && ($value === [] || !array_is_list($value))) {
            return $this->object($value, $schema, $path);
        }
        if (in_array('array', $types, true) && is_array($value) && array_is_list($value)) {
            return $this->list($value, $schema, $path);
        }
        if ($types !== [] && !$this->typeMatches($value, $types)) {
            return $this->drop($path, 'type', $value);
        }
        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
            return $this->drop($path, 'option_removed', $value);
        }
        if (isset($schema['const']) && $value !== $schema['const']) {
            return $this->drop($path, 'option_removed', $value);
        }
        if ((is_int($value) || is_float($value)) && ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum']))) {
            return $this->drop($path, 'out_of_range', $value);
        }
        if (is_string($value) && ((isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) || (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']))) {
            return $this->drop($path, 'length', $value);
        }
        if (is_string($value) && isset($schema['pattern']) && @preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/u', $value) !== 1) {
            return $this->drop($path, 'pattern', $value);
        }
        $this->kept++;

        return $value;
    }

    /** @param array<string, mixed> $value @param array<string, mixed> $schema */
    private function object(array $value, array $schema, string $path): array|object
    {
        $properties = (array) ($schema['properties'] ?? []);
        $open = ($schema['additionalProperties'] ?? true) !== false;
        $out = [];
        foreach ($value as $key => $item) {
            $child = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (isset($properties[$key])) {
                $result = $this->value($item, (array) $properties[$key], $child);
                if (!$result instanceof Dropped) {
                    $out[$key] = $result;
                }
            } elseif ($open) {
                $out[$key] = $item;
                $this->kept++;
            } else {
                $this->drop($child, 'field_removed', $item);
            }
        }
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $out)) {
                return $path === '' ? $out : $this->drop($path, 'incomplete', $value);
            }
        }

        return $out === [] && $path !== '' ? (object) [] : $out;
    }

    /** @param list<mixed> $items @param array<string, mixed> $schema @return list<mixed>|Dropped */
    private function list(array $items, array $schema, string $path): array|Dropped
    {
        $itemSchema = (array) ($schema['items'] ?? []);
        $out = [];
        foreach ($items as $i => $item) {
            $result = $this->value($item, $itemSchema, "{$path}/{$i}");
            if (!$result instanceof Dropped) {
                $out[] = $result;
            }
        }
        if (isset($schema['maxItems']) && count($out) > (int) $schema['maxItems']) {
            foreach (array_slice($out, (int) $schema['maxItems'], null, true) as $i => $extra) {
                $this->drop("{$path}/{$i}", 'too_many_items', $extra);
            }
            $out = array_slice($out, 0, (int) $schema['maxItems']);
        }

        return $out;
    }

    /** @param list<string> $types */
    private function typeMatches(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if (match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'object' => is_array($value) && !array_is_list($value),
                'array' => is_array($value) && array_is_list($value),
                default => false,
            }) {
                return true;
            }
        }

        return false;
    }

    private function drop(string $path, string $reason, mixed $value): Dropped
    {
        $this->dropped[] = ['path' => $path === '' ? '/' : $path, 'reason' => $reason, 'value' => $value];

        return Dropped::instance();
    }

    /** @param array<string, mixed> $doc @return array{0: bool, 1: mixed} */
    private static function read(array $doc, string $pointer): array
    {
        $node = $doc;
        foreach (array_slice(explode('/', $pointer), 1) as $raw) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return [false, null];
            }
            $node = $node[$key];
        }

        return [true, $node];
    }
}
