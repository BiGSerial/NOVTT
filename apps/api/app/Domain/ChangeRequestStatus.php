<?php

declare(strict_types=1);

namespace App\Domain;

enum ChangeRequestStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case AwaitingResolution = 'awaiting_resolution';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Superseded = 'superseded';

    /** Statuses covered by the one-active-proposal partial unique index. */
    public const ACTIVE = ['submitted', 'in_review', 'awaiting_resolution'];

    public function isActive(): bool
    {
        return in_array($this->value, self::ACTIVE, true);
    }
}
