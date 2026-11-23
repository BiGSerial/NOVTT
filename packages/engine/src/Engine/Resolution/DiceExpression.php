<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;

/**
 * Parses a bounded dice expression into a SumRoll. Grammar (whitespace ignored, case-insensitive):
 *   expr  := term (("+" | "-") term)*
 *   term  := dice | integer
 *   dice  := [count] "d" faces [("kh" | "kl") keep]
 * Examples: "1d20+5", "2d6+1d4-1", "4d6kh3", "2d20kl1". No functions, variables or nesting.
 */
final class DiceExpression
{
    public const MAX_LENGTH = 64;

    public static function parse(string $expression, ?int $target = null, ?int $criticalAtLeast = null, ?int $fumbleAtMost = null): SumRoll
    {
        $source = strtolower(preg_replace('/\s+/', '', $expression) ?? '');
        if ($source === '' || strlen($source) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('roll.expression_invalid');
        }
        if (preg_match('/^[+-]?(?:\d*d\d+(?:k[hl]\d+)?|\d+)(?:[+-](?:\d*d\d+(?:k[hl]\d+)?|\d+))*$/', $source) !== 1) {
            throw new InvalidArgumentException('roll.expression_invalid');
        }
        preg_match_all('/([+-]?)(?:(\d*)d(\d+)(?:k([hl])(\d+))?|(\d+))/', $source, $terms, PREG_SET_ORDER);
        $groups = [];
        $modifier = 0;
        foreach ($terms as $term) {
            $sign = $term[1] === '-' ? -1 : 1;
            if (isset($term[6]) && $term[6] !== '') {
                $modifier += $sign * (int) $term[6];
                continue;
            }
            $count = $term[2] === '' ? 1 : (int) $term[2];
            $keepMode = ($term[4] ?? '') === '' ? null : ($term[4] === 'h' ? 'highest' : 'lowest');
            $groups[] = new DiceGroup($count, (int) $term[3], $sign, $keepMode, $keepMode === null ? null : (int) $term[5]);
        }
        if ($groups === []) {
            throw new InvalidArgumentException('roll.expression_needs_dice');
        }

        return new SumRoll($groups, $modifier, $target, $criticalAtLeast, $fumbleAtMost);
    }
}
