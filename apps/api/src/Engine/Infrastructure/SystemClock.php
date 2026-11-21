<?php
declare(strict_types=1);

namespace Novtt\Engine\Infrastructure;

use Novtt\Engine\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
