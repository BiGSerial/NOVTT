<?php
declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use DateTimeImmutable;
use Novtt\Engine\Contracts\Clock;
use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DicePoolRoller;
use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\OfficialRollOutcome;
use Novtt\Engine\Rolls\OfficialRollService;
use Novtt\Engine\Rolls\OfficialRollStore;
use Novtt\Engine\Rolls\RollAuthorization;
use Novtt\Engine\Rolls\RollPolicy;
use Novtt\Engine\Rolls\RollPolicyViolation;
use Novtt\Engine\Rolls\RollStatus;
use PHPUnit\Framework\TestCase;

final class OfficialRollServiceTest extends TestCase
{
    public function test_idempotency_attempts_and_preset_success_weight(): void
    {
        $random = new class implements RandomSource {
            public int $calls = 0;
            public function integer(int $min, int $max): int
            {
                $this->calls++;
                return 8;
            }
        };
        // In-memory fake models sequential transactions; it is NOT a concurrency test.
        $store = new class implements OfficialRollStore {
            public RollAuthorization $authorization;
            /** @var array<string, array{fingerprint:string,outcome:OfficialRollOutcome}> */
            public array $completed = [];
            public function __construct()
            {
                $this->authorization = new RollAuthorization('req', 1, 5, 2, 0, 0, true, RollStatus::OPEN, null, 10, 7, 2);
            }
            public function executeAtomically(string $actorId, AuthorizedRoll $command, string $payloadFingerprint, callable $execute): OfficialRollOutcome
            {
                $key = $actorId . ':' . $command->idempotencyKey;
                if (isset($this->completed[$key])) {
                    if ($this->completed[$key]['fingerprint'] !== $payloadFingerprint) {
                        throw new RollPolicyViolation('Idempotency conflict.');
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
                    $outcome->attemptsConsumed >= $this->authorization->attemptsAllowed ? RollStatus::EXHAUSTED : RollStatus::OPEN,
                    $this->authorization->expiresAt,
                    $this->authorization->faces,
                    $this->authorization->threshold,
                    $this->authorization->successesPerDie,
                );
                $this->completed[$key] = ['fingerprint' => $payloadFingerprint, 'outcome' => $outcome];
                return $outcome;
            }
        };
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-21T12:00:00+00:00'); }
        };
        $service = new OfficialRollService($store, new RollPolicy($clock), new DicePoolRoller($random));
        $first = $service->execute('actor', new AuthorizedRoll('req', 3, 0, 'key-1'));
        self::assertSame(6, $first->result->successes);
        self::assertSame(RollStatus::OPEN, $store->authorization->status);
        self::assertSame($first, $service->execute('actor', new AuthorizedRoll('req', 3, 0, 'key-1')));
        self::assertSame(3, $random->calls);

        try {
            $service->execute('actor', new AuthorizedRoll('req', 2, 0, 'key-1'));
            self::fail('Expected idempotency conflict.');
        } catch (RollPolicyViolation) {
            self::assertTrue(true);
        }

        $second = $service->execute('actor', new AuthorizedRoll('req', 2, 1, 'key-2'));
        self::assertSame(4, $second->result->successes);
        self::assertSame(RollStatus::EXHAUSTED, $store->authorization->status);
        self::assertSame(5, $random->calls);

        $this->expectException(RollPolicyViolation::class);
        $service->execute('actor', new AuthorizedRoll('req', 2, 2, 'key-3'));
    }
}
