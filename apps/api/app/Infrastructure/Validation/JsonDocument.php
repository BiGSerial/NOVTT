<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use stdClass;

/**
 * PHP decodes both `{}` and `[]` to `[]`. JSON Schema validation needs the distinction, so
 * conversion to stdClass trees is guided by the schema: an empty PHP array becomes an object
 * wherever the schema expects an object, and a list everywhere else. Local `$ref`s
 * (`#/$defs/...`) and allOf/anyOf/oneOf branches are followed.
 */
final class JsonDocument
{
    private const MAX_REF_HOPS = 16;

    /** @param array<string, mixed>|null $schema */
    public static function toJsonValue(mixed $value, ?array $schema = null): mixed
    {
        return self::convert($value, $schema ?? [], $schema ?? []);
    }

    /** Decodes a JSON string keeping the object/list distinction. */
    public static function decode(string $json, int $maxDepth = 64): mixed
    {
        return json_decode($json, false, $maxDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /** @param array<string, mixed> $schema @param array<string, mixed> $root */
    private static function convert(mixed $value, array $schema, array $root): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $schema = self::narrow(self::deref($schema, $root), $value, $root);
        $isObject = self::expectsObject($schema, $root);
        if ($value === []) {
            return $isObject ? new stdClass() : [];
        }
        if (array_is_list($value) && !$isObject) {
            $items = self::find($schema, $root, static fn (array $s): ?array => is_array($s['items'] ?? null) ? $s['items'] : null) ?? [];

            return array_map(static fn (mixed $v): mixed => self::convert($v, $items, $root), $value);
        }
        $object = new stdClass();
        foreach ($value as $key => $child) {
            $key = (string) $key;
            $childSchema = self::find($schema, $root, static fn (array $s): ?array => is_array($s['properties'][$key] ?? null) ? $s['properties'][$key] : null)
                ?? self::find($schema, $root, static fn (array $s): ?array => is_array($s['additionalProperties'] ?? null) ? $s['additionalProperties'] : null)
                ?? [];
            $object->{$key} = self::convert($child, $childSchema, $root);
        }

        return $object;
    }

    /**
     * For a discriminated oneOf/anyOf (branches with `const` properties), keep only the branch whose
     * constants match the value, merged with the parent's own keywords.
     *
     * @param array<string, mixed> $schema @param array<mixed> $value @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private static function narrow(array $schema, array $value, array $root): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;
            if (!is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                $branch = is_array($branch) ? self::deref($branch, $root) : [];
                $consts = array_filter((array) ($branch['properties'] ?? []), static fn (mixed $p): bool => is_array($p) && array_key_exists('const', $p));
                if ($consts === []) {
                    continue;
                }
                $matches = true;
                foreach ($consts as $key => $property) {
                    if (!array_key_exists($key, $value) || $value[$key] !== $property['const']) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $parent = $schema;
                    unset($parent[$keyword]);
                    $merged = $parent;
                    $merged['properties'] = array_merge((array) ($parent['properties'] ?? []), (array) ($branch['properties'] ?? []));

                    return $merged;
                }
            }
        }

        return $schema;
    }

    /** @param array<string, mixed> $schema @param array<string, mixed> $root */
    private static function expectsObject(array $schema, array $root): bool
    {
        return self::find($schema, $root, static function (array $s): ?array {
            $type = $s['type'] ?? null;
            $object = $type === 'object' || (is_array($type) && in_array('object', $type, true))
                || (!isset($s['type']) && (isset($s['properties']) || isset($s['additionalProperties']) || isset($s['propertyNames'])));

            return $object ? $s : null;
        }) !== null;
    }

    /**
     * Depth-first search over the schema and its combinator branches.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $root
     * @param callable(array<string, mixed>): ?array<string, mixed> $probe
     * @return array<string, mixed>|null
     */
    private static function find(array $schema, array $root, callable $probe, int $depth = 0): ?array
    {
        if ($depth > self::MAX_REF_HOPS) {
            return null;
        }
        $schema = self::deref($schema, $root);
        $hit = $probe($schema);
        if ($hit !== null) {
            return $hit;
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ((array) ($schema[$keyword] ?? []) as $branch) {
                if (is_array($branch) && ($found = self::find($branch, $root, $probe, $depth + 1)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema @param array<string, mixed> $root @return array<string, mixed> */
    private static function deref(array $schema, array $root): array
    {
        for ($hops = 0; isset($schema['$ref']) && is_string($schema['$ref']) && str_starts_with($schema['$ref'], '#/') && $hops < self::MAX_REF_HOPS; $hops++) {
            $target = $root;
            foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($target) || !array_key_exists($segment, $target)) {
                    return [];
                }
                $target = $target[$segment];
            }
            $schema = is_array($target) ? $target : [];
        }

        return $schema;
    }
}
