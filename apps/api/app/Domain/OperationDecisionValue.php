<?php

declare(strict_types=1);

namespace App\Domain;

enum OperationDecisionValue: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
