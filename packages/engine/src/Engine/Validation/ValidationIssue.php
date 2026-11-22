<?php

declare(strict_types=1);

namespace Novtt\Engine\Validation;

/**
 * One finding of novtt.validation-result/1. `rule_id` is a stable code (no prose, ADR 0007
 * direction). Callers MUST filter issues before returning them to a viewer who cannot read
 * the referenced paths, so hidden data never leaks through errors.
 */
final readonly class ValidationIssue
{
    /**
     * @param list<string> $operationIds
     * @param list<string> $paths
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public string $ruleId,
        public Severity $severity = Severity::Error,
        public array $operationIds = [],
        public array $paths = [],
        public array $params = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'severity' => $this->severity->value,
            'operation_ids' => $this->operationIds,
            'paths' => $this->paths,
            'params' => (object) $this->params,
        ];
    }
}
