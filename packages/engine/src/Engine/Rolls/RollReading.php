<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

/** How the faces of an official roll are read (chosen by the master in the request). */
enum RollReading: string
{
    /** Sum of all faces plus the bonus. */
    case SUM = 'sum';
    /** Highest face plus the bonus. */
    case HIGHEST = 'highest';
    /** Each face at or above the threshold counts as successes-per-die. */
    case SUCCESSES = 'successes';
}
