<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

/**
 * Result of a resolution strategy. `faces` lists every die rolled in order (groups in order,
 * explosions appended); `kept` marks which of them count. `success` is null when no target or
 * requirement applies. `trace` explains the reading for the calculation inspector.
 */
final readonly class RollOutcome
{
    /**
     * @param list<int> $faces
     * @param list<bool> $kept
     * @param list<string> $flags e.g. critical, fumble, botch
     * @param array<string, mixed> $trace
     */
    public function __construct(
        public string $strategy,
        public array $faces,
        public array $kept,
        public int $total,
        public ?bool $success,
        public array $flags = [],
        public array $trace = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'faces' => $this->faces,
            'kept' => $this->kept,
            'total' => $this->total,
            'success' => $this->success,
            'flags' => $this->flags,
            'trace' => (object) $this->trace,
        ];
    }
}
