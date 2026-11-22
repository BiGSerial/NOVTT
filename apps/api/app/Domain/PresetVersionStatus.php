<?php

declare(strict_types=1);

namespace App\Domain;

enum PresetVersionStatus: string
{
    case Draft = 'draft';
    case StructurallyValid = 'structurally_valid';
    case EngineCompatible = 'engine_compatible';
    case Reviewed = 'reviewed';
    case Published = 'published';

    /** A campaign may only pin a version the engine accepted. */
    public function isPinnable(): bool
    {
        return in_array($this, [self::EngineCompatible, self::Reviewed, self::Published], true);
    }
}
