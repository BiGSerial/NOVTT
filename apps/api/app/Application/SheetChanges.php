<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CampaignCharacterStatus;
use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\CharacterGrant;
use App\Models\PresetVersion;
use App\Models\User;
use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Rules\Mechanics;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\ChangeOperation;
use Novtt\Engine\Sheets\ChangeOutcome;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Sheets\SheetChangeEngine;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/** Glue between persisted characters and the pure SheetChangeEngine. */
final class SheetChanges
{
    /** @var array<string, SheetChangeEngine> */
    private array $engines = [];

    public function __construct(private readonly StructuralValidator $structure, private readonly SheetViews $views)
    {
    }

    public function engine(string $presetVersionId): SheetChangeEngine
    {
        if (!isset($this->engines[$presetVersionId])) {
            $version = PresetVersion::query()->findOrFail($presetVersionId);
            $this->engines[$presetVersionId] = new SheetChangeEngine(
                $this->views->layout($presetVersionId),
                $version->sheetSchemaArray(),
                $version->sheetSchemaObject(),
                (array) $version->rules,
                $this->structure,
            );
        }

        return $this->engines[$presetVersionId];
    }

    public function mechanics(string $presetVersionId): Mechanics
    {
        return $this->engine($presetVersionId)->mechanics();
    }

    /** Whether the pinned version uses novtt.rules/2 (creation steps, derived values, governance). */
    public function usesRulesV2(string $presetVersionId): bool
    {
        return ($this->mechanics($presetVersionId)->rules()['contract'] ?? null) === 'novtt.rules/2';
    }

    public function layout(CampaignCharacter $character): SheetLayout
    {
        return $this->views->layout($character->preset_version_id);
    }

    public static function phase(CampaignCharacter $character): Phase
    {
        return $character->status === CampaignCharacterStatus::Approved ? Phase::Advancement : Phase::Creation;
    }

    public function actorFor(User $user, CampaignCharacter $character): Actor
    {
        return $this->isMaster($user, $character->campaign_id) ? Actor::Master : Actor::Player;
    }

    public function isMaster(User $user, string $campaignId): bool
    {
        return CampaignMember::query()->where([
            'campaign_id' => $campaignId, 'user_id' => $user->getKey(), 'role' => CampaignRole::Master, 'status' => MemberStatus::Active,
        ])->exists();
    }

    /** @return array<string, int> */
    public function grants(CampaignCharacter $character): array
    {
        return CharacterGrant::query()
            ->where('campaign_character_id', $character->getKey())
            ->selectRaw('budget_id, SUM(amount) AS total')
            ->groupBy('budget_id')
            ->pluck('total', 'budget_id')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();
    }

    /** @param list<ChangeOperation> $operations */
    public function evaluate(CampaignCharacter $character, array $operations, Actor $actor): ChangeOutcome
    {
        return $this->engine($character->preset_version_id)->evaluate(
            (array) $character->sheet,
            $operations,
            $actor,
            self::phase($character),
            $this->grants($character),
        );
    }

    /**
     * Removes from a result every issue that would reveal a field the viewer cannot read; if any
     * was removed, a single path-less `validation.restricted` stands in for all of them.
     */
    public function filterFor(ValidationResult $result, CampaignCharacter $character, Actor $viewer): ValidationResult
    {
        if ($viewer === Actor::Master) {
            return $result;
        }
        $projector = $this->views->projector($character->preset_version_id);
        $kept = [];
        $restricted = false;
        foreach ($result->issues as $issue) {
            $readable = true;
            foreach ($issue->paths as $path) {
                if ($path === '' || !$projector->playerCanRead(JsonPointer::parse($path))) {
                    $readable = false;
                    break;
                }
            }
            if ($readable) {
                $kept[] = $issue;
            } elseif (!$restricted) {
                $restricted = true;
                $kept[] = new ValidationIssue('validation.restricted', $issue->severity);
            }
        }

        return new ValidationResult($kept);
    }

    public function sectionOf(CampaignCharacter $character, ChangeOperation $op): string
    {
        return $this->layout($character)->element($op->path)['section'] ?? 'unknown';
    }

    public static function error(string $rule, string $path = ''): ValidationResult
    {
        return new ValidationResult([new ValidationIssue($rule, Severity::Error, [], $path === '' ? [] : [$path])]);
    }
}
