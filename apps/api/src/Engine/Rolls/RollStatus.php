<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

enum RollStatus: string
{
    case OPEN = 'OPEN';
    case EXHAUSTED = 'EXHAUSTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case CLOSED = 'CLOSED';
}
