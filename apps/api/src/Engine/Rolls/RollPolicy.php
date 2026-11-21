<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use Novtt\Engine\Contracts\Clock;

final readonly class RollPolicy
{
    public function __construct(private Clock $clock)
    {
    }

    public function assertAllowed(RollAuthorization $authorization, AuthorizedRoll $command): void
    {
        if ($authorization->requestId !== $command->requestId) {
            throw new RollPolicyViolation('Roll request mismatch.');
        }
        if ($authorization->status !== RollStatus::OPEN) {
            throw new RollPolicyViolation('Roll request is not open.');
        }
        if ($authorization->expiresAt !== null && $this->clock->now() >= $authorization->expiresAt) {
            throw new RollPolicyViolation('Roll request has expired.');
        }
        if ($authorization->revision !== $command->expectedRevision) {
            throw new RollPolicyViolation('Roll request revision mismatch.');
        }
        if ($authorization->attemptsConsumed >= $authorization->attemptsAllowed) {
            throw new RollPolicyViolation('No remaining attempts.');
        }
        if ($command->chosenDiceCount < $authorization->minDice || $command->chosenDiceCount > $authorization->maxDice) {
            throw new RollPolicyViolation('Chosen dice count outside authorized range.');
        }
        if (!$authorization->allowsPlayerChoice && $command->chosenDiceCount !== $authorization->maxDice) {
            throw new RollPolicyViolation('Preset requires a fixed dice pool.');
        }
    }
}
