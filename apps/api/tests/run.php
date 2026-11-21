<?php
declare(strict_types=1);

require __DIR__ . '/../src/Engine/Contracts/RandomSource.php';
require __DIR__ . '/../src/Engine/Dice/DicePool.php';
require __DIR__ . '/../src/Engine/Dice/RollResult.php';
require __DIR__ . '/../src/Engine/Dice/DicePoolRoller.php';

use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DicePool;
use Novtt\Engine\Dice\DicePoolRoller;

$random = new class implements RandomSource {
    private array $values = [3, 7, 10];
    public function integer(int $min, int $max): int
    {
        $value = array_shift($this->values);
        if ($value === null) {
            throw new RuntimeException('Unexpected random call.');
        }
        return $value;
    }
};

$result = (new DicePoolRoller($random))->roll(new DicePool(3, 10, 7));
if ($result->dice !== [3, 7, 10] || $result->successes !== 2) {
    throw new RuntimeException('Dice pool test failed.');
}

try {
    new DicePool(0, 10, 6);
    throw new RuntimeException('Invalid pool accepted.');
} catch (InvalidArgumentException) {
    // Expected.
}

echo "NOVTT dice kernel checks passed.\n";
