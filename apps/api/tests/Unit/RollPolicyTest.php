<?php
declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Novtt\Engine\Contracts\Clock;
use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\RollAuthorization;
use Novtt\Engine\Rolls\RollPolicy;
use Novtt\Engine\Rolls\RollPolicyViolation;
use Novtt\Engine\Rolls\RollStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RollPolicyTest extends TestCase
{
    private static function clock(string $now): Clock
    {
        return new class($now) implements Clock {
            public function __construct(private string $instant) {}
            public function now(): DateTimeImmutable { return new DateTimeImmutable($this->instant); }
        };
    }

    public function test_authorized_choice_and_rejections(): void
    {
        $policy = new RollPolicy(self::clock('2026-11-21T12:00:00+00:00'));
        $auth = new RollAuthorization('req', 1, 5, 2, 0, 3, true);
        $policy->assertAllowed($auth, new AuthorizedRoll('req', 3, 3, 'key'));

        foreach ([
            [new RollAuthorization('req', 1, 5, 2, 0, 3, true), new AuthorizedRoll('req', 6, 3, 'key')],
            [new RollAuthorization('req', 1, 5, 2, 0, 3, true), new AuthorizedRoll('req', 3, 2, 'key')],
            [new RollAuthorization('req', 1, 5, 2, 0, 3, true), new AuthorizedRoll('other', 3, 3, 'key')],
            [new RollAuthorization('req', 1, 5, 2, 2, 3, true), new AuthorizedRoll('req', 3, 3, 'key')],
            [new RollAuthorization('req', 1, 5, 2, 0, 3, false), new AuthorizedRoll('req', 3, 3, 'key')],
            [new RollAuthorization('req', 1, 5, 2, 0, 3, true, RollStatus::CANCELLED), new AuthorizedRoll('req', 3, 3, 'key')],
        ] as [$authorization, $command]) {
            try {
                $policy->assertAllowed($authorization, $command);
                self::fail('Expected policy rejection.');
            } catch (RollPolicyViolation) {
                self::assertTrue(true);
            }
        }
    }

    public function test_expiry_boundary_is_inclusive(): void
    {
        $policy = new RollPolicy(self::clock('2026-11-21T12:00:00+00:00'));
        $command = new AuthorizedRoll('req', 3, 0, 'key');
        $before = new RollAuthorization('req', 1, 5, 1, 0, 0, true, RollStatus::OPEN, new DateTimeImmutable('2026-11-21T12:00:01+00:00'));
        $policy->assertAllowed($before, $command);
        $at = new RollAuthorization('req', 1, 5, 1, 0, 0, true, RollStatus::OPEN, new DateTimeImmutable('2026-11-21T12:00:00+00:00'));
        $this->expectException(RollPolicyViolation::class);
        $policy->assertAllowed($at, $command);
    }

    #[DataProvider('invalidAuthorizations')]
    public function test_invalid_authorization_bounds(int $min, int $max, int $faces, int $threshold, int $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RollAuthorization('req', $min, $max, 1, 0, 0, true, RollStatus::OPEN, null, $faces, $threshold, $weight);
    }

    public static function invalidAuthorizations(): iterable
    {
        yield 'zero min' => [0, 5, 10, 6, 1];
        yield 'min above max' => [6, 5, 10, 6, 1];
        yield 'max above 100' => [1, 101, 10, 6, 1];
        yield 'faces too small' => [1, 5, 1, 1, 1];
        yield 'threshold too high' => [1, 5, 10, 11, 1];
        yield 'success weight too high' => [1, 5, 10, 6, 11];
    }
}
