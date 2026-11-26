<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-003 NT-10: the master sets the table atmosphere (time of day, weather); everyone sees it. */
final class AtmosphereApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private const VALID = ['hour' => 18.5, 'weather' => 'rain', 'intensity' => 0.666, 'time_scale' => 10, 'transition_seconds' => 6];

    private User $master;

    private User $ana;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->ana = $this->makeUser('Ana');
        $this->campaign = $this->makeCampaign($this->master);
        $this->addMember($this->campaign, $this->ana);
    }

    private function openSession(): string
    {
        return $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'Sessão'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    public function test_master_sets_the_atmosphere_and_the_table_sees_it(): void
    {
        $id = $this->openSession();
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$id}/snapshot")->assertOk()->assertJsonPath('session.atmosphere', null);

        $first = $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", self::VALID)->assertOk();
        $first->assertJsonPath('hour', 18.5)->assertJsonPath('weather', 'rain')->assertJsonPath('intensity', 0.67)
            ->assertJsonPath('time_scale', 10)->assertJsonPath('transition_seconds', 6)->assertJsonPath('revision', 1);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $first->json('set_at'));

        $snapshot = $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$id}/snapshot")->assertOk();
        $snapshot->assertJsonPath('session.atmosphere.weather', 'rain')->assertJsonPath('session.atmosphere.revision', 1);

        $event = collect($this->actingAs($this->ana)->getJson("/api/v1/sessions/{$id}/events?after=0")->assertOk()->json('data'))->firstWhere('type', 'atmosphere.updated');
        self::assertNotNull($event, 'players receive the table event');
        self::assertSame('rain', $event['payload']['weather']);
        self::assertSame(1, $event['payload']['revision']);

        $second = $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", ['hour' => 6, 'weather' => 'clear', 'intensity' => 0, 'time_scale' => 0, 'transition_seconds' => 0])->assertOk();
        $second->assertJsonPath('revision', 2)->assertJsonPath('weather', 'clear');
        $first->assertJsonPath('fog', 0); // omitted fog means none
    }

    public function test_fog_is_a_layer_that_coexists_with_any_weather(): void
    {
        $id = $this->openSession();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", ['weather' => 'storm', 'fog' => 0.456] + self::VALID)
            ->assertOk()->assertJsonPath('weather', 'storm')->assertJsonPath('fog', 0.46);
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$id}/snapshot")->assertJsonPath('session.atmosphere.fog', 0.46);
    }

    public function test_session_revision_is_untouched_by_atmosphere_changes(): void
    {
        $id = $this->openSession();
        $before = $this->actingAs($this->master)->getJson("/api/v1/sessions/{$id}/snapshot")->json('session.revision');
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", self::VALID)->assertOk();
        self::assertSame($before, $this->actingAs($this->master)->getJson("/api/v1/sessions/{$id}/snapshot")->json('session.revision'));
    }

    public function test_only_the_master_sets_the_atmosphere(): void
    {
        $id = $this->openSession();
        $this->actingAs($this->ana)->putJson("/api/v1/sessions/{$id}/atmosphere", self::VALID)->assertForbidden();
        $this->actingAs($this->makeUser('Fora'))->putJson("/api/v1/sessions/{$id}/atmosphere", self::VALID)->assertNotFound();
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'hour 24' => [['hour' => 24] + self::VALID];
        yield 'hour not a quarter' => [['hour' => 3.1] + self::VALID];
        yield 'negative hour' => [['hour' => -0.25] + self::VALID];
        yield 'unknown weather' => [['weather' => 'hail'] + self::VALID];
        yield 'intensity above 1' => [['intensity' => 1.5] + self::VALID];
        yield 'time scale above 120' => [['time_scale' => 121] + self::VALID];
        yield 'transition above 30' => [['transition_seconds' => 31] + self::VALID];
        yield 'missing field' => [array_diff_key(self::VALID, ['weather' => true])];
        yield 'fog above 1' => [self::VALID + ['fog' => 1.2]];
        yield 'extra key' => [self::VALID + ['sound' => 'thunder']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalid')]
    public function test_invalid_atmosphere_is_rejected(array $body): void
    {
        $id = $this->openSession();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", $body)->assertUnprocessable();
        $this->actingAs($this->master)->getJson("/api/v1/sessions/{$id}/snapshot")->assertJsonPath('session.atmosphere', null);
    }

    public function test_an_ended_session_cannot_change_atmosphere(): void
    {
        $id = $this->openSession();
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$id}/end")->assertOk();
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$id}/atmosphere", self::VALID)->assertConflict()->assertJsonPath('code', 'session.ended');
    }
}
