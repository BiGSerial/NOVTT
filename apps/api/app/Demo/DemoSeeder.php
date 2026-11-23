<?php

declare(strict_types=1);

namespace App\Demo;

use App\Application\AuditTrail;
use App\Application\PresetImporter;
use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates (idempotently) the demonstration world: a master, a player, the neutral demo-basic
 * preset published through the normal three-stage importer, one campaign pinned to it and one
 * character in creation. Running it again reuses what already exists.
 */
final readonly class DemoSeeder
{
    public function __construct(private PresetImporter $importer, private AuditTrail $audit)
    {
    }

    /** @return array{master: User, player: User, version: PresetVersion, campaign: Campaign, character: CampaignCharacter} */
    public function seed(string $repositoryRoot): array
    {
        if (!DemoMode::enabled()) {
            throw new RuntimeException('Demo mode is disabled.');
        }
        [$raw, $missing] = PresetImporter::readDirectory($repositoryRoot . '/' . DemoMode::PRESET_DIRECTORY);
        if ($raw === null) {
            throw new RuntimeException("Demo preset is missing {$missing}.");
        }

        return DB::transaction(function () use ($raw): array {
            $master = $this->user(DemoMode::MASTER_EMAIL, 'Mestre (demo)');
            $player = $this->user(DemoMode::PLAYER_EMAIL, 'Jogador (demo)');
            [$package, $objects] = $this->importer->parse($raw);
            [$version] = $this->importer->import($master, $package, $objects, canonical: true);

            $campaign = Campaign::query()->where('owner_user_id', $master->getKey())->where('name', DemoMode::CAMPAIGN_NAME)->first();
            if ($campaign === null) {
                $campaign = Campaign::query()->create([
                    'owner_user_id' => $master->getKey(),
                    'preset_version_id' => $version->getKey(),
                    'name' => DemoMode::CAMPAIGN_NAME,
                    'description' => 'Dados fictícios criados por novtt:demo:seed.',
                ]);
                $this->audit->record($master, $campaign->getKey(), 'campaign.created', $campaign, ['preset_version_id' => $version->getKey(), 'demo' => true]);
            }
            $this->member($campaign, $master, CampaignRole::Master);
            $this->member($campaign, $player, CampaignRole::Player);

            $character = CampaignCharacter::query()->where('campaign_id', $campaign->getKey())->where('player_user_id', $player->getKey())->first()
                ?? CampaignCharacter::query()->create([
                    'campaign_id' => $campaign->getKey(),
                    'player_user_id' => $player->getKey(),
                    'preset_version_id' => $campaign->preset_version_id,
                    'name' => DemoMode::CHARACTER_NAME,
                ]);

            return ['master' => $master, 'player' => $player, 'version' => $version, 'campaign' => $campaign, 'character' => $character->refresh()];
        });
    }

    private function user(string $email, string $name): User
    {
        // The password is random and never shown: demo users can only sign in through the
        // local demo session endpoint.
        return User::query()->where('email', $email)->first()
            ?? User::query()->create(['name' => $name, 'email' => $email, 'password' => Str::password(48)]);
    }

    private function member(Campaign $campaign, User $user, CampaignRole $role): void
    {
        $exists = CampaignMember::query()->where('campaign_id', $campaign->getKey())->where('user_id', $user->getKey())->exists();
        if (!$exists) {
            CampaignMember::query()->create([
                'campaign_id' => $campaign->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);
        }
    }
}
