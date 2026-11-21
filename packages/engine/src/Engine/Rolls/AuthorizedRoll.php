<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use InvalidArgumentException;

final readonly class AuthorizedRoll
{
    public function __construct(
        public string $requestId,
        public int $chosenDiceCount,
        public int $expectedRevision,
        public string $idempotencyKey,
    ) {
        if ($requestId === '' || $chosenDiceCount < 1 || $expectedRevision < 0 || $idempotencyKey === '') {
            throw new InvalidArgumentException('Invalid roll command.');
        }
    }
}
