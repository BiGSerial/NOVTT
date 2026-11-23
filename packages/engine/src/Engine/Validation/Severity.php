<?php

declare(strict_types=1);

namespace Novtt\Engine\Validation;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
