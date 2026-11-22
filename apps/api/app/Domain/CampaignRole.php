<?php

declare(strict_types=1);

namespace App\Domain;

enum CampaignRole: string
{
    case Master = 'master';
    case Player = 'player';
}
