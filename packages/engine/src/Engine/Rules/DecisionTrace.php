<?php

declare(strict_types=1);

namespace Novtt\Engine\Rules;

/**
 * Explains how each derived value was obtained (ADR-001 §3 `decision_trace`): the base formula
 * result, every effect considered with its source and whether it applied, and the final value.
 * Callers filter entries by the viewer's read access before returning them.
 */
final class DecisionTrace
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    /** @param list<array<string, mixed>> $modifiers */
    public function record(string $derivedId, string $path, int|float|bool|string|null $base, array $modifiers, int|float|bool|string|null $value): void
    {
        $this->entries[$derivedId] = [
            'derived' => $derivedId,
            'path' => $path,
            'base' => $base,
            'modifiers' => $modifiers,
            'value' => $value,
        ];
    }

    /** @return list<array<string, mixed>> in computation order */
    public function entries(): array
    {
        return array_values($this->entries);
    }
}
