<?php
declare(strict_types=1);

namespace Novtt\Engine\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
