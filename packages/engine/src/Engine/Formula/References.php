<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/** References found in a formula (keys are the referenced names; values are always true). */
final class References
{
    /** @var array<string, true> */
    public array $pointers = [];
    /** @var array<string, true> */
    public array $derived = [];
    /** @var array<string, true> */
    public array $budgets = [];
    /** @var array<string, true> */
    public array $variables = [];
    /** @var array<string, true> */
    public array $collections = [];
    /** @var array<string, true> */
    public array $itemFields = [];
}
