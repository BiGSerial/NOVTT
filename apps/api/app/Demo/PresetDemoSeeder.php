<?php

declare(strict_types=1);

namespace App\Demo;

use App\Application\AuditTrail;
use App\Application\PresetImporter;
use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo campaigns for the authorial rules/2 presets (SPEC-PRESET-ENGINE-001): the demo master runs
 * one campaign per preset and every demo player is a member without a character, so players can
 * build one with the guided creation and the master can review and activate it.
 * Idempotent: running it again reuses what exists.
 */
final readonly class PresetDemoSeeder
{
    /** Preset directory => [campaign name, description]. */
    public const CAMPAIGNS = [
        'presets/novtt-demo/demo-linhagens/1.0.0' => ['Linhagens da Névoa (demo)', 'Reserva de d10, prioridades e experiência. Crie seu personagem e envie ao mestre.'],
        'presets/novtt-demo/demo-heroico/1.0.0' => ['Heroico: a Estrada de Sal (demo)', 'd20 com modificadores e níveis por experiência ou marcos. Crie seu personagem e envie ao mestre.'],
        // Third-party presets under rights review: imported into the master's own library only.
        'presets/ordem-paranormal-rpg/livro-basico/0.1.1' => ['Ordem Paranormal (teste interno)', 'Conteúdo não oficial, publicado sob a Licença da Comunidade de Ordem Paranormal. Uso interno: publicação bloqueada até revisão jurídica.', false],
    ];

    public function __construct(private PresetImporter $importer, private AuditTrail $audit)
    {
    }

    /** @return list<Campaign> */
    public function seed(string $repositoryRoot): array
    {
        if (!DemoMode::enabled()) {
            throw new RuntimeException('Demo mode is disabled.');
        }
        $campaigns = [];
        foreach (self::CAMPAIGNS as $directory => $entry) {
            [$name, $description] = $entry;
            $canonical = $entry[2] ?? true;
            [$raw, $missing] = PresetImporter::readDirectory($repositoryRoot . '/' . $directory);
            if ($raw === null) {
                throw new RuntimeException("Demo preset {$directory} is missing {$missing}.");
            }
            $campaigns[] = DB::transaction(function () use ($raw, $name, $description, $canonical): Campaign {
                $master = $this->user(DemoMode::MASTER_EMAIL, 'Mestre (demo)');
                [$package, $objects] = $this->importer->parse($raw);
                [$version] = $this->importer->import($master, $package, $objects, canonical: $canonical);
                $campaign = Campaign::query()->where('owner_user_id', $master->getKey())->where('name', $name)->first();
                if ($campaign === null) {
                    $campaign = Campaign::query()->create([
                        'owner_user_id' => $master->getKey(), 'preset_version_id' => $version->getKey(), 'name' => $name, 'description' => $description,
                    ]);
                    $this->audit->record($master, $campaign->getKey(), 'campaign.created', $campaign, ['preset_version_id' => $version->getKey(), 'demo' => true]);
                }
                $this->member($campaign, $master, CampaignRole::Master);
                foreach (TableDemoSeeder::PLAYERS as [$email, $playerName]) {
                    $this->member($campaign, $this->user($email, $playerName), CampaignRole::Player);
                }

                return $campaign;
            });
        }

        return $campaigns;
    }

    private function user(string $email, string $name): User
    {
        return User::query()->where('email', $email)->first()
            ?? User::query()->create(['name' => $name, 'email' => $email, 'password' => Str::password(48)]);
    }

    private function member(Campaign $campaign, User $user, CampaignRole $role): void
    {
        if (CampaignMember::query()->where('campaign_id', $campaign->getKey())->where('user_id', $user->getKey())->doesntExist()) {
            CampaignMember::query()->create(['campaign_id' => $campaign->getKey(), 'user_id' => $user->getKey(), 'role' => $role, 'status' => MemberStatus::Active, 'joined_at' => now()]);
        }
    }
}
