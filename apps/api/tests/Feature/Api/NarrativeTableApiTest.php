<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\Roteiro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-003: images and portraits, plot and stage, inventory and prep carry-over. */
final class NarrativeTableApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;

    private User $ana;

    private User $bruno;

    private Campaign $campaign;

    private CampaignCharacter $lyra;

    private CampaignNpc $npc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->ana = $this->makeUser('Ana');
        $this->bruno = $this->makeUser('Bruno');
        $this->campaign = $this->makeCampaign($this->master);
        $this->addMember($this->campaign, $this->ana);
        $this->addMember($this->campaign, $this->bruno);
        $this->lyra = $this->makeCharacter($this->campaign, $this->ana);
        $this->lyra->update(['name' => 'Lyra', 'sheet' => ['identity' => ['concept' => 'Batedora', 'motivation' => 'Achar o irmão']]]);
        $this->npc = CampaignNpc::query()->create(['campaign_id' => $this->campaign->getKey(), 'preset_version_id' => $this->campaign->preset_version_id, 'name' => 'Guardião', 'sheet' => [], 'sheet_revision' => 0, 'created_by' => $this->master->getKey()]);
    }

    private function base(): string
    {
        return "/api/v1/campaigns/{$this->campaign->getKey()}";
    }

    private function png(int $w = 40, int $h = 30, string $name = 'a.png'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 30));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        // A trailing payload after IEND must not survive re-encoding.
        $bytes .= '<script>alert(1)</script>';

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function upload(UploadedFile $file, string $kind = 'portrait', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->master)->post("{$this->base()}/images", ['kind' => $kind, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function openSession(?string $roteiroId = null): array
    {
        return $this->actingAs($this->master)->postJson("{$this->base()}/sessions", ['title' => 'Sessão', 'roteiro_id' => $roteiroId], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json();
    }

    /** @return list<array<string, mixed>> */
    private function eventsFor(User $user, string $sessionId): array
    {
        return $this->actingAs($user)->getJson("/api/v1/sessions/{$sessionId}/events?after=0")->assertOk()->json('data');
    }

    public function test_upload_reencodes_to_webp_and_rejects_non_images(): void
    {
        $image = $this->upload($this->png(2000, 1000))->assertCreated()->assertJsonPath('kind', 'portrait')->assertJsonPath('width', 1024)->assertJsonPath('height', 512);
        $res = $this->actingAs($this->master)->get("/api/v1/images/{$image->json('id')}")->assertOk();
        $res->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
        $body = $res->getContent();
        self::assertStringStartsWith('RIFF', $body);
        self::assertStringNotContainsString('<script>', $body);
        $this->actingAs($this->master)->get("/api/v1/images/{$image->json('id')}", ['If-None-Match' => (string) $res->headers->get('ETag')])->assertStatus(304);

        $this->upload(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422)->assertJsonPath('code', 'image.type_unsupported');
        $this->upload(UploadedFile::fake()->createWithContent('x.png', '<html><body>not a png</body></html>'))->assertStatus(422)->assertJsonPath('code', 'image.type_unsupported');
        $this->upload(UploadedFile::fake()->create('big.png', 5000, 'image/png'))->assertStatus(422);
        $this->upload($this->png(), 'banner')->assertStatus(422);
        $this->actingAs($this->master)->post("{$this->base()}/images", ['kind' => 'portrait', 'file' => $this->png(), 'extra' => '1'], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_images_need_a_session_even_without_json_accept(): void
    {
        $this->get('/api/v1/images/'.Str::uuid(), ['Accept' => 'image/webp'])->assertUnauthorized();
    }

    public function test_players_upload_only_portraits_and_outsiders_nothing(): void
    {
        $this->upload($this->png(), 'scene', $this->ana)->assertForbidden();
        $this->upload($this->png(), 'portrait', $this->ana)->assertCreated();
        $this->upload($this->png(), 'portrait', $this->makeUser())->assertNotFound();
    }

    public function test_portrait_assignment_and_visibility(): void
    {
        $mine = $this->upload($this->png(), 'portrait', $this->ana)->json('id');
        // Before assignment only the uploader and the master can read it.
        $this->actingAs($this->bruno)->get("/api/v1/images/{$mine}")->assertNotFound();
        $this->actingAs($this->ana)->get("/api/v1/images/{$mine}")->assertOk();

        $url = "{$this->base()}/portraits/character/{$this->lyra->getKey()}";
        $this->actingAs($this->bruno)->putJson($url, ['image_id' => $mine])->assertForbidden();
        $this->actingAs($this->ana)->putJson("{$this->base()}/portraits/npc/{$this->npc->getKey()}", ['image_id' => $mine])->assertNotFound();
        $session = $this->openSession();
        $this->actingAs($this->ana)->putJson($url, ['image_id' => $mine])->assertOk()->assertJsonPath('portrait_image_id', $mine);
        $this->actingAs($this->bruno)->get("/api/v1/images/{$mine}")->assertOk();
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonPath('characters.0.portrait_image_id', $mine)->assertJsonPath('characters.0.concept', null);
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonPath('characters.0.concept', 'Batedora');
        self::assertContains('portrait.updated', array_column($this->eventsFor($this->bruno, $session['id']), 'type'));

        $scene = $this->upload($this->png(), 'scene')->json('id');
        $this->actingAs($this->master)->putJson($url, ['image_id' => $scene])->assertStatus(422)->assertJsonPath('code', 'image.unknown');
    }

    public function test_npc_portrait_is_hidden_until_the_npc_is_revealed(): void
    {
        $session = $this->openSession();
        $portrait = $this->upload($this->png())->json('id');
        $this->actingAs($this->master)->putJson("{$this->base()}/portraits/npc/{$this->npc->getKey()}", ['image_id' => $portrait])->assertOk();
        self::assertNotContains('portrait.updated', array_column($this->eventsFor($this->ana, $session['id']), 'type'));
        $this->actingAs($this->ana)->get("/api/v1/images/{$portrait}")->assertNotFound();

        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/npc-reveals", ['campaign_npc_id' => $this->npc->getKey(), 'summary' => 'Um homem enorme'])->assertOk();
        $this->actingAs($this->ana)->get("/api/v1/images/{$portrait}")->assertOk();
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonPath('session.revealed_npcs.0.portrait_image_id', $portrait);
    }

    public function test_scene_and_handout_images_follow_the_stage_and_clues(): void
    {
        $session = $this->openSession();
        $art = $this->upload($this->png(), 'scene')->json('id');
        $handout = $this->upload($this->png(), 'handout')->json('id');
        $this->actingAs($this->ana)->get("/api/v1/images/{$art}")->assertNotFound();

        $stage = $this->actingAs($this->master)->putJson("/api/v1/sessions/{$session['id']}/stage", ['revision' => $session['revision'], 'stage' => ['title' => 'A ponte', 'text' => 'A névoa sobe.', 'art' => 'bridge', 'image_id' => $art]])->assertOk();
        $stage->assertJsonPath('stage.title', 'A ponte');
        $this->actingAs($this->ana)->get("/api/v1/images/{$art}")->assertOk();
        $event = collect($this->eventsFor($this->bruno, $session['id']))->firstWhere('type', 'stage.updated');
        self::assertSame('bridge', $event['payload']['stage']['art']);
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonPath('session.stage.text', 'A névoa sobe.');
        $this->actingAs($this->ana)->putJson("/api/v1/sessions/{$session['id']}/stage", ['revision' => $stage->json('revision'), 'stage' => null])->assertForbidden();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$session['id']}/stage", ['revision' => $session['revision'], 'stage' => null])->assertConflict();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$session['id']}/stage", ['revision' => $stage->json('revision'), 'stage' => ['title' => 'x', 'art' => 'spaceship']])->assertUnprocessable();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$session['id']}/stage", ['revision' => $stage->json('revision'), 'stage' => null])->assertOk()->assertJsonPath('stage', null);
        $this->actingAs($this->ana)->get("/api/v1/images/{$art}")->assertNotFound();

        $clue = $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/clues", ['title' => 'Mapa', 'body' => 'Um mapa', 'image_id' => $handout])->assertCreated()->assertJsonPath('image_id', $handout);
        $this->actingAs($this->ana)->get("/api/v1/images/{$handout}")->assertNotFound();
        $this->actingAs($this->master)->postJson("/api/v1/clues/{$clue->json('id')}/reveal", ['to' => 'user', 'user_id' => $this->ana->getKey()])->assertOk();
        $this->actingAs($this->ana)->get("/api/v1/images/{$handout}")->assertOk();
        $this->actingAs($this->bruno)->get("/api/v1/images/{$handout}")->assertNotFound();
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/clues", ['title' => 'x', 'body' => 'y', 'image_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_plot_walks_the_story_and_stays_with_the_master(): void
    {
        $roteiro = $this->actingAs($this->master)->postJson("{$this->base()}/roteiros", ['title' => 'S1', 'content' => [
            'scenes' => [
                ['title' => 'Ponte', 'goal' => 'Atravessar', 'location' => 'Vau', 'read_aloud' => 'A névoa.', 'npcs' => ['Guardião'], 'art' => 'bridge', 'complication' => 'Chuva', 'clues' => [['title' => 'Corda', 'body' => 'Cortada']]],
                ['title' => 'Cabana', 'goal' => 'Falar com a Corva', 'art' => 'swamp', 'clues' => [['title' => 'Pena', 'body' => 'Negra'], ['title' => 'Retrato', 'body' => 'Rasgado']]],
            ],
            'locations' => [['name' => 'Vau', 'aspects' => ['Limo', 'Sinos', 'Névoa']]],
            'rewards' => ['Barco'],
        ]])->assertCreated()->assertJsonPath('content.scenes.0.art', 'bridge')->assertJsonPath('content.locations.0.aspects.2', 'Névoa');
        $this->actingAs($this->master)->postJson("{$this->base()}/roteiros", ['title' => 'x', 'content' => ['scenes' => [['title' => 'a', 'goal' => '', 'clues' => [], 'art' => 'moon']]]])->assertUnprocessable();

        $session = $this->openSession($roteiro->json('id'));
        self::assertSame(0, $session['plot']['current']);
        self::assertSame(['current', 'pending'], array_column($session['plot']['scenes'], 'status'));
        self::assertSame('Ponte', $session['compass']['scene']);

        $scenes = $session['plot']['scenes'];
        $url = "/api/v1/sessions/{$session['id']}/plot";
        $this->actingAs($this->ana)->putJson($url, ['revision' => $session['revision'], 'plot' => ['scenes' => $scenes, 'current' => 1]])->assertForbidden();
        $next = $this->actingAs($this->master)->putJson($url, ['revision' => $session['revision'], 'plot' => ['scenes' => $scenes, 'current' => 1]])->assertOk();
        $next->assertJsonPath('plot.current', 1)->assertJsonPath('plot.scenes.0.status', 'done')->assertJsonPath('plot.scenes.1.status', 'current')
            ->assertJsonPath('compass.scene', 'Cabana')->assertJsonPath('compass.objective', 'Falar com a Corva')->assertJsonPath('compass.next_clue', 'Pena');
        $this->actingAs($this->master)->putJson($url, ['revision' => $session['revision'], 'plot' => ['scenes' => $scenes, 'current' => 0]])->assertConflict()->assertJsonPath('code', 'session.revision_mismatch');
        $this->actingAs($this->master)->putJson($url, ['revision' => $next->json('revision'), 'plot' => ['scenes' => $scenes, 'current' => 5]])->assertUnprocessable();
        // An improvised scene appended at the end.
        $more = [...$next->json('plot.scenes'), ['title' => 'Emboscada', 'goal' => 'Sobreviver', 'art' => 'forest']];
        $this->actingAs($this->master)->putJson($url, ['revision' => $next->json('revision'), 'plot' => ['scenes' => $more, 'current' => 2]])->assertOk()
            ->assertJsonPath('plot.scenes.2.id', 's3')->assertJsonPath('compass.scene', 'Emboscada')->assertJsonPath('compass.next_clue', '');

        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonMissingPath('session.plot')->assertJsonMissingPath('session.compass');
        self::assertNotContains('plot.updated', array_column($this->eventsFor($this->ana, $session['id']), 'type'));
        self::assertContains('plot.updated', array_column($this->eventsFor($this->master, $session['id']), 'type'));
    }

    public function test_items_are_master_managed_and_kept_when_omitted(): void
    {
        $session = $this->openSession();
        $url = "{$this->base()}/live-states/character/{$this->lyra->getKey()}";
        $body = ['revision' => 0, 'resources' => [], 'conditions' => [], 'items' => [['id' => 'i1', 'label' => 'Corda', 'qty' => 1, 'note' => '15 m']]];
        $this->actingAs($this->ana)->putJson($url, $body)->assertForbidden();
        $this->actingAs($this->master)->putJson($url, $body)->assertOk()->assertJsonPath('live.items.0.label', 'Corda');
        $this->actingAs($this->master)->putJson($url, ['revision' => 1, 'resources' => [], 'conditions' => ['Molhada']])->assertOk()->assertJsonPath('live.items.0.qty', 1);
        $this->actingAs($this->master)->putJson($url, ['revision' => 2, 'resources' => [], 'conditions' => [], 'items' => [['id' => 'i1', 'label' => 'x', 'qty' => 1000]]])->assertUnprocessable();
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$session['id']}/snapshot")->assertJsonPath('characters.0.live.items.0.note', '15 m');
    }

    public function test_carry_over_seeds_the_next_roteiro_for_the_master_only(): void
    {
        $session = $this->openSession();
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/clues", ['title' => 'Selo', 'body' => 'Recente', 'scene' => 'Ponte'])->assertCreated();
        $shown = $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/clues", ['title' => 'Mostrada', 'body' => 'Vista'])->json('id');
        $this->actingAs($this->master)->postJson("/api/v1/clues/{$shown}/reveal", ['to' => 'table'])->assertOk();
        $this->actingAs($this->master)->postJson("{$this->base()}/atlas", ['kind' => 'thread', 'title' => 'Onde está o irmão?', 'summary' => 'Sumiu', 'thread_status' => 'open'])->assertCreated();
        $this->actingAs($this->master)->postJson("{$this->base()}/atlas", ['kind' => 'thread', 'title' => 'Fechado', 'thread_status' => 'resolved'])->assertCreated();
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$session['id']}/end")->assertOk();
        Roteiro::query()->create(['campaign_id' => $this->campaign->getKey(), 'title' => 'S1', 'content' => [], 'revision' => 1]);

        $this->actingAs($this->ana)->getJson("{$this->base()}/prep/carry-over")->assertForbidden();
        $res = $this->actingAs($this->master)->getJson("{$this->base()}/prep/carry-over")->assertOk();
        $res->assertJsonCount(1, 'unrevealed_clues')->assertJsonPath('unrevealed_clues.0.title', 'Selo')
            ->assertJsonCount(1, 'open_threads')->assertJsonPath('open_threads.0.title', 'Onde está o irmão?')
            ->assertJsonPath('characters.0.motivation', 'Achar o irmão')->assertJsonPath('last_roteiro_title', 'S1');
    }
}
