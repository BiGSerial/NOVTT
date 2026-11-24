<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/** @internal marker for a value removed by the migration */
final class Dropped
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
