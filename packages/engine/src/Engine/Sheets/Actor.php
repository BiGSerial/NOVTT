<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/** Who is proposing the operations: a player is bound by field access; a master is not. */
enum Actor: string
{
    case Player = 'player';
    case Master = 'master';
}
