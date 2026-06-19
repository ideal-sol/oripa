<?php

namespace App\Domain\Probability\Exceptions;

use RuntimeException;

class ProbabilityValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
