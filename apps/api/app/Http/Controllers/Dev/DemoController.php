<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Demo\DemoMode;
use App\Demo\TableDemoSeeder;
use App\Domain\ChangeRequestStatus;
use App\Http\Problems\ApiProblem;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\ChangeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Local demonstration session (EnsureDemoMode guards every action). Signs the browser in as the
 * seeded master or player through the normal first-party session (ADR 0009); everything after
 * that goes through the real /api/v1 routes, policies and workflow.
 */
final class DemoController
{
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->state($request->user()));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $role = $data['role'] ?? null;
        if (array_diff(array_keys($data), ['role']) !== [] || !in_array($role, ['master', 'player', 'player2', 'player3'], true)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('demo.invalid_role', Severity::Error, [], ['/role'])]), 'request.invalid');
        }
        if (!$request->hasSession()) {
            // Only first-party origins (SANCTUM_STATEFUL_DOMAINS) get a session cookie.
            throw new ApiProblem(400, 'demo.stateful_origin_required');
        }
        $user = User::query()->where('email', $role === 'master' ? DemoMode::MASTER_EMAIL : TableDemoSeeder::PLAYERS[$role][0])->first();
        if ($user === null) {
            throw new ApiProblem(409, 'demo.not_seeded');
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new JsonResponse($this->state($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if (!$request->hasSession()) {
            return new JsonResponse(null, 204);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function state(?User $user): array
    {
        $master = User::query()->where('email', DemoMode::MASTER_EMAIL)->first();
        $campaign = $master === null ? null : Campaign::query()->where('owner_user_id', $master->getKey())->where('name', DemoMode::CAMPAIGN_NAME)->first();
        $character = $campaign === null ? null : CampaignCharacter::query()->where('campaign_id', $campaign->getKey())->orderBy('id')->first();
        $active = $character === null ? null : ChangeRequest::query()
            ->where('campaign_character_id', $character->getKey())
            ->whereIn('status', ChangeRequestStatus::ACTIVE)
            ->value('id');
        $role = $user?->email === DemoMode::MASTER_EMAIL ? 'master' : null;
        foreach (TableDemoSeeder::PLAYERS as $key => [$email]) {
            $role = $user?->email === $email ? $key : $role;
        }
        $table = $master === null ? null : Campaign::query()->where('owner_user_id', $master->getKey())->where('name', TableDemoSeeder::CAMPAIGN_NAME)->value('id');

        return [
            'seeded' => $campaign !== null && $character !== null,
            'user' => $user === null ? null : ['id' => $user->getKey(), 'name' => $user->name, 'role' => $role],
            'campaign_id' => $campaign?->getKey(),
            'campaign_character_id' => $character?->getKey(),
            'active_change_request_id' => $active,
            'table_campaign_id' => $table,
        ];
    }
}
