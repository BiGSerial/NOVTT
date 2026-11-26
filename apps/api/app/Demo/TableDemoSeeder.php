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
 * Live-table demonstration (SPEC-MVP-002/003): a master, five players with approved characters,
 * ten NPCs, resources and items, a five-scene roteiro and an Atlas, on the original "Mesa NOVTT" preset. All fictional.
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
        'player4' => ['jogador4@demo.novtt.invalid', 'Davi (demo)'],
        'player5' => ['jogador5@demo.novtt.invalid', 'Eva (demo)'],
    ];

    private const CHARACTERS = [
        'player' => ['Lyra', 'Batedora de passo leve', ['vigor' => 2, 'agilidade' => 4, 'astucia' => 3, 'presenca' => 2], ['atletismo' => 2, 'furtividade' => 3, 'investigacao' => 2, 'pontaria' => 2, 'sobrevivencia' => 1],
            'Encontrar o irmão desaparecido no rio.', 'Água funda.', 'Deve a vida a Bram.', 'Arco curto, corda, capa de musgo', 'Viu o barqueiro na noite do sumiço.'],
        'player2' => ['Bram', 'Ferreiro que largou a forja', ['vigor' => 4, 'agilidade' => 2, 'astucia' => 2, 'presenca' => 3], ['atletismo' => 3, 'luta' => 3, 'persuasao' => 1, 'sobrevivencia' => 2],
            'Pagar a dívida com a guilda.', 'Voltar a ser covarde.', 'Protege Lyra como uma irmã.', 'Martelo, avental de couro, 12 moedas', 'Forjou a chave que abre a eclusa.'],
        'player3' => ['Nix', 'Erudita de sorriso fácil', ['vigor' => 1, 'agilidade' => 3, 'astucia' => 4, 'presenca' => 3], ['investigacao' => 3, 'saber' => 3, 'persuasao' => 2, 'furtividade' => 1],
            'Provar que a Mãe Corva existe.', 'Ser esquecida.', 'Rival amigável de Lyra.', 'Diário, lanterna, tinta que brilha', 'Carrega uma carta selada da Mãe Corva.'],
        'player4' => ['Tomé', 'Barqueiro aposentado e rabugento', ['vigor' => 3, 'agilidade' => 2, 'astucia' => 3, 'presenca' => 2], ['sobrevivencia' => 3, 'atletismo' => 2, 'luta' => 1, 'saber' => 1],
            'Reaver o barco que Oren lhe tomou.', 'Morrer em terra firme.', 'Ensinou Lyra a nadar.', 'Remo de freixo, rede, cachimbo', 'Sabe a passagem secreta sob a eclusa.'],
        'player5' => ['Selene', 'Cantora de feira com voz de sereia', ['vigor' => 2, 'agilidade' => 3, 'astucia' => 2, 'presenca' => 4], ['persuasao' => 3, 'furtividade' => 2, 'pontaria' => 1, 'saber' => 1],
            'Descobrir quem canta debaixo da ponte.', 'Perder a voz.', 'Deve um favor a Nix.', 'Alaúde, faca curta, frasco de mel', 'A voz debaixo da ponte é a da sua irmã.'],
    ];

    /** Starting inventory per character (live items, master-managed). */
    private const ITEMS = [
        'Lyra' => [['Flechas', 12], ['Corda (15 m)', 1], ['Ração seca', 3]],
        'Bram' => [['Martelo de forja', 1], ['Moedas', 12], ['Tocha', 2]],
        'Nix' => [['Diário de campo', 1], ['Lanterna', 1], ['Carta selada', 1]],
        'Tomé' => [['Remo de freixo', 1], ['Rede de pesca', 1], ['Fumo de cachimbo', 5]],
        'Selene' => [['Alaúde', 1], ['Frasco de mel', 2], ['Moedas', 7]],
    ];

    private const NPCS = [
        ['Guardião da Ponte', ['vigor' => 4, 'agilidade' => 2, 'astucia' => 2, 'presenca' => 3], ['luta' => 3, 'atletismo' => 2], 'Voz grave, frases curtas. Quer cobrar o pedágio a qualquer custo.', 12],
        ['Mãe Corva', ['vigor' => 1, 'agilidade' => 3, 'astucia' => 5, 'presenca' => 4], ['saber' => 4, 'persuasao' => 3, 'furtividade' => 2], 'Fala em perguntas. Quer o diário de Nix.', 8],
        ['Oren, o barqueiro', ['vigor' => 2, 'agilidade' => 3, 'astucia' => 3, 'presenca' => 2], ['furtividade' => 2, 'persuasao' => 2], 'Gagueja quando mente. Esconde o irmão de Lyra.', 7],
        ['Capitã Ilsa Morn', ['vigor' => 3, 'agilidade' => 3, 'astucia' => 3, 'presenca' => 4], ['luta' => 3, 'persuasao' => 2, 'pontaria' => 2], 'Olhar de quem já perdeu muito. Quer a eclusa sob a lei.', 10],
        ['Velho Barqueiro', ['vigor' => 1, 'agilidade' => 1, 'astucia' => 3, 'presenca' => 2], ['saber' => 3, 'sobrevivencia' => 2], 'Conta histórias sem fim. Sabe quem canta na ponte.', 5],
        ['Irmã Fenda', ['vigor' => 2, 'agilidade' => 2, 'astucia' => 4, 'presenca' => 3], ['saber' => 3, 'investigacao' => 3], 'Sussurra orações ao rio. Vende segredos por velas.', 6],
        ['Corvo-sentinela', ['vigor' => 1, 'agilidade' => 5, 'astucia' => 2, 'presenca' => 1], ['furtividade' => 4], 'Olhos da Mãe Corva. Grasna quando alguém mente.', 3],
        ['Contrabandista Juca', ['vigor' => 2, 'agilidade' => 4, 'astucia' => 3, 'presenca' => 3], ['furtividade' => 3, 'persuasao' => 3, 'luta' => 1], 'Sorriso de ouro falso. Quer passar a carga antes da cheia.', 7],
        ['Lobo Cinzento', ['vigor' => 4, 'agilidade' => 4, 'astucia' => 1, 'presenca' => 2], ['luta' => 3, 'atletismo' => 3], 'Ameaça. Caça em matilha na névoa da margem.', 9],
        ['Eco da Ponte', ['vigor' => 3, 'agilidade' => 2, 'astucia' => 4, 'presenca' => 5], ['persuasao' => 4, 'saber' => 2], 'Ameaça. Repete as palavras de quem atravessa, distorcidas.', 14],
    ];

    public function __construct(private PresetImporter $importer, private AuditTrail $audit) {}

    /** @return array{campaign: Campaign} */
    public function seed(string $repositoryRoot): array
    {
        if (! DemoMode::enabled()) {
            throw new RuntimeException('Demo mode is disabled.');
        }
        [$raw, $missing] = PresetImporter::readDirectory($repositoryRoot.'/'.self::PRESET_DIRECTORY);
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
                $this->live($campaign, 'character', $character->getKey(), [['vida', 'Vida', 5 + $attrs['vigor'], 5 + $attrs['vigor']], ['vontade', 'Vontade', 3 + $attrs['presenca'], 3 + $attrs['presenca']]], self::ITEMS[$cname] ?? []);
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
                    ['title' => 'O pedágio', 'goal' => 'Atravessar a ponte sem pagar com sangue', 'location' => 'Ponte de Vau Cinzento', 'art' => 'bridge',
                        'read_aloud' => 'A névoa sobe do rio como fumaça de forja apagada. A ponte de tábuas geme sob o peso de vocês, e lá embaixo, entre os pilares cobertos de limo, alguém canta uma canção que ninguém aqui deveria conhecer.',
                        'npcs' => ['Guardião da Ponte', 'Eco da Ponte'], 'complication' => 'O Eco repete em voz alta o segredo de quem falar primeiro.',
                        'clues' => [['title' => 'Corda cortada', 'body' => 'A corda de segurança foi cortada com lâmina fina, há pouco tempo.'], ['title' => 'Moedas molhadas', 'body' => 'O Guardião guarda moedas que ainda pingam água do rio.']]],
                    ['title' => 'A estrada da margem', 'goal' => 'Chegar à cabana antes do anoitecer', 'location' => 'Trilha dos salgueiros', 'art' => 'road',
                        'read_aloud' => 'A trilha segue colada ao rio, entre salgueiros que choram até a água. Pegadas grandes demais para um cão cortam a lama, e todas apontam para o mesmo lado que vocês.',
                        'npcs' => ['Lobo Cinzento', 'Contrabandista Juca'], 'complication' => 'A matilha cerca quem ficar para trás.',
                        'clues' => [['title' => 'Carga escondida', 'body' => 'Caixotes com o selo da Guilda da Forja, enterrados na lama.']]],
                    ['title' => 'A cabana da Mãe Corva', 'goal' => 'Descobrir o que ela sabe do irmão de Lyra', 'location' => 'Cabana sobre palafitas', 'art' => 'swamp',
                        'read_aloud' => 'A cabana se equilibra sobre palafitas tortas, coberta de penas negras. Dezenas de corvos se calam ao mesmo tempo quando vocês pisam na primeira tábua.',
                        'npcs' => ['Mãe Corva', 'Corvo-sentinela'], 'complication' => 'Ela só responde a quem lhe der algo de valor sentimental.',
                        'clues' => [['title' => 'Pena de corvo no diário', 'body' => 'Uma pena marca a página sobre a eclusa.'], ['title' => 'Retrato rasgado', 'body' => 'Metade de um retrato: um menino com o rosto de Lyra.']]],
                    ['title' => 'A taverna do Sino', 'goal' => 'Convencer a Capitã a não fechar a eclusa', 'location' => 'Taverna do Sino Rachado', 'art' => 'tavern',
                        'read_aloud' => 'O calor da lareira e o cheiro de peixe frito batem no rosto de vocês. No canto, a Capitã Ilsa Morn joga cartas sozinha, e o Velho Barqueiro já está no meio de uma história que ninguém pediu.',
                        'npcs' => ['Capitã Ilsa Morn', 'Velho Barqueiro', 'Irmã Fenda'], 'complication' => 'A Irmã Fenda vende para a Capitã o que ouvir na mesa.',
                        'clues' => [['title' => 'A dívida da guilda', 'body' => 'A Capitã tem a promissória de Bram assinada pela guilda.']]],
                    ['title' => 'A eclusa', 'goal' => 'Abrir a eclusa antes da cheia', 'location' => 'Eclusa velha e casa de barcos', 'art' => 'ruins',
                        'read_aloud' => 'As engrenagens da eclusa estão cobertas de ferrugem e de flores do rio. A água já sobe pelos degraus de pedra, e de dentro da casa de barcos vem uma luz de lamparina.',
                        'npcs' => ['Oren, o barqueiro'], 'complication' => 'Abrir a eclusa inunda a casa de barcos, onde está o irmão.',
                        'clues' => [['title' => 'A chave do ferreiro', 'body' => 'A fechadura tem a marca da forja de Bram.'], ['title' => 'Passagem submersa', 'body' => 'Uma corrente de ar sai por baixo das pedras: há um túnel.']]],
                ],
                'npcs' => [
                    ['name' => 'Guardião da Ponte', 'voice' => 'Grave, frases curtas', 'wants' => 'Cobrar o pedágio', 'secret' => 'Trabalha para Oren'],
                    ['name' => 'Mãe Corva', 'voice' => 'Fala em perguntas', 'wants' => 'O diário de Nix', 'secret' => 'É a mãe do irmão desaparecido'],
                    ['name' => 'Capitã Ilsa Morn', 'voice' => 'Seca, militar', 'wants' => 'Ordem na eclusa', 'secret' => 'Também deve à guilda'],
                ],
                'clocks' => [['label' => 'Cheia do rio', 'segments' => 6], ['label' => 'Paciência do Guardião', 'segments' => 4], ['label' => 'A matilha se aproxima', 'segments' => 4]],
                'wants' => [['character' => 'Lyra', 'text' => 'Uma pista real do irmão'], ['character' => 'Bram', 'text' => 'Uma chance de ser corajoso'], ['character' => 'Nix', 'text' => 'Ver a Mãe Corva de perto'], ['character' => 'Tomé', 'text' => 'Ver o barco de volta'], ['character' => 'Selene', 'text' => 'Ouvir a voz da irmã']],
                'locations' => [
                    ['name' => 'Ponte de Vau Cinzento', 'aspects' => ['Tábuas que cantam ao vento', 'Pilares cobertos de limo verde', 'Sinos pendurados por pescadores mortos']],
                    ['name' => 'Cabana sobre palafitas', 'aspects' => ['Penas negras em cada fresta', 'Cheiro de ervas queimadas', 'Um espelho que não reflete corvos']],
                    ['name' => 'Eclusa velha', 'aspects' => ['Engrenagens do tamanho de carroças', 'Flores que crescem na ferrugem', 'Água subindo degrau a degrau']],
                ],
                'rewards' => ['Barco de Tomé de volta', 'Promissória de Bram rasgada', 'Mapa da passagem submersa', 'Favor da Mãe Corva'],
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

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $tracks
     * @param  list<array{0: string, 1: int}>  $items
     */
    private function live(Campaign $campaign, string $kind, string $id, array $tracks, array $items = []): void
    {
        LiveState::query()->create([
            'campaign_id' => $campaign->getKey(), 'subject_kind' => $kind, 'subject_id' => $id,
            'resources' => array_map(static fn (array $t): array => ['id' => $t[0], 'label' => $t[1], 'current' => $t[2], 'max' => $t[3]], $tracks),
            'conditions' => [],
            'items' => array_map(static fn (array $i, int $n): array => ['id' => 'i'.($n + 1), 'label' => $i[0], 'qty' => $i[1], 'note' => ''], $items, array_keys($items)),
            'revision' => 1,
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
