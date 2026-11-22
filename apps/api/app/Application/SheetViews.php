<?php

declare(strict_types=1);

namespace App\Application;

use App\Models\PresetVersion;
use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Presets\SheetProjector;

/** Builds (and memoizes per request) the layout/projector of a preset version. */
final class SheetViews
{
    /** @var array<string, SheetProjector> */
    private array $projectors = [];

    /** @var array<string, SheetLayout> */
    private array $layouts = [];

    public function layout(string $presetVersionId): SheetLayout
    {
        return $this->layouts[$presetVersionId] ??= SheetLayout::fromArray(
            (array) PresetVersion::query()->whereKey($presetVersionId)->firstOrFail(['id', 'sheet_ui'])->sheet_ui,
        );
    }

    public function projector(string $presetVersionId): SheetProjector
    {
        return $this->projectors[$presetVersionId] ??= new SheetProjector($this->layout($presetVersionId));
    }

    /**
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    public function project(string $presetVersionId, array $sheet, bool $asMaster): array
    {
        $projector = $this->projector($presetVersionId);

        return $asMaster ? $projector->forMaster($sheet) : $projector->forPlayer($sheet);
    }
}
