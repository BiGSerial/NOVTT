<?php
declare(strict_types=1);

require __DIR__ . '/../src/Engine/Rolls/RollAuthorization.php';
require __DIR__ . '/../src/Engine/Rolls/AuthorizedRoll.php';
require __DIR__ . '/../src/Engine/Rolls/RollPolicyViolation.php';
require __DIR__ . '/../src/Engine/Rolls/RollPolicy.php';

use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\RollAuthorization;
use Novtt\Engine\Rolls\RollPolicy;
use Novtt\Engine\Rolls\RollPolicyViolation;

$policy = new RollPolicy();
$authorization = new RollAuthorization('request-1', 1, 5, 1, 0, 2, true);
$policy->assertAllowed($authorization, new AuthorizedRoll('request-1', 3, 2, 'key-1'));

$assertRejected = static function (RollAuthorization $auth, AuthorizedRoll $command) use ($policy): void {
    try {
        $policy->assertAllowed($auth, $command);
    } catch (RollPolicyViolation) {
        return;
    }
    throw new RuntimeException('Expected roll policy rejection.');
};

$assertRejected($authorization, new AuthorizedRoll('request-1', 6, 2, 'key-2'));
$assertRejected($authorization, new AuthorizedRoll('request-1', 3, 1, 'key-3'));
$assertRejected($authorization, new AuthorizedRoll('other-request', 3, 2, 'key-4'));
$assertRejected(new RollAuthorization('request-1', 1, 5, 1, 1, 2, true), new AuthorizedRoll('request-1', 3, 2, 'key-5'));
$assertRejected(new RollAuthorization('request-1', 1, 5, 1, 0, 2, false), new AuthorizedRoll('request-1', 3, 2, 'key-6'));
$assertRejected(new RollAuthorization('request-1', 1, 5, 1, 0, 2, true, 'CANCELLED'), new AuthorizedRoll('request-1', 3, 2, 'key-7'));

echo "NOVTT roll policy checks passed.\n";
