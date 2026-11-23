<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A campaign image (portrait, scene art or handout), stored re-encoded as WebP. The bytes column
 * is never selected by default: App\Live\ImageStore reads and writes it explicitly.
 */
#[Fillable(['campaign_id', 'kind', 'mime', 'width', 'height', 'sha256', 'created_by'])]
class CampaignImage extends Model
{
    use HasUuids;

    protected $table = 'campaign_images';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer'];
    }
}
