<?php

declare(strict_types=1);

namespace Novtt\Engine\Validation;

/**
 * Port for JSON Schema (Draft 2020-12) validation, implemented outside the engine (ADR 0010).
 * Implementations MUST NOT fetch remote schemas and MUST bound the number of reported issues.
 * Issues use rule ids of the form `schema.<keyword>` and report the failing data pointer in `paths`.
 */
interface StructuralValidator
{
    /**
     * @param array<string, mixed>|list<mixed>|scalar|null $document decoded JSON (assoc arrays)
     * @param array<string, mixed> $schema decoded JSON Schema
     */
    public function validate(mixed $document, array $schema): ValidationResult;

    /**
     * Validates against a versioned NOVTT contract, e.g. "novtt.sheet-ui/1".
     *
     * @param array<string, mixed>|list<mixed>|scalar|null $document
     */
    public function validateContract(mixed $document, string $contract): ValidationResult;
}
