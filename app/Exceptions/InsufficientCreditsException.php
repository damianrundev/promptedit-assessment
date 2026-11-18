<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $requiredNano, public readonly int $balanceNano)
    {
        parent::__construct('Insufficient credits to cover the maximum estimated cost.');
    }
}
