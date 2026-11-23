<?php

declare(strict_types=1);

namespace Novtt\Engine\Validation;

/** novtt.validation-result/1 */
final readonly class ValidationResult
{
    public const CONTRACT = 'novtt.validation-result/1';

    /** @param list<ValidationIssue> $issues */
    public function __construct(public array $issues = [])
    {
    }

    public static function ok(): self
    {
        return new self();
    }

    public function isValid(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === Severity::Error) {
                return false;
            }
        }

        return true;
    }

    public function merge(self $other): self
    {
        return new self([...$this->issues, ...$other->issues]);
    }

    /** @param callable(ValidationIssue): bool $keep */
    public function filter(callable $keep): self
    {
        return new self(array_values(array_filter($this->issues, $keep)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::CONTRACT,
            'valid' => $this->isValid(),
            'issues' => array_map(static fn (ValidationIssue $i): array => $i->toArray(), $this->issues),
        ];
    }
}
