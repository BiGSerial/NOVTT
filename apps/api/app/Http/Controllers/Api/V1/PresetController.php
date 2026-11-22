<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Idempotency;
use App\Application\PresetImporter;
use App\Domain\PresetVersionStatus;
use App\Http\Problems\ApiProblem;
use App\Http\Resources\PresetVersionPresenter;
use App\Models\PresetVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

final class PresetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $scope = (string) $request->query('scope', 'all');
        if (!in_array($scope, ['all', 'canonical', 'personal'], true)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('request.in', paths: ['/scope'])]), 'request.invalid');
        }
        $limit = max(1, min(100, (int) $request->query('limit', 25)));

        $page = PresetVersion::query()
            ->with('definition')
            ->whereHas('definition', function (Builder $definition) use ($scope, $actor): void {
                $definition->where(function (Builder $q) use ($scope, $actor): void {
                    if ($scope !== 'personal') {
                        $q->orWhereNull('owner_user_id');
                    }
                    if ($scope !== 'canonical') {
                        $q->orWhere('owner_user_id', $actor->getKey());
                    }
                });
            })
            ->where(function (Builder $q): void {
                // Canonical entries must be published; personal entries are listed in any status.
                $q->where('status', PresetVersionStatus::Published)
                    ->orWhereHas('definition', fn (Builder $d) => $d->whereNotNull('owner_user_id'));
            })
            ->orderBy('id')
            ->cursorPaginate($limit);

        return new JsonResponse([
            'data' => array_map(PresetVersionPresenter::summary(...), $page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, string $presetVersionId): JsonResponse
    {
        $version = PresetVersion::query()->with('definition')->find($presetVersionId) ?? throw ApiProblem::notFound();
        $this->authorizeOr404($request, 'view', $version);

        return new JsonResponse(PresetVersionPresenter::full($version));
    }

    public function import(Request $request, PresetImporter $importer, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $raw = (string) $request->getContent();
        [$package, $objects] = $importer->parse($raw);

        [$status, $body] = DB::transaction(fn (): array => $idempotency->run(
            $actor,
            'preset.import',
            $key,
            hash('sha256', $raw),
            function () use ($importer, $actor, $package, $objects): array {
                [$version, $created] = $importer->import($actor, $package, $objects);

                return [$created ? 201 : 200, PresetVersionPresenter::full($version->load('definition'))];
            },
        ));

        return new JsonResponse($body, $status);
    }
}
