<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Support;

use Novtt\Engine\Contracts\RandomSource;
use RuntimeException;

/** Deterministic RandomSource for tests: returns the given faces in order and checks bounds. */
final class SequenceRandom implements RandomSource
{
    /** @param list<int> $values */
    public function __construct(private array $values)
    {
    }

    public function integer(int $min, int $max): int
    {
        $value = array_shift($this->values) ?? throw new RuntimeException('sequence exhausted');
        if ($value < $min || $value > $max) {
            throw new RuntimeException("face {$value} outside {$min}..{$max}");
        }

        return $value;
    }
}
