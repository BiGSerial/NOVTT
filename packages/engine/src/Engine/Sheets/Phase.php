<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/** Character phase that selects cost and limit rules: creation until approved, advancement after. */
enum Phase: string
{
    case Creation = 'creation';
    case Advancement = 'advancement';
}
