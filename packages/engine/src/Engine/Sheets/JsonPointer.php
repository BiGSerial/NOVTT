<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

use InvalidArgumentException;

/**
 * RFC 6901 JSON Pointer with bounded size. The root pointer ("") is rejected for sheet
 * operations: replacing the whole document would bypass per-field policies.
 */
final readonly class JsonPointer
{
    public const MAX_LENGTH = 512;
    public const MAX_DEPTH = 16;

    /** @param list<string> $segments */
    private function __construct(public array $segments)
    {
    }

    public static function parse(string $pointer): self
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            throw new InvalidArgumentException('pointer.invalid_syntax');
        }
        if (strlen($pointer) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('pointer.too_long');
        }
        if (preg_match('/~(?![01])/', $pointer) === 1) {
            throw new InvalidArgumentException('pointer.invalid_escape');
        }
        $segments = array_map(
            static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s),
            explode('/', substr($pointer, 1)),
        );
        if (count($segments) > self::MAX_DEPTH) {
            throw new InvalidArgumentException('pointer.too_deep');
        }
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('pointer.empty_segment');
            }
        }

        return new self($segments);
    }

    /** @param list<string> $segments */
    public static function fromSegments(array $segments): self
    {
        return self::parse(self::encode($segments));
    }

    public function toString(): string
    {
        return self::encode($this->segments);
    }

    public function append(string $segment): self
    {
        return self::fromSegments([...$this->segments, $segment]);
    }

    public function startsWith(self $prefix): bool
    {
        return array_slice($this->segments, 0, count($prefix->segments)) === $prefix->segments;
    }

    public function equals(self $other): bool
    {
        return $this->segments === $other->segments;
    }

    /** @param list<string> $segments */
    private static function encode(array $segments): string
    {
        return '/' . implode('/', array_map(
            static fn (string $s): string => str_replace(['~', '/'], ['~0', '~1'], $s),
            $segments,
        ));
    }
}
