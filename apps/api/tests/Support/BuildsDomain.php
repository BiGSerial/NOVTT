<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\CampaignRole;
use App\Domain\ChangeRequestStatus;
use App\Domain\MemberStatus;
use App\Domain\PresetVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\ChangeRequest;
use App\Models\PresetDefinition;
use App\Models\PresetFamily;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Support\Str;

/** Minimal builders for domain rows; uses the neutral demo preset fixture. */
trait BuildsDomain
{
    protected const DEMO_PRESET = __DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0';

    protected function makeUser(string $name = 'User'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::lower(Str::random(12)) . '@example.test',
            'password' => 'not-used-in-tests',
        ]);
    }

    /** @return array<string, mixed> */
    protected function demoFile(string $file): array
    {
        return json_decode((string) file_get_contents(self::DEMO_PRESET . '/' . $file), true, 64, JSON_THROW_ON_ERROR);
    }

    protected function makePresetVersion(
        User $importer,
        PresetVersionStatus $status = PresetVersionStatus::Published,
        ?User $owner = null,
        string $version = '1.0.0',
    ): PresetVersion {
        $family = PresetFamily::query()->create([
            'owner_user_id' => $owner?->getKey(),
            'slug' => 'fam-' . Str::lower(Str::random(8)),
            'display_name' => 'Family',
        ]);
        $definition = PresetDefinition::query()->create([
            'family_id' => $family->getKey(),
            'owner_user_id' => $owner?->getKey(),
            'variant_slug' => 'variant',
            'display_name' => 'Variant',
        ]);

        return PresetVersion::query()->create([
            'definition_id' => $definition->getKey(),
            'version' => $version,
            'status' => $status,
            'content_hash' => hash('sha256', Str::random()),
            'manifest' => $this->demoFile('manifest.json'),
            'sheet_schema' => $this->demoFile('sheet.schema.json'),
            'sheet_ui' => $this->demoFile('sheet.ui.json'),
            'rules' => $this->demoFile('rules.json'),
            'validation_report' => ['contract' => 'novtt.validation-result/1', 'valid' => true, 'issues' => []],
            'engine_version' => '0.1.0',
            'imported_by' => $importer->getKey(),
            'published_at' => $status === PresetVersionStatus::Published ? now() : null,
        ]);
    }

    protected function makeCampaign(User $master, ?PresetVersion $version = null): Campaign
    {
        $version ??= $this->makePresetVersion($master);
        $campaign = Campaign::query()->create([
            'owner_user_id' => $master->getKey(),
            'preset_version_id' => $version->getKey(),
            'name' => 'Campaign',
        ]);
        $this->addMember($campaign, $master, CampaignRole::Master);

        return $campaign;
    }

    protected function addMember(Campaign $campaign, User $user, CampaignRole $role = CampaignRole::Player): CampaignMember
    {
        return CampaignMember::query()->create([
            'campaign_id' => $campaign->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ]);
    }

    protected function makeCharacter(Campaign $campaign, User $player): CampaignCharacter
    {
        return CampaignCharacter::query()->create([
            'campaign_id' => $campaign->getKey(),
            'player_user_id' => $player->getKey(),
            'preset_version_id' => $campaign->preset_version_id,
            'name' => 'Hero',
        ]);
    }

    protected function makeChangeRequest(CampaignCharacter $character, User $author, ChangeRequestStatus $status = ChangeRequestStatus::Submitted): ChangeRequest
    {
        return ChangeRequest::query()->create([
            'campaign_character_id' => $character->getKey(),
            'author_user_id' => $author->getKey(),
            'status' => $status,
            'base_sheet_revision' => 0,
            'draft_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
            'submitted_at' => now(),
        ]);
    }
}
