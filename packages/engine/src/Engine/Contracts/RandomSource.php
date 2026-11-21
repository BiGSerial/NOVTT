<?php
declare(strict_types=1);

namespace Novtt\Engine\Contracts;

interface RandomSource
{
    public function integer(int $min, int $max): int;
}
