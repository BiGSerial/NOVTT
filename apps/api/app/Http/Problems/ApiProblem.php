<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Novtt\Engine\Validation\ValidationResult;
use RuntimeException;

/**
 * Domain error rendered as RFC 9457 application/problem+json with a stable `code`.
 * `detail` is developer-facing; clients translate `code` (ADR 0007 direction).
 */
final class ApiProblem extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $detail = '',
        public readonly array $extra = [],
    ) {
        parent::__construct($detail === "" ? $problemCode : $detail);
    }

    public static function notFound(string $code = 'resource.not_found'): self
    {
        return new self(404, $code);
    }

    public static function conflict(string $code, ?int $currentRevision = null, string $detail = ''): self
    {
        return new self(409, $code, $detail, $currentRevision === null ? [] : ['current_revision' => $currentRevision]);
    }

    public static function validation(ValidationResult $result, string $code = 'validation.failed'): self
    {
        return new self(422, $code, '', ['validation' => $result->toArray()]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => 'https://novtt.invalid/problems/' . str_replace('.', '/', $this->problemCode),
            'title' => $this->problemCode,
            'status' => $this->status,
            'code' => $this->problemCode,
            'detail' => $this->getMessage(),
            ...$this->extra,
        ];
    }
}
