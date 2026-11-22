<?php

declare(strict_types=1);

namespace App\Domain;

enum CampaignCharacterStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Archived = 'archived';
}
