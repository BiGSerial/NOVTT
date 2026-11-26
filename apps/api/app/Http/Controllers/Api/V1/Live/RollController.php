<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\Idempotency;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\RollRequests;
use App\Live\RollViews;
use App\Live\SessionAccess;
use App\Models\OfficialRoll;
use App\Models\RollRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Novtt\Engine\Sheets\CanonicalJson;

/** Roll requests (master), official rolls (roller), NPC rolls and reveals (SPEC-MVP-002 LT-04). */
final class RollController extends ApiController
{
    public function __construct(private readonly RollRequests $rolls, private readonly SessionAccess $access) {}

    public function request(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'campaign_character_id' => ['required', 'uuid'],
            'suggestion_id' => ['sometimes', 'nullable', 'uuid'],
            ...self::diceRules(),
            'allows_choice' => ['sometimes', 'boolean'],
            'attempts' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'secret' => ['sometimes', 'boolean'],
            'expires_in_seconds' => ['sometimes', 'nullable', 'integer', 'min:30', 'max:3600'],
        ]);
        $created = $this->rolls->create($actor, $sessionId, $data, $data['suggestion_id'] ?? null);

        return new JsonResponse(RollViews::request($created, $this->rolls->subjectName($created)), 201);
    }

    public function roll(Request $request, string $requestId): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        if (! Str::isUuid($key)) {
            throw new ApiProblem(400, 'idempotency.key_invalid');
        }
        $data = $this->input($request, [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'dice_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.RollRequests::MAX_DICE],
        ]);
        if (! Str::isUuid($requestId)) {
            throw ApiProblem::notFound();
        }
        $roll = $this->rolls->execute($actor, $requestId, $data['dice_count'] ?? null, $data['expected_revision'], $key);
        $rollRequest = RollRequest::query()->findOrFail($roll->roll_request_id);

        return new JsonResponse(RollViews::roll($roll, $rollRequest, $this->rolls->subjectName($rollRequest)), 201);
    }

    public function npcRoll(Request $request, string $sessionId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'campaign_npc_id' => ['required', 'uuid'],
            ...self::diceRules(),
            'paths' => ['present', 'array', 'max:4'],
            'secret' => ['sometimes', 'boolean'],
        ]);
        [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'roll.npc', $key, hash('sha256', $sessionId.CanonicalJson::encode($data)), function () use ($actor, $sessionId, $data, $key): array {
            $roll = $this->rolls->rollForNpc($actor, $sessionId, $data, $key);
            $r = RollRequest::query()->findOrFail($roll->roll_request_id);

            return [201, RollViews::roll($roll, $r, $this->rolls->subjectName($r))];
        }));

        return new JsonResponse($body, $status);
    }

    public function reveal(Request $request, string $rollId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! Str::isUuid($rollId) || OfficialRoll::query()->whereKey($rollId)->doesntExist()) {
            throw ApiProblem::notFound();
        }
        $roll = $this->rolls->reveal($actor, $rollId);
        $r = RollRequest::query()->findOrFail($roll->roll_request_id);

        return new JsonResponse(RollViews::roll($roll, $r, $this->rolls->subjectName($r)));
    }

    public function cancel(Request $request, string $requestId): JsonResponse
    {
        return $this->masterChange($request, $requestId, fn ($actor) => $this->rolls->cancel($actor, $requestId));
    }

    public function grant(Request $request, string $requestId): JsonResponse
    {
        return $this->masterChange($request, $requestId, fn ($actor) => $this->rolls->grantAttempt($actor, $requestId));
    }

    private function masterChange(Request $request, string $requestId, callable $change): JsonResponse
    {
        if (! Str::isUuid($requestId)) {
            throw ApiProblem::notFound();
        }
        $r = $change($this->actor($request));

        return new JsonResponse(RollViews::request($r, $this->rolls->subjectName($r)));
    }

    /** @return array<string, list<string>> */
    private static function diceRules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'paths' => ['required', 'array', 'max:4'],
            'paths.*' => ['string', 'max:200'],
            'modifier' => ['sometimes', 'integer', 'min:-20', 'max:20'],
            'dice' => ['required', 'array'],
            'dice.faces' => ['required', 'integer', 'in:'.implode(',', RollRequests::FACES)],
            'dice.count_mode' => ['required', 'in:pool,fixed'],
            'dice.count' => ['required_if:dice.count_mode,fixed', 'nullable', 'integer', 'min:1', 'max:'.RollRequests::MAX_DICE],
            'dice.reading' => ['required', 'in:sum,highest,successes'],
            'dice.threshold' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'dice.successes_per_die' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'dice.target' => ['sometimes', 'nullable', 'integer', 'min:-50', 'max:500'],
        ];
    }
}
