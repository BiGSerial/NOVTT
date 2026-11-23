<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Problems\ApiProblem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

abstract class ApiController
{
    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * Validates the JSON body strictly: unknown keys are rejected (contract additionalProperties: false).
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    protected function input(Request $request, array $rules): array
    {
        $data = $request->json()->all();
        $allowed = array_unique(array_map(static fn (string $k): string => explode('.', $k)[0], array_keys($rules)));
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ApiProblem::validation(new ValidationResult(array_map(
                static fn (string $k): ValidationIssue => new ValidationIssue('request.unknown_field', Severity::Error, [], ['/' . $k]),
                array_values($unknown),
            )), 'request.invalid');
        }

        return Validator::make($data, $rules)->validate();
    }

    protected function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '') {
            throw new ApiProblem(400, 'idempotency.key_required');
        }

        return $key;
    }

    /** Authorization failure is reported as 404 so private resources stay undiscoverable. */
    protected function authorizeOr404(Request $request, string $ability, mixed $subject): void
    {
        if (!Gate::forUser($this->actor($request))->allows($ability, $subject)) {
            throw ApiProblem::notFound();
        }
    }
}
