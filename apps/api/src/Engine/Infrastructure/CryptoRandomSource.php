<?php
declare(strict_types=1);

namespace Novtt\Engine\Infrastructure;

use Novtt\Engine\Contracts\RandomSource;

final class CryptoRandomSource implements RandomSource
{
    public function integer(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
