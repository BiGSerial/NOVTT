<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Renders every /api/* error as RFC 9457 problem+json with a stable code and no stack traces. */
final class ProblemRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(static function (\Throwable $e, Request $request): ?JsonResponse {
            if (!$request->is('api/*')) {
                return null;
            }
            $problem = self::toProblem($e);

            return $problem === null ? null : self::respond($problem);
        });
    }

    public static function respond(ApiProblem $problem): JsonResponse
    {
        return new JsonResponse($problem->toArray(), $problem->status, ['Content-Type' => 'application/problem+json']);
    }

    private static function toProblem(\Throwable $e): ?ApiProblem
    {
        return match (true) {
            $e instanceof ApiProblem => $e,
            $e instanceof AuthenticationException => new ApiProblem(401, 'auth.unauthenticated'),
            // Never reveal that a resource exists to someone who may not see it.
            $e instanceof AuthorizationException, $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiProblem::notFound(),
            $e instanceof ThrottleRequestsException => new ApiProblem(429, 'rate_limit.exceeded'),
            $e instanceof ValidationException => ApiProblem::validation(self::fromLaravel($e), 'request.invalid'),
            $e instanceof HttpExceptionInterface => new ApiProblem($e->getStatusCode(), 'http.' . $e->getStatusCode()),
            default => null,
        };
    }

    private static function fromLaravel(ValidationException $e): ValidationResult
    {
        $issues = [];
        foreach ($e->validator->failed() as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $issues[] = new ValidationIssue('request.' . strtolower((string) $rule), paths: ['/' . str_replace('.', '/', (string) $field)]);
            }
        }

        return new ValidationResult($issues);
    }
}
