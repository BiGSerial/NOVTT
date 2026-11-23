<?php

declare(strict_types=1);

namespace App\Demo;

use App\Application\AuditTrail;
use App\Application\PresetImporter;
use App\Domain\CampaignCharacterStatus;
use App\Domain\CampaignRole;
use App\Domain\MemberStatus;
use App\Models\AtlasEntry;
use App\Models\AtlasTimelineEntry;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\CampaignNpc;
use App\Models\CharacterSheetRevision;
use App\Models\LiveState;
use App\Models\MasterNote;
use App\Models\Roteiro;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Live-table demonstration (SPEC-MVP-002): a master, three players with approved characters,
 * NPCs, resources, a roteiro and an Atlas, on the original "Mesa NOVTT" preset. All fictional.
 * Idempotent: running it again reuses what exists.
 */
final readonly class TableDemoSeeder
{
    public const PRESET_DIRECTORY = 'presets/fixtures/novtt-mesa/1.0.0';

    public const CAMPAIGN_NAME = 'A Ponte de Vau Cinzento (demo)';

    /** Demo accounts: login role => [email, name]. */
    public const PLAYERS = [
        'player' => [DemoMode::PLAYER_EMAIL, 'Ana (demo)'],
        'player2' => ['jogador2@demo.novtt.invalid', 'Bruno (demo)'],
        'player3' => ['jogador3@demo.novtt.invalid', 'Carla (demo)'],
    ];

    private const CHARACTERS = [
        'player' => ['Lyra', 'Batedora de passo leve', ['vigor' => 2, 'agilidade' => 4, 'astucia' => 3, 'presenca' => 2], ['atletismo' => 2, 'furtividade' => 3, 'investigacao' => 2, 'pontaria' => 2, 'sobrevivencia' => 1],
            'Encontrar o irmão desaparecido no rio.', 'Água funda.', 'Deve a vida a Bram.', 'Arco curto, corda, capa de musgo', 'Viu o barqueiro na noite do sumiço.'],
        'player2' => ['Bram', 'Ferreiro que largou a forja', ['vigor' => 4, 'agilidade' => 2, 'astucia' => 2, 'presenca' => 3], ['atletismo' => 3, 'luta' => 3, 'persuasao' => 1, 'sobrevivencia' => 2],
            'Pagar a dívida com a guilda.', 'Voltar a ser covarde.', 'Protege Lyra como uma irmã.', 'Martelo, avental de couro, 12 moedas', 'Forjou a chave que abre a eclusa.'],
        'player3' => ['Nix', 'Erudita de sorriso fácil', ['vigor' => 1, 'agilidade' => 3, 'astucia' => 4, 'presenca' => 3], ['investigacao' => 3, 'saber' => 3, 'persuasao' => 2, 'furtividade' => 1],
            'Provar que a Mãe Corva existe.', 'Ser esquecida.', 'Rival amigável de Lyra.', 'Diário, lanterna, tinta que brilha', 'Carrega uma carta selada da Mãe Corva.'],
    ];

    private const NPCS = [
        ['Guardião da Ponte', ['vigor' => 4, 'agilidade' => 2, 'astucia' => 2, 'presenca' => 3], ['luta' => 3, 'atletismo' => 2], 'Voz grave, frases curtas. Quer cobrar o pedágio a qualquer custo.', 12],
        ['Mãe Corva', ['vigor' => 1, 'agilidade' => 3, 'astucia' => 5, 'presenca' => 4], ['saber' => 4, 'persuasao' => 3, 'furtividade' => 2], 'Fala em perguntas. Quer o diário de Nix.', 8],
        ['Oren, o barqueiro', ['vigor' => 2, 'agilidade' => 3, 'astucia' => 3, 'presenca' => 2], ['furtividade' => 2, 'persuasao' => 2], 'Gagueja quando mente. Esconde o irmão de Lyra.', 7],
    ];

    public function __construct(private PresetImporter $importer, private AuditTrail $audit)
    {
    }

    /** @return array{campaign: Campaign} */
    public function seed(string $repositoryRoot): array
    {
        if (!DemoMode::enabled()) {
            throw new RuntimeException('Demo mode is disabled.');
        }
        [$raw, $missing] = PresetImporter::readDirectory($repositoryRoot . '/' . self::PRESET_DIRECTORY);
        if ($raw === null) {
            throw new RuntimeException("Table demo preset is missing {$missing}.");
        }

        return DB::transaction(function () use ($raw): array {
            $master = $this->user(DemoMode::MASTER_EMAIL, 'Mestre (demo)');
            [$package, $objects] = $this->importer->parse($raw);
            [$version] = $this->importer->import($master, $package, $objects, canonical: true);

            $campaign = Campaign::query()->where('owner_user_id', $master->getKey())->where('name', self::CAMPAIGN_NAME)->first();
            if ($campaign !== null) {
                return ['campaign' => $campaign];
            }
            $campaign = Campaign::query()->create([
                'owner_user_id' => $master->getKey(), 'preset_version_id' => $version->getKey(), 'name' => self::CAMPAIGN_NAME,
                'description' => 'Mesa ao vivo de demonstração (dados fictícios, mundo original).',
            ]);
            $this->audit->record($master, $campaign->getKey(), 'campaign.created', $campaign, ['preset_version_id' => $version->getKey(), 'demo' => true]);
            $this->member($campaign, $master, CampaignRole::Master);

            $characters = [];
            foreach (self::PLAYERS as $role => [$email, $name]) {
                $player = $this->user($email, $name);
                $this->member($campaign, $player, CampaignRole::Player);
                [$cname, $concept, $attrs, $skills, $wants, $fear, $bonds, $gear, $hook] = self::CHARACTERS[$role];
                $sheet = [
                    'identity' => ['name' => $cname, 'concept' => $concept, 'motivation' => $wants, 'fear' => $fear, 'bonds' => $bonds],
                    'attributes' => $attrs, 'skills' => $skills + array_fill_keys(['atletismo', 'furtividade', 'investigacao', 'persuasao', 'luta', 'pontaria', 'saber', 'sobrevivencia'], 0),
                    'gear' => ['items' => $gear], 'secrets' => ['hook' => $hook], 'master' => ['notes' => ''],
                ];
                $character = CampaignCharacter::query()->create([
                    'campaign_id' => $campaign->getKey(), 'player_user_id' => $player->getKey(), 'preset_version_id' => $version->getKey(),
                    'name' => $cname, 'status' => CampaignCharacterStatus::Approved, 'sheet' => $sheet, 'sheet_revision' => 1,
                    'approved_by' => $master->getKey(), 'approved_at' => now(),
                ]);
                CharacterSheetRevision::query()->create(['campaign_character_id' => $character->getKey(), 'revision' => 1, 'sheet' => $sheet, 'created_by' => $master->getKey(), 'created_at' => now()]);
                $this->live($campaign, 'character', $character->getKey(), [['vida', 'Vida', 5 + $attrs['vigor'], 5 + $attrs['vigor']], ['vontade', 'Vontade', 3 + $attrs['presenca'], 3 + $attrs['presenca']]]);
                $characters[$cname] = $character;
            }

            foreach (self::NPCS as [$name, $attrs, $skills, $notes, $life]) {
                $npc = CampaignNpc::query()->create([
                    'campaign_id' => $campaign->getKey(), 'preset_version_id' => $version->getKey(), 'name' => $name,
                    'sheet' => ['identity' => ['name' => $name], 'attributes' => $attrs, 'skills' => $skills, 'master' => ['notes' => $notes]],
                    'sheet_revision' => 0, 'created_by' => $master->getKey(),
                ]);
                $this->live($campaign, 'npc', $npc->getKey(), [['vida', 'Vida', $life, $life]]);
            }

            Roteiro::query()->create(['campaign_id' => $campaign->getKey(), 'title' => 'Sessão 1 · A ponte que canta', 'revision' => 1, 'content' => [
                'strong_start' => 'A ponte de Vau Cinzento range e uma voz canta debaixo dela: “Pague, ou o rio paga por vocês”.',
                'scenes' => [
                    ['title' => 'O pedágio', 'goal' => 'Atravessar a ponte sem pagar com sangue', 'clues' => [['title' => 'Corda cortada', 'body' => 'A corda de segurança foi cortada com lâmina fina, há pouco tempo.'], ['title' => 'Moedas molhadas', 'body' => 'O Guardião guarda moedas que ainda pingam água do rio.']]],
                    ['title' => 'A cabana da Mãe Corva', 'goal' => 'Descobrir o que ela sabe do irmão de Lyra', 'clues' => [['title' => 'Pena de corvo no diário', 'body' => 'Uma pena marca a página sobre a eclusa.']]],
                    ['title' => 'A eclusa', 'goal' => 'Abrir a eclusa antes da cheia', 'clues' => [['title' => 'A chave do ferreiro', 'body' => 'A fechadura tem a marca da forja de Bram.']]],
                ],
                'npcs' => [
                    ['name' => 'Guardião da Ponte', 'voice' => 'Grave, frases curtas', 'wants' => 'Cobrar o pedágio', 'secret' => 'Trabalha para Oren'],
                    ['name' => 'Mãe Corva', 'voice' => 'Fala em perguntas', 'wants' => 'O diário de Nix', 'secret' => 'É a mãe do irmão desaparecido'],
                ],
                'clocks' => [['label' => 'Cheia do rio', 'segments' => 6], ['label' => 'Paciência do Guardião', 'segments' => 4]],
                'wants' => [['character' => 'Lyra', 'text' => 'Uma pista real do irmão'], ['character' => 'Bram', 'text' => 'Uma chance de ser corajoso'], ['character' => 'Nix', 'text' => 'Ver a Mãe Corva de perto']],
            ]]);

            $atlas = [];
            foreach ([
                ['place', 'Vau Cinzento', 'Aldeia ribeirinha de pescadores e contrabandistas.', 'A eclusa controla a cheia; quem a controla manda na aldeia.', true, null],
                ['person', 'Mãe Corva', 'Velha que vive na margem e conversa com corvos.', 'Mãe do irmão desaparecido; quer proteger o filho de Oren.', true, null],
                ['faction', 'Guilda da Forja', 'Ferreiros que cobram dívidas antigas.', 'Bram deve 40 moedas; mandaram um cobrador.', false, null],
                ['secret', 'Oren esconde o irmão de Lyra', '', 'Na casa de barcos, atrás da eclusa.', false, null],
                ['thread', 'Onde está o irmão de Lyra?', 'Ele sumiu na noite da última cheia.', 'Resolve-se na eclusa.', true, 'open'],
            ] as [$kind, $title, $summary, $details, $known, $status]) {
                $atlas[$title] = AtlasEntry::query()->create(['campaign_id' => $campaign->getKey(), 'kind' => $kind, 'title' => $title, 'summary' => $summary, 'details' => $details, 'known_to_players' => $known, 'thread_status' => $status, 'links' => [], 'revision' => 1]);
            }
            $atlas['Onde está o irmão de Lyra?']->update(['links' => [$atlas['Vau Cinzento']->getKey(), $atlas['Oren esconde o irmão de Lyra']->getKey()]]);
            AtlasTimelineEntry::query()->create(['entry_id' => $atlas['Onde está o irmão de Lyra?']->getKey(), 'session_id' => null, 'note' => 'Prólogo: Lyra encontra o barco vazio do irmão.', 'created_by' => $master->getKey(), 'created_at' => now()->subDays(7)]);

            MasterNote::query()->create(['campaign_id' => $campaign->getKey(), 'author_user_id' => $master->getKey(), 'body' => 'Nomes de improviso: Tobias (pescador), Vela (taverneira), Sino (cachorro do Guardião).', 'tags' => ['nomes'], 'pinned' => true, 'revision' => 1]);

            return ['campaign' => $campaign];
        });
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: int}> $tracks */
    private function live(Campaign $campaign, string $kind, string $id, array $tracks): void
    {
        LiveState::query()->create([
            'campaign_id' => $campaign->getKey(), 'subject_kind' => $kind, 'subject_id' => $id,
            'resources' => array_map(static fn (array $t): array => ['id' => $t[0], 'label' => $t[1], 'current' => $t[2], 'max' => $t[3]], $tracks),
            'conditions' => [], 'revision' => 1,
        ]);
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
