<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

final class RollPolicy
{
    public function assertAllowed(RollAuthorization $authorization, AuthorizedRoll $command): void
    {
        if ($authorization->requestId !== $command->requestId) {
            throw new RollPolicyViolation('Roll request mismatch.');
        }
        if ($authorization->status !== 'OPEN') {
            throw new RollPolicyViolation('Roll request is not open.');
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
