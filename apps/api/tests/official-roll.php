<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Engine/Contracts/RandomSource.php';
require_once __DIR__ . '/../src/Engine/Dice/DicePool.php';
require_once __DIR__ . '/../src/Engine/Dice/RollResult.php';
require_once __DIR__ . '/../src/Engine/Dice/DicePoolRoller.php';
require_once __DIR__ . '/../src/Engine/Rolls/RollAuthorization.php';
require_once __DIR__ . '/../src/Engine/Rolls/AuthorizedRoll.php';
require_once __DIR__ . '/../src/Engine/Rolls/RollPolicyViolation.php';
require_once __DIR__ . '/../src/Engine/Rolls/RollPolicy.php';
require_once __DIR__ . '/../src/Engine/Rolls/OfficialRollOutcome.php';
require_once __DIR__ . '/../src/Engine/Rolls/OfficialRollStore.php';
require_once __DIR__ . '/../src/Engine/Rolls/OfficialRollService.php';

use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DicePoolRoller;
use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\OfficialRollOutcome;
use Novtt\Engine\Rolls\OfficialRollService;
use Novtt\Engine\Rolls\OfficialRollStore;
use Novtt\Engine\Rolls\RollAuthorization;
use Novtt\Engine\Rolls\RollPolicy;
use Novtt\Engine\Rolls\RollPolicyViolation;

$source = new class implements RandomSource {
    public int $calls = 0;
    public function integer(int $min, int $max): int
    {
        $this->calls++;
        return 8;
    }
};

$store = new class implements OfficialRollStore {
    public RollAuthorization $authorization;
    /** @var array<string, array{fingerprint:string, outcome:OfficialRollOutcome}> */
    public array $completed = [];

    public function __construct()
    {
        $this->authorization = new RollAuthorization('request-1', 1, 5, 1, 0, 0, true);
    }

    public function executeAtomically(
        string $actorId,
        AuthorizedRoll $command,
        string $payloadFingerprint,
        callable $execute,
    ): OfficialRollOutcome {
        $key = $actorId . ':' . $command->idempotencyKey;
        if (isset($this->completed[$key])) {
            if ($this->completed[$key]['fingerprint'] !== $payloadFingerprint) {
                throw new RollPolicyViolation('Idempotency key reused with different payload.');
            }
            return $this->completed[$key]['outcome'];
        }

        $outcome = $execute($this->authorization);
        $this->authorization = new RollAuthorization(
            $this->authorization->requestId,
            $this->authorization->minDice,
            $this->authorization->maxDice,
            $this->authorization->attemptsAllowed,
            $outcome->attemptsConsumed,
            $outcome->revision,
            $this->authorization->allowsPlayerChoice,
            'EXHAUSTED',
        );
        $this->completed[$key] = ['fingerprint' => $payloadFingerprint, 'outcome' => $outcome];
        return $outcome;
    }
};

$service = new OfficialRollService($store, new RollPolicy(), new DicePoolRoller($source));
$command = new AuthorizedRoll('request-1', 3, 0, 'key-1');
$first = $service->execute('player-1', $command, 10, 7);
$again = $service->execute('player-1', $command, 10, 7);
if ($first !== $again || $first->result->successes !== 3 || $source->calls !== 3) {
    throw new RuntimeException('Idempotent roll failed.');
}
try {
    $service->execute('player-1', new AuthorizedRoll('request-1', 2, 0, 'key-1'), 10, 7);
    throw new RuntimeException('Idempotency conflict accepted.');
} catch (RollPolicyViolation) {
}
try {
    $service->execute('player-1', new AuthorizedRoll('request-1', 3, 1, 'key-2'), 10, 7);
    throw new RuntimeException('Attempt budget exceeded.');
} catch (RollPolicyViolation) {
}
if ($source->calls !== 3) {
    throw new RuntimeException('Rejected roll consumed random values.');
}
echo "NOVTT official roll orchestration checks passed.\n";
