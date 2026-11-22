<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\CampaignRole;
use App\Domain\ChangeRequestStatus;
use App\Domain\MemberStatus;
use App\Domain\PresetVersionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class AuthorizationMatrixTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private function can(User $user, string $ability, mixed $subject): bool
    {
        return Gate::forUser($user)->allows($ability, $subject);
    }

    public function test_campaign_access_is_default_deny_and_master_only_for_management(): void
    {
        [$master, $player, $outsider] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $membership = $this->addMember($campaign, $player);

        $this->assertTrue($this->can($master, 'view', $campaign));
        $this->assertTrue($this->can($player, 'view', $campaign));
        $this->assertFalse($this->can($outsider, 'view', $campaign));

        $this->assertTrue($this->can($master, 'manage', $campaign));
        $this->assertFalse($this->can($player, 'manage', $campaign));
        $this->assertFalse($this->can($outsider, 'manage', $campaign));

        $membership->update(['status' => MemberStatus::Removed, 'removed_at' => now()]);
        $this->assertFalse($this->can($player, 'view', $campaign), 'removed members lose access');

        $campaign->update(['status' => 'archived', 'archived_at' => now()]);
        $this->assertTrue($this->can($master, 'view', $campaign), 'archived campaigns stay readable');
        $this->assertFalse($this->can($master, 'manage', $campaign), 'archived campaigns are frozen');
    }

    public function test_characters_are_visible_to_masters_and_their_player_only(): void
    {
        [$master, $alice, $bob, $outsider] = [$this->makeUser(), $this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $this->addMember($campaign, $bob);
        $character = $this->makeCharacter($campaign, $alice);

        $this->assertTrue($this->can($master, 'view', $character));
        $this->assertTrue($this->can($alice, 'view', $character));
        $this->assertFalse($this->can($bob, 'view', $character));
        $this->assertFalse($this->can($outsider, 'view', $character));

        $this->assertTrue($this->can($alice, 'propose', $character));
        $this->assertFalse($this->can($bob, 'propose', $character));
        $this->assertFalse($this->can($master, 'propose', $character), 'master proposes only under custody');
        $this->assertTrue($this->can($master, 'master', $character));
        $this->assertFalse($this->can($alice, 'master', $character), 'players cannot approve their own sheets');
    }

    public function test_custody_moves_proposal_rights_to_the_master(): void
    {
        [$master, $alice] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $character = $this->makeCharacter($campaign, $alice);

        $character->update(['custodian_user_id' => $master->getKey()]);

        $this->assertTrue($this->can($master, 'propose', $character));
        $this->assertFalse($this->can($alice, 'propose', $character));
        $this->assertTrue($this->can($alice, 'view', $character));
    }

    public function test_change_request_review_is_master_only_and_authors_see_their_own(): void
    {
        [$master, $alice, $bob, $coMaster] = [$this->makeUser(), $this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $this->addMember($campaign, $bob);
        $this->addMember($campaign, $coMaster, CampaignRole::Master);
        $request = $this->makeChangeRequest($this->makeCharacter($campaign, $alice), $alice, ChangeRequestStatus::Submitted);

        $this->assertTrue($this->can($alice, 'view', $request));
        $this->assertTrue($this->can($alice, 'author', $request));
        $this->assertFalse($this->can($alice, 'review', $request));
        $this->assertFalse($this->can($bob, 'view', $request));
        $this->assertTrue($this->can($coMaster, 'review', $request));
        $this->assertTrue($this->can($master, 'review', $request));
    }

    public function test_preset_visibility_and_pinning(): void
    {
        [$team, $alice, $bob] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $canonical = $this->makePresetVersion($team, PresetVersionStatus::Published);
        $canonicalDraft = $this->makePresetVersion($team, PresetVersionStatus::Reviewed);
        $personal = $this->makePresetVersion($alice, PresetVersionStatus::EngineCompatible, $alice);
        $personalDraft = $this->makePresetVersion($alice, PresetVersionStatus::StructurallyValid, $alice);

        $this->assertTrue($this->can($bob, 'view', $canonical));
        $this->assertFalse($this->can($bob, 'view', $canonicalDraft), 'unpublished canonical versions are not public');
        $this->assertTrue($this->can($bob, 'pin', $canonical));
        $this->assertFalse($this->can($bob, 'pin', $canonicalDraft));

        $this->assertTrue($this->can($alice, 'view', $personal));
        $this->assertFalse($this->can($bob, 'view', $personal), 'personal imports are private');
        $this->assertTrue($this->can($alice, 'pin', $personal));
        $this->assertFalse($this->can($alice, 'pin', $personalDraft), 'only engine-compatible versions can be pinned');
        $this->assertFalse($this->can($bob, 'pin', $personal));

        // Joining a campaign pinned to a personal version grants read access to that version only.
        $campaign = $this->makeCampaign($alice, $personal);
        $this->addMember($campaign, $bob);
        $this->assertTrue($this->can($bob, 'view', $personal));
        $this->assertFalse($this->can($bob, 'pin', $personal));
        $this->assertFalse($this->can($bob, 'view', $personalDraft));
    }
}
